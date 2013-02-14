<?php

namespace Sky\Model;

/*

TODO

- ability to save a child object directly (i.e. depth is relative to where save is initiated)

- readOnlyProperties (fields and nested objects)

- when setting $this->foreign_key->id, also need to set $this->foreign_key_id

- when saving a model, refresh other models with the same primary table?

- reorder the object so the id, ide, primary_id, primary_ide are first

- throw exceptions (and ability to not throw, \Sky\Model::$throwExceptions = false;)

- detect changes in the aql so you don't need schemaModificationTime

- ide's should not appear in _modified

- automatic basic validation
    - check for max length
    - check for correct datatype

- cross-namespace lazy objects
    person {
        [\Cms\Model\blog_article]s
    }

- issue with cache conflicts with same model name in different namespaces

- make sure aql::select doesn't return models (the wrong kind of models)

*/

/**
 *
 */
class AQLModel extends PHPModel
{

    /**
     * @var array
     */
    protected static $_meta = array(
        'schemaModificationTime' => null,
        'cachedLists' => array(),
        'possibleErrors' => array(),
        'requiredFields' => array(),
        'readOnlyProperties' => array(),
        'readOnlyTables' => array(),
        'testMode' => false
    );

    /**
     * save this single object to the database
     */
    public function saveDataToDatabase()
    {
        // see github.com/SkyPHP/skyphp/issues/139

        elapsed(static::meta('class') . '->saveDataToDatabase()');

        // if nothing to save
        if (!$this->_modified) {
            return false;
        }

        $aql_array = static::meta('aql_array');

        //d($aql_array);

        $saveStack = array();
        foreach ($aql_array as $block) {
            $table = $block['table'];
            // if the table is not yet in the saveStack, put the table along with its
            // dependencies into the saveStack
            if (array_search($table, $saveStack) === false) {
                $tempStack = static::getSaveStack($table);
                foreach ($tempStack as $table) {
                    if (array_search($table, $saveStack) === false) {
                        // quick fix for a weird bug where the value true would end up
                        // in the saveStack intermittently
                        if (strlen($table) > 0) {
                            $saveStack[] = $table;
                        }
                    }
                }
            }
        }

        //d($saveStack);
        //d($this->_modified);

        // organize the modified data fields by table
        $data = array();
        foreach ($aql_array as $block) {
            foreach ($block['fields'] as $alias => $field) {
                if (property_exists($this->_modified, $alias)) {
                    $field = substr($field, strpos($field, '.') + 1);
                    $data[$block['table']][$field] = $this->_data->$alias;
                }
            }
        }

        // check to see if there are any data fields to be saved for each table
        // if so, insert or update
        foreach ($saveStack as $table) {
            if ($data[$table]) {
                // there is something to save in this table
                // set the foreign key values that were just inserted
                if (is_array($aql_array[$table]['fk'])) {
                    foreach ($aql_array[$table]['fk'] as $fk) {
                        $field = $fk . '_id';
                        if ($foreign_keys[$fk]) {
                            $data[$table][$field] = $foreign_keys[$fk];
                        }
                    }
                }
                // now update or insert the record
                $id_field = $table . '_id';
                $id = $this->$id_field;
                if (is_numeric($id)) {
                    //d($table, $data[$table], $id);
                    $r = \aql::update($table, $data[$table], $id);
                    if (!$r) {
                        $e = array_pop(\aql::$errors);
                        $this->addInternalError('database_error', array(
                            'message' => $e->getMessage(),
                            'trace' => $e->getTrace(),
                            'db_error' => $e->db_error,
                            'fields' => $e->fields
                        ));
                    }
                } else {
                    $r = \aql::insert($table, $data[$table]);
                    if (!$r) {
                        $e = array_pop(\aql::$errors);
                        $this->addInternalError('database_error', array(
                            'message' => $e->getMessage(),
                            'trace' => $e->getTrace(),
                            'db_error' => $e->db_error,
                            'fields' => $e->fields
                        ));
                        return;
                    }
                    $id = $r[0]['id'];
                    $this->_data->$id_field = $id;
                    $this->afterSetValue($id_field, $id);
                    $foreign_keys[$table] = $id;
                }
            }
        }

        //elapsed(static::meta('class') . '->saveDataToDatabase() DONE');
    }


    /**
     *
     */
    public static function getSaveStack($table)
    {
        $aql_array = static::meta('aql_array');
        // TODO: is the table block always the same as the table name?
        $dependencies = $aql_array[$table]['fk'];
        $saveStack = array();
        if (count($dependencies)) {
            // if dependencies, recursively get dependencies
            // then add this table to the saveStack
            foreach ($dependencies as $dependency) {
                $tempStack = static::getSaveStack($dependency);
                $saveStack = array_merge($saveStack, $tempStack);
            }
        }
        $saveStack[] = $table;
        return $saveStack;
    }

    /**
     *
     */
    public function getAQL()
    {
        return static::AQL;
    }

    /**
     *
     */
    final public function __construct($data = null, $params = null)
    {
        // we will use this to remember which objects have been loaded from db
        // so we don't refresh the same thing repetitively
        static $memoize;

        // make sure we have all metadata
        static::getMetadata();

        // set the depth -- the level of nesting from the main object
        $this->_depth = $params['depth'] ?: 0;

        // link the parent object
        if ($params['parent']) {
            $this->_parent = $params['parent'];
        }

        // if we are pre-populating a new object with new data
        if (is_array($data) || is_object($data) || $data == null) {
            // instantiate a new object with the given data
            $this->set($data);
            if ($this->_errors) {
                //throw new \ValidationException($this->_errors);
            } else {
                elapsed('call construct');
                $this->callMethod('construct');
            }
            return;
        }

        // get the object by id
        $id = $data;

        $primary_table = static::getPrimaryTable();

        // if the id is not numeric, decrypt it
        if (!is_numeric($id)) {
            $ide = $id;
            $id = \decrypt($ide, $primary_table);
        }
        if (!is_numeric($id)) {
            // TODO: this needs to be addError()
            $this->_errors[] = "'$ide' cannot be decrypted to a valid ID.";
            return;
        }

        // determine if this object has already been loaded from db on this page load
        $class = static::meta('class');

        if ($memoize[$class][$id] || (!$params['refresh'] && !$_GET['refresh'])) {
            $data = static::getDataFromCache($id);
            if ($data) {
                $this->_data = $data;
                $this->getNestedObjects();
                return;
            }
        }

        // data is not in cache, get from database and then construct
        $this->getDataFromDatabase($id, $params);

        // memoize the fact that this object has been refreshed already in this thread
        $memoize[$class][$id] = true;

    }

    /**
     *
     */
    public function delete()
    {
        $this->active = 0;
        return $this->save();
    }

    /**
     * Set multiple properties
     */
    public function set($data)
    {
        if (is_object($data)) {
            $data = static::object_to_array($data);
        }

        if (is_array($data)) {

            // first check to see if an id is set, if so, fully load the object
            // then after original object is loaded set the modified properties
            $primary_table = static::getPrimaryTable();
            $primary_id = $primary_table . '_id';
            $primary_ide = $primary_table . '_ide';

            // determine if we have an id
            $id = null;
            $id = $data['ide'] ?: $id;
            $id = $data[$primary_ide] ?: $id;
            $id = $data['id'] ?: $id;
            $id = $data[$primary_id] ?: $id;

            // load the existing data
            if ($id) {
                $class = get_class($this);
                $temp = new $class($id);
                $this->_data = $temp->_data;
            } else {
                $this->_data->id = static::FOREIGN_KEY_VALUE_TBD;
            }

            foreach ($data as $property => $value) {
                // if the property is a lazyObject
                $lazy = static::$_meta['lazyObjects'][$property];
                if ($lazy) {
                    //d($lazy);
                    $model = $lazy['model'];
                    $nested_class = static::getNamespacedModelName($model);
                    if ($lazy['plural']) {
                        $values = $value;
                        $value = array();
                        // just in case trying to add a 1-to-m without using array
                        if (!is_array($values)) {
                            $values = array($values);
                        }
                        foreach ($values as $val) {
                            // add this id as a foreign key in the 1-to-m nested object
                            $key = static::getOneToManyKey();
                            $val[$key] = $this->id;
                            // nest the object
                            $obj = new $nested_class($val, array(
                                'depth' => $this->_depth + 1,
                                'parent' => $this
                            ));
                            $value[] = $obj;
                        }
                    } else {
                        $value = new $nested_class($value, array(
                            'depth' => $this->_depth + 1,
                            'parent' => $this
                        ));
                        $foreign_key = static::getForeignKey($property);
                        $this->$foreign_key = $value->getID();
                    }
                    $this->_data->$property = $value;
                } else {
                    $this->setValue($property, $value);
                }
            }
        }

        // run full validation
        $this->runValidation();

        //get all errors from nested objects
        $this->getChildErrors();

        return $this;
    }

    /**
     * make sure the related fields are set based on this field
     * for example apple_id --> apple_ide, id, ide
     *             banana_ide -> banana_id
     */
    public function afterSetValue($property, $value)
    {
        $primary_table = static::getPrimaryTable();
        $primary_id = $primary_table . '_id';
        $primary_ide = $primary_table . '_ide';

        if ($property == 'id') {
            $id = $value;
            if (is_numeric($id)) {
                $ide = \encrypt($value, $primary_table);
            }
            #$this->_data->id = $id;
            $this->_data->ide = $ide;
            $this->_data->$primary_id = $id;
            $this->_data->$primary_ide = $ide;

        } else if ($property == 'ide') {
            $id = \decrypt($value, $primary_table);
            $ide = $value;
            $this->_data->id = $id;
            #$this->_data->ide = $ide;
            $this->_data->$primary_id = $id;
            $this->_data->$primary_ide = $ide;

        } else if (substr($property, -3) == '_id') {
            $table = substr($property, 0, -3);
            if (strpos($table, '__')) {
                $alias = substr($table, 0, strpos($table, '__') + 2);
            }
            $table = str_replace($alias, '', $table);
            $field_id = $alias . $table . '_id';
            $field_ide = $alias . $table . '_ide';
            $id = $value;
            if (is_numeric($id)) {
                $ide = \encrypt($id, $table);
            }
            #$this->_data->$field_id = $id;
            $this->_data->$field_ide = $ide;
            if ($table == $primary_table && !$alias) {
                $this->_data->id = $id;
                $this->_data->ide = $ide;
            }

        } else if (substr($property, -4) == '_ide') {
            $table = substr($property, 0, -4);
            if (strpos($table, '__')) {
                $alias = substr($table, 0, strpos($table, '__') + 2);
            }
            $table = str_replace($alias, '', $table);
            $field_id = $alias . $table . '_id';
            #$field_ide = $alias . $table . '_ide';
            $id = \decrypt($value, $table);
            $ide = $value;
            $this->_modified->$field_id = $this->_data->$field_id;
            $this->_data->$field_id = $id;
            #$this->_data->$field_ide = $ide;
            if ($table == $primary_table && !$alias) {
                $this->_data->id = $id;
                $this->_data->ide = $ide;
            }
        }

        // instantiate the 1-to-1 nested object if applicable
        if ($field_id != $primary_id) {
            $lazyObjects = static::meta('lazyObjects');
            if (is_array($lazyObjects)) {
                foreach ($lazyObjects as $property => $info) {
                    if ($info['constructor argument'] == $field_id) {
                        $model = $info['model'];
                        $ns_model = static::getNamespacedModelName($model);
                        $this->_data->$property = new $ns_model($id);
                        break;
                    }
                }
            }
        }

        return $this;
    }

    /**
     *
     */
    public function refreshCachedLists()
    {
        // refresh any cached lists this object may have (if the appropriate field
        // has been modified)
        $cachedLists = static::meta('cachedLists');
        if (is_array($cachedLists)) {
            foreach ($cachedLists as $property) {
                if (property_exists($this->_modified, $property)) {
                    // a cachedList field has been modified, we need to requery the list
                    // using the prior field value and the new field value
                    $values = array(
                        $this->_modified->$property,
                        $this->_data->$property
                    );
                    foreach ($values as $value) {
                        // don't try to update a non-existent cached list
                        if ($value == static::FOREIGN_KEY_VALUE_TBD) {
                            continue;
                        }
                        if (!$value && $value !== 0) {
                            continue;
                        }
                        // get dbfield given the property
                        $aql_array = static::meta('aql_array');
                        $dbfield = null;
                        foreach ($aql_array as $block) {
                            foreach ($block['fields'] as $alias => $field) {
                                if ($alias == $property) {
                                    $dbfield = $field;
                                    break;
                                }
                            }
                            if ($dbfield) {
                                break;
                            }
                        }
                        if ($dbfield) {
                            $where = "{$dbfield} = {$value}";
                            $cachedListKey = "list:" . str_replace(' ', '', $where);
                            $list = static::getList(array(
                                'where' => $where
                            ));
                            mem($cachedListKey, $list);
                        }
                    }
                }
            }
        }
    }

    /**
     *
     */
    public function getDataFromDatabase($id = null, $params = null)
    {
        if (!$id) {
            $id = $this->getID();
        }

        // reset state
        unset($this->_errors);
        unset($this->_modified);
        unset($this->_data);

        elapsed(static::meta('class') . "::getDataFromDatabase($id)");
        // use the aql to query the data since we have added implicit fields
        // that may not be in the aql statement
        $aql_array = static::meta('aql_array');
        $primary_table = static::meta('primary_table');
        $read_from_master = $params['dbw'] ? true : false;
        $clause = array(
            'where' => "$primary_table.id = $id"
        );
        // remvoe the objects from aql_array so they will be loaded lazily
        foreach ($aql_array as $table => $data) {
            unset($aql_array[$table]['objects']);
        }
        // select the data from the database
        $rs = \aql::select($aql_array, $clause, null, null, $read_from_master);

        $data = (object) $rs[0];

        // if the record is not found
        if (!count((array)$data)) {
            $this->addInternalError('not_found', array(
                'message' => 'Record not found.',
                'clause' => $clause
            ));
            return $this;
        }

        $this->_data = $data;
        $idfield = $primary_table . '_id';
        $this->_data->id = $this->_data->$idfield;
        $this->_data->ide = encrypt($this->id, $primary_table);
        $this->getNestedObjects();
        $this->callMethod('construct');
        $this->saveDataToCache();
        // don't show the cache time since this is not cached data
        unset($this->_data->_cache_time);

        return $this;
    }

    public function lazyLoadProperty($property)
    {
        //elapsed("lazyLoadProperty($property)");

        // determine if this property is a lazy load object
        $lazyMetadata = static::$_meta['lazyObjects'][$property];
        // if it is a lazy object and it has not yet been loaded
        if ($lazyMetadata && is_string($this->_data->$property)) {
            // get the full class name that is nested
            $model = $lazyMetadata['model'];
            $nested_class = static::getNamespacedModelName($model);
            // get the nested object(s)
            if ($lazyMetadata['plural']) {
                // lazy load the list of objects
                // determine the where clause to get the list of objects
                $class = static::meta('class');
                $primary_table = $class::getPrimaryTable();
                $field = $primary_table . '_id';
                $search = '{$' . $field . '}';
                $replace = $this->getID();
                $objects = array();
                // if this object has an id
                if ($replace) {
                    $where = str_replace($search, $replace, $lazyMetadata['sub_where']);
                    // determine if cached list is enabled for this field in the nested model
                    if (is_array($nested_class::$_meta['cachedLists'])
                        && array_search($field, $nested_class::$_meta['cachedLists']) !== false) {
                        $useCachedList = true;
                        // remove spaces from where so it's a better cache key
                        $cachedListKey = "list:" . str_replace(' ', '', $where);
                        elapsed("Using $cachedListKey");
                    }

                    // TODO don't use $_GET['refresh'] here
                    if ($useCachedList && !$_GET['refresh']) {
                        $list = mem($cachedListKey);
                    }
                    if ($list) {
                        elapsed("Lazy loaded mem($cachedListKey)");
                    } else {
                        elapsed('Lazy load objects from DB');
                        $list = $nested_class::getList(array(
                            'where' => $where
                        ));
                        if ($useCachedList) {
                            mem($cachedListKey, $list);
                        }
                    }
                    // convert the list to actual objects
                    foreach ($list as $id) {
                        $objects[] = $nested_class::get($id, array(
                            'depth' => $this->_depth + 1
                        ));
                    }
                }

                $this->_data->$property = $objects;
                return $objects;

            } else {
                // lazy load the single object
                $aql_array = static::meta('aql_array');
                $field = $lazyMetadata['constructor argument'];
                $object = null;
                if ($this->$field) {
                    $object = new $nested_class($this->$field, array(
                        'depth' => $this->_depth + 1,

                    ));
                }
                $this->_data->$property = $object;
                return $object;
            }
        }
    }

    /**
     * save a value to the cache
     */
    public static function cacheWrite($key, $value)
    {
        mem($key, $value);
    }

    /**
     * read a value from the cache
     */
    public static function cacheRead($key)
    {
        return mem($key);
    }

    /**
     *
     */
    public static function getWritableProperties()
    {
        // aggregate all writable fields from the aql_array into a single list
        $aql_array = static::meta('aql_array');
        $readOnly = static::meta('readOnlyTables');
        $all_fields = array();
        foreach ($aql_array as $block) {
            if (is_array($block['fields'])) {
                foreach ($block['fields'] as $alias => $field) {
                    if (is_array($readOnly) && array_search($block['as'], $readOnly)) {
                        continue;
                    }
                    $all_fields[$alias] = $field;
                }
            }
        }
        return $all_fields;
    }

    /**
     *
     */
    public function __toString ()
    {
        return @d($this);
    }

    /**
     * Get many id's with given criteria
     */
    public static function getList($criteria = array())
    {
        $fn = \getList::getFn(static::getAQL());
        return $fn($criteria);
    }

    public static function count($criteria)
    {
        return static::getCount($criteria);

    }

    /**
     * Get the number of objects with given criteria
     */
    public static function getCount($criteria = array())
    {
        $fn = \getList::getFn(static::getAQL());
        return $fn($criteria, true);
    }

    /**
     *
     */
    protected static function getOneToOneProperties()
    {
        $properties = array();
        $objects = static::meta('lazyObjects');
        if (is_array($objects)) {
            foreach ($objects as $property => $object_info) {
                if (!$object_info['plural']) {
                    $properties[] = $property;
                }
            }
        }
        return $properties;
    }

    /**
     *
     */
    protected static function getOneToManyProperties()
    {
        $properties = array();
        $objects = static::meta('lazyObjects');
        if (is_array($objects)) {
            foreach ($objects as $property => $object_info) {
                if ($object_info['plural']) {
                    $properties[] = $property;
                }
            }
        }
        return $properties;
    }

    /**
     *
     */
    public static function getOneToManyKey()
    {
        return static::getPrimaryTable() . '_id';

    }

    /**
     *
     */
    public static function getForeignKey($property)
    {
        $object_info = static::$_meta['lazyObjects'][$property];
        return $object_info['primary_table'] . '_id';

    }

    /**
     *
     */
    private static function getNamespacedModelName($model = null)
    {
        if (!$model) {
            return false;
        }
        $class = get_called_class();
        $rc = new \ReflectionClass($class);
        $ns = $rc->getNamespaceName();
        return "\\$ns\\$model";
    }

    /**
     * adds an error to _errors if a required field is missing
     */
    public function checkRequiredFields()
    {
        $required_fields = static::meta('requiredFields');
        if (is_array($required_fields)) {
            //d($this->_data);
            foreach ($required_fields as $field => $description) {
                if (!$this->_data->$field && $this->_data->$field !== '0') {
                    $this->addInternalError('field_is_required', array(
                        'message' => sprintf(self::E_FIELD_IS_REQUIRED, $description),
                        'fields' => array($field)
                    ));
                }
            }
        }
        return $this;
    }

    /**
     *
     */
    public static function getMetadata()
    {
        // get aql array if we don't already have it
        if (!static::meta('aql_array')) {

            $aql_array = \aql2array(static::getAQL());
            // identify the lazy objects
            foreach ($aql_array as $i => $table) {
                $objects = $table['objects'];
                if (is_array($objects)) {
                    foreach ($objects as $alias => $object) {
                        $model = $object['model'];
                        $ns_model = static::getNamespacedModelName($model);
                        if (!$object['plural']) {
                            $field = $object['constructor argument'];
                            // quick fix, TODO: fix aql2array
                            if ($field == '_id') {
                                $field = $ns_model::getPrimaryTable() . '_id';
                            }
                            $full_field = $table['table'] . '.' . $field;
                            $aql_array[$i]['fields'][$field] = $full_field;
                        }
                        static::$_meta['lazyObjects'][$alias] = $object;
                    }
                }
            }

            // set aql_array
            static::meta('aql_array', $aql_array);

            // set primary_table
            $primary_table_data = reset($aql_array);
            static::meta('primary_table', $primary_table_data['table']);

            // set called class
            static::meta('class', get_called_class());
        }
    }

    /**
     *
     */
    public static function getPrimaryTable()
    {
        static::getMetadata();
        return static::meta('primary_table');
    }

    /**
     * @return int | null
     */
    public function getID()
    {
        if ($this->id) {
            return $this->id;
        }

        $primary_table = static::meta('primary_table');
        $field = $primary_table . '_id';
        $field_ide = $field . 'e';

        if ($this->$field) {
            return $this->$field;
        }
        if ($this->$field_ide) {
            return decrypt($this->$field_ide, $primary_table);
        }
        return null;
    }

    /**
     *
     */
    public function getIDE()
    {
        if ($this->ide) {
            return $this->ide;
        }
        $primary_table = static::getPrimaryTable();
        $field_id = $primary_table . '_id';
        $field_ide = $field_id . 'e';

        if ($this->$field_ide) {
            return $this->field_ide;
        }
        if ($this->$field_id) {
            return encrypt($this->$field_id, $primary_table);
        }
        return null;
    }

    /**
     *
     */
    public function getNestedObjects()
    {
        // add a placeholder message for each of the lazy objects
        $lazyObjects = static::meta('lazyObjects');
        if (is_array($lazyObjects)) {
            foreach ($lazyObjects as $alias => $object) {
                if ($object['plural']) {
                    $this->_data->$alias = self::LAZY_OBJECTS_MESSAGE;
                } else {
                    $this->_data->$alias = self::LAZY_OBJECT_MESSAGE;
                }
            }
        }
    }

    /**
     *
     */
    public static function meta($key, $value = '§k¥')
    {
        if ($value == '§k¥') {
            // read
            return static::$_meta[$key];
        } else {
            // write
            static::$_meta[$key] = $value;
        }
    }


    /**
     * @param   mixed       $id    identifier(id, ide), default: $this->getID()
     * @return  string
     * @global  $db_name
     */
    public static function getCacheKey($id)
    {
        global $db_name;

        if (!$id) {
            return null;
        }

        $mod_time = static::meta('mod_time');

        return static::meta('class') . ':' . $id . ':' . $db_name . ':' . $mod_time;
    }



    /**
     *
     */
    protected function beginTransaction()
    {
        \aql::getMasterDB()->StartTrans();
        \Sky\Memcache::begin();

        // if this is the first begin
        // take a snapshot of the data in case we need to rollback
        if ($this->_depth == 0) {
            $this->_revert = static::deepClone($this->_data);
        }

        return $this;
    }

    /**
     *
     */
    public static function deepClone($object) {
        if (!is_object($object)) {
            return $object;
        }
        $clone = clone $object;
        foreach ($object as $key => $value) {
            if (is_object($value)) {
                $clone->$key = static::deepClone($value);
            } else if (is_array($value)) {
                foreach ($value as $k => $v) {
                    if (is_object($v)) {
                        $clone->{$key}[$k] = static::deepClone($v);
                    }
                }
            } else {
                $clone->$key = $value;
            }
        }
        return $clone;
    }

    /**
     *
     */
    protected function reloadSavedObjects()
    {
        elapsed(static::meta('class') . '->reloadSavedObjects()');

        $mods = $this->getModifiedProperties();

        // reload and cache this object
        $this->getDataFromDatabase();

        // reload and cache 1-to-1 nested objects
        $objects = static::getOneToOneProperties();
        if (is_array($objects)) {
            foreach ($objects as $property) {
                // if this nested object has at least 1 modified field
                if (count((array)$mods->$property)) {
                    $this->$property->getDataFromDatabase();
                }
            }
        }

        //elapsed('before getOneToManyProperties');

        // reload and cache 1-to-many nested objects
        $objects = static::getOneToManyProperties();

        //elapsed('after getOneToManyProperties');

        //d($this);

        if (is_array($objects)) {
            foreach ($objects as $property) {
                if (is_array($mods->$property)) {
                    foreach ($mods->$property as $i => $object) {
                        // if this nested one-to-many object has at least 1 modified field
                        //d($object);
                        if (count((array)$object)) {
                            //d($property);
                            //d($this->$property);
                            $this->{$property}[$i]->getDataFromDatabase();
                        }
                    }
                }
            }
        }

    }

    /**
     *
     */
    protected function callAfterCommitHooks()
    {
        $mods = $this->getModifiedProperties();

        // call this object's afterCommit()
        $this->callMethod('afterCommit');

        // call 1-to-1 nested objects' afterCommit()
        $objects = static::getOneToOneProperties();
        if (is_array($objects)) {
            foreach ($objects as $property) {
                // if this nested object has at least 1 modified field
                if (count((array)$mods->$property)) {
                    $this->$property->callMethod('afterCommit');
                }
            }
        }

        // call 1-to-many nested objects' afterCommit()
        $objects = static::getOneToManyProperties();
        if (is_array($objects)) {
            foreach ($objects as $property) {
                if (is_array($mods->$property)) {
                    foreach ($mods->$property as $i => $object) {
                        // if this nested one-to-many object has at least 1 modified field
                        if (count((array)$object)) {
                            $this->{$property}[$i]->callMethod('afterCommit');
                        }
                    }
                }
            }
        }

    }

    /**
     *
     */
    protected function commitTransaction()
    {
        elapsed(static::meta('class') . '->commitTransaction()');
        \aql::getMasterDB()->CompleteTrans();
        \Sky\Memcache::commit();

        // if this is the final commit remove _revert property
        if ($this->_depth == 0) {
            // discard the _revert data since we are not rolling back
            unset($this->_revert);

            // all data has been committed to the master database
            // traverse all saved objects and run afterCommit hooks
            $this->callAfterCommitHooks();

            // reload each object that was successfully saved
            // remove _modified, contruct, and save to cache
            // this is here because afterCommit needs _modified
            $this->reloadSavedObjects();
        }

        return $this;
    }

    /**
     *
     */
    protected function rollbackTransaction()
    {
        elapsed(static::meta('class') . '->rollbackTransaction()');
        //d($this);
        \aql::getMasterDB()->FailTrans();
        \aql::getMasterDB()->CompleteTrans();
        \Sky\Memcache::rollback();

        #d(get_called_class());
        #print_r($this->_data);
        #dd(1);

        // get all the errors from nested objects because when we revert back we will
        // lose all the error messages, etc
        $this->getChildErrors();

        //d($this);

        // if this is the final rollback, revert the data back to what it was before save
        if ($this->_depth == 0) {
            $this->_data = static::deepClone($this->_revert);
            unset($this->_revert);
        }

        return $this;
    }

    /**
     *
     */
    protected function isFailedTransaction()
    {
        $db = \aql::getMasterDB();
        $failed = $db->HasFailedTrans();
        if ($failed) {
            elapsed(static::meta('class') . '->isFailedTransaction(): yes');
        }
        return $failed;
    }


    /**
     * @return array       response array
     * @final
     */
    final public function getResponse()
    {
        if (count($this->_errors)) {
            return array(
                'status' => 'Error',
                'errors' => array_filter($this->getErrorMessages()),
                'debug' => $this->_errors,
                #'data' => $this->dataToArray(true)
            );
        }
        // if no errors
        return array(
            'status' => 'OK',
            #'data' => $this->dataToArray(true),
            '_token' => $this->getToken()
        );
    }

    /**
     * @return array
     */
    protected function getErrorMessages()
    {
        return array_map(function($e) {
            return (is_string($e)) ? $e : $e->message;
        }, $this->_errors);
    }



    /**
     * returns this object's data as an array
     * we use ModelArrayObjects instead of arrays and these need to get converted back
     *
     * @param  Boolean     $hideIds   if true, remove "_id" fields (keep _ides)
     *                                 default false
     * @return array
     */
    public function dataToArray($hideIds = false)
    {
        return self::objectToArray($this, $hideIds);
    }

    /**
     * convert object to array recursively
     */
    public static function objectToArray($obj, $hideIds = false)
    {
        if (!is_object($obj)) {
            return $obj;
        }
        if ($obj->_data) {
            $obj = $obj->_data;
        }
        $array = array();
        foreach ($obj as $property => $value) {
            if ($hideIds) {
                if ($property == 'id') continue;
                if (substr($property,-3) == '_id') continue;
            }

            if (is_array($value)) {
                $values = array();
                foreach ($value as $k => $v) {
                    $values[$k] = static::objectToArray($v, $hideIds);
                }
                $value = $values;
            }
            $array[$property] = static::objectToArray($value, $hideIds);
        }
        return $array;
    }





    #################################################################################
    #  Backwards Compatibility                                                      #
    #################################################################################









    /**
     * Plural subobject specific "array_map", because these are not arrays
     * if the model has [sub_model]s
     *     $things = $model->mapSubObjects('sub_model', $callback)
     * @param  string      $name           object name
     * @param  callback    $fn             defaults to null
     * @param  Boolean     $skip_id_filter skip is filter, defaults to false
     * @return array                       like in array map
     * @throws InvalidArgumentException    invalid $name
     */
    public function mapSubObjects($name, $fn = null, $skip_id_filter = false)
    {
        if (!static::$_meta['lazyObjects'][$name]['plural']) {
        #if (!$this->isPluralObject($name)) {
            $e = 'mapSubObjects expects a valid plural object param.';
            throw new InvalidArgumentException($e);
        }

        if ($fn && !is_callable($fn)) {
            $e = '$fn is not callable.';
            throw new InvalidArgumentException($e);
        }

        $map = function($o) use ($skip_id_filter, $fn) {
            if (!$skip_id_filter && !$o->getID()) {
                return null;
            }

            return ($fn) ? $fn($o) : $o;
        };

        return array_map($map, (array) $this->{$name});
    }


    /**
     * Uses required fields to fetch the identifier of the object if it is not set
     * Should generally be used in postValidate() for a uniqueness constraint on the
     * required fields
     * This sets $this->{primary_table_id}
     *
     * @return Model   $this
     */
    public function getIDByRequiredFields()
    {
        elapsed(static::meta('class') . '->getIDByRequiredFields()');

        # if there are errors | have ID | no required fields return
        if ($this->_errors || $this->getID() || !$this->hasRequiredFields()) {
            return $this;
        }

        # set up
        $where = array();
        $clause = array('limit' => 1, 'where' => &$where);
        $aql = sprintf('%s { }', static::getPrimaryTable());
        $key = static::getPrimaryTable() . '_id';

        # make where
        foreach ($this->getRequiredFields() as $f) {
            $where[] = sprintf("%s = '%s'", $f, $this->{$f});
        }

        $rs = \aql::select($aql, $clause);
        $this->{$key} = ($rs[0][$key]) ?: $this->{$key};
        #$this->_token = ($this->_token) ?: $this->getToken();

        return $this;
    }

    /**
     * @return array
     */
    public function getRequiredFields()
    {
        return array_keys(static::meta('requiredFields'));
    }

    /**
     * @return Boolean
     */
    public function hasRequiredFields()
    {
        if (count(static::meta('requiredFields'))) {
            return true;
        }
        return false;
    }

    /**
     * does nothing, just here for backwards compatibility
     */
    public function addProperty()
    {

    }

    /**
     * unnecessary, just here for backwards compatibility
     */
    public function propertyExists($property)
    {
        if (isset($this->_data->$property)) {
            return true;
        }
        return false;
    }

    /**
     * @return Boolean
     */
    public function isStaticCall()
    {
        if (!isset($this) && !self::isModelClass($this)) {
            return true;
        }
        $bt = debug_backtrace();

        return (!is_a($this, $bt[1]['class']));
    }

    /**
     * @param  string  $field_name
     * @return Boolean
     */
    public function fieldIsSet($field_name)
    {
        return isset($this->_data->$field_name);
    }

    /**
     *
     */
    public function preFetchRequiredFields($id = null)
    {

    }

    /**
     * to be used statically
     * @param mixed $id                identifier (id, ide)
     * @param string $primary_table    primary_table, default to get called class
     * @return mixed                   string or null
     */
    public static function generateToken($id = null, $primary_table = null)
    {
        if (!$primary_table) {
            $cl = static::meta('class');
            $o = new $cl;

            return $o->getToken($id);
        }

        if ($id && !is_numeric($id)) {
            $id = decrypt($id, $primary_table);
        }

        return self::_makeToken($id, $primary_table);
    }

    /**
     * to be used on an instantiated object
     * @param mixed $id                identifier(id, ide), default: $this->getID()
     * @param string $primary_table    primary_table, default: static::getPrimaryTable()
     * @return mixed                   string or null
     */
    public function getToken($id = null, $primary_table = null)
    {
        $primary_table = ($primary_table) ?: static::getPrimaryTable();
        if ($id && !is_numeric($id)) {
            $id = decrypt($id, $primary_table);
        }
        $id = ($id) ?: $this->getID();

        return self::_makeToken($id, $primary_table);
    }

    /**
     * @param int $id
     * @param string $table
     * @return mixed       string or null
     */
    private static function _makeToken($id, $table)
    {
        return ($id && $table)
            ? encrypt($id, encrypt($id, $table))
            : null;
    }

    /**
     * Returns an object based on the argument $o
     * @param  mixed   $o
     * @return Model
     * @throws Exception if cannot find the model
     */
    public static function convertToObject($o)
    {
        if (self::isModelClass($o)) {
            return $o;
        }

        $cl = get_called_class();
        $obj = new $cl($o);

        if (!$obj->getID()) {
            throw new \Exception('ID not found');
        }

        return $obj;
    }

    /**
     * Returns an ID based on the argument $o
     * @param  mixed   $o
     * @return string
     * @throws \Exception if cannot find the ID
     */
    public static function convertToID($o)
    {
        if (is_numeric($o)) {
            return $o;
        }

        if (self::isModelClass($o)) {
            $id = $o->getID();
            if (!$id) {
                throw new Exception('Paramter is an empty object');
            }

            return $id;
        }

        $cl = get_called_class();
        $tmp = new $cl;
        $tbl = $tmp->getPrimaryTable();

        $id = decrypt($o, $tbl);
        if (!$id) {
            throw new Exception('ID not found.');
        }

        return $id;
    }

    /**
     * Returns an IDE based on the argument $o
     * @param  mixed   $o
     * @return string
     * @throws \Exception if cannot find the IDE or it is invalid
     */
    public static function convertToIDE($o)
    {
        if (self::isModelClass($o)) {
            $ide = $o->getIDE();
            if (!$ide) {
                throw new Exception('Parameter is an empty object.');
            }

            return $ide;
        }

        $cl = get_called_class();
        $tmp = new $cl;
        $tbl = $tmp->getPrimaryTable();

        if (is_numeric($o)) {
            return encrypt($o, $tbl);
        }

        $id = decrypt($o, $tbl);
        if (!$id) {
            throw new Exception('IDE not found.');
        }

        return $o;
    }

    /**
     * @param  mixed $class
     * @return Boolean
     */
    public static function isModelClass($class)
    {
        if (is_object($class) || (is_string($class) && class_exists($class))) {
            $ref = new \ReflectionClass($class);
            return $ref->isSubclassOf('\Sky\Model');
        }
        return false;
    }

    /**
     * Return array of objects matching the criteria specified
     * @param  array $clause
     * @return array
     */
    // public static function getByClause(array $clause = array())
    // {
    //     return static::getMany($clause);
    // }


    /**
     *
     */
    public function getModelName()
    {
        return get_class($this);
    }




}
