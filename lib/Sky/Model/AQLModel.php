<?php

namespace Sky\Model;

use Sky\AQL as AQL;

/*

TODO

- test PDO nested commit rollbacks

- don't allow setting of read-only properties / read-only tables

- don't allow saving of nested objects that are readOnlyProperties

- when setting $this->object or $this->object->id, also need to set $this->object_id

- instead of string placeholder, use a ModelPlaceholder object that knows what ID will be
  loaded so you can lazy load an array of nested 1-to-m object ids (only applicable if
  cached list is not being used for that field) without actually loading every one of
  the actual objects until the specific 1-to-m object needs to be accessed.

- allow saving multiple joined records of the same table name for a given object

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

- support for nested queries

*/




/**
 * A Model class using AQL as the ORM with the following features:
 *
 *  - All of the features of PHPModel
 *  - AQL integration
 *  - Memcache integration
 *  - Object properties can be fields from joined tables as well as other objects with
 *    either 1-to-1 or 1-to-m relationship
 *  - id fields are automatically encrypted/decrypted upon being set when applicable
 */
class AQLModel extends PHPModel
{

    /**
     * Instantiates the object. Attempts to get the data from cache, otherwise gets the
     * data from the database. Also adds a placeholder for each nested object to be
     * lazy loaded on-demand.
     * @param mixed $data The ID of an existing object, or an associative array (or object)
     *                    containing data for creating a new object
     * @param array $params
     *                  refresh - if true, discards existing cached data
     *
     *                  The following $params keys are used internally:
     *                  parent  - parent object if this is a nested object
     *                  parent_key - parent object's foreign key if this is a 1-to-1
     *                               nested object
     * @return Model $this
     */
    final public function __construct($data = null, $params = null)
    {
        // we will use this to remember which objects have been loaded from db
        // so we don't refresh the same thing repetitively
        static $memoize;

        // make sure we have all metadata
        static::getMetadata();

        // link the parent object
        if ($params['parent']) {
            $this->_parent = null; // create the property first
            $this->_parent = &$params['parent'];
        }

        // if this is a 1-to-1 nested object, set the parent's foreign key
        if ($params['parent_key']) {
            $this->_parent_key = null; // create the property first
            $this->_parent_key = $params['parent_key'];
        }

        // if we are pre-populating a new object with new data
        if (is_array($data) || is_object($data) || $data == null) {
            // instantiate a new object with the given data
            $this->set($data);
            if ($this->_errors) {
                //throw new \ValidationException($this->_errors);
            } else {
                elapsed(get_called_class() . ' call construct');
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

        $class = get_called_class();

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
     * Saves this single object to the database.  Will insert and/or update all of the
     * joined tables that compose the object.  Will add an error if any of the database
     * commands is unsuccessful.  Also, if this is a nested object, populate the parent
     * object's foreign key with this ID.
     */
    public function saveDataToDatabase()
    {
        elapsed(get_called_class() . '->saveDataToDatabase()');

        #d($this);

        // if nothing to save
        if (!$this->_modified) {
            return false;
        }

        $aql = static::meta('aql');

        $saveStack = [];
        foreach ($aql->blocks as $block) {
            $table = $block->table;
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

        #d($saveStack);
        #d($this);

        // organize the modified data fields by table
        // and omit fields corresponding to read-only properties
        $data = [];
        $aliases = []; // track the aliases that have been set so we don't overwrite
                       // for example a.b_id gets saved to db not b.id as b_id
        $readOnly = static::meta('readOnlyProperties');
        foreach ($aql->blocks as $block) {
            #d($block);
            foreach ($block->fields as $f) {
                $alias = $f['alias'];
                $field = $f['field'];
                #elapsed($alias);
                if (!is_array($readOnly) || !in_array($alias, $readOnly)) {
                    if (property_exists($this->_modified, $alias) && !$aliases[$alias]) {
                        $field = substr($field, strpos($field, '.') + 1);
                        $data[$block->table][$field] = $this->_data->$alias;
                        #d($data);
                        $aliases[$alias] = true;
                        #d($aliases);
                    } else {
                        #elapsed($alias . ' not in _modified');
                    }
                }
            }
        }

        #d($data);

        // check to see if there are any data fields to be saved for each table
        // if so, insert or update
        $fk_values = []; // keep track of newly inserted id's
        foreach ($saveStack as $table) {
            if ($data[$table]) {
                // there is something to save in this table
                // set the foreign key values that were just inserted
                $block = $aql->getBlock($table);
                if (is_array($block->foreignKeys)) {
                    #d($block->foreignKeys);
                    #d($fk_values);
                    foreach ($block->foreignKeys as $fk) {
                        if ($fk_values[$fk['table']]) {
                            $data[$table][$fk['key']] = $fk_values[$fk['table']];
                        }
                    }
                }

                // now update or insert the record
                $id_field = $table . AQL\Block::FOREIGN_KEY_SUFFIX;
                $id = $this->$id_field;

                // remove the placeholders for values that will be updated after the
                // nested object are inserted
                foreach ($data[$table] as $k => $v) {
                    if ($v == static::FOREIGN_KEY_VALUE_TBD) {
                        unset($data[$table][$k]);
                    }
                }

                if (is_numeric($id)) {
                    $r = AQL::update($table, $data[$table], $id);
                    if (!$r) {
                        $error = AQL::$errors[0];
                        $e = $error['exception'];
                        $this->addInternalError('database_error', array(
                            'message' => $e->getMessage(),
                            'trace' => $e->getTrace(),
                            'db_error' => $e->db_error,
                            'fields' => $e->fields
                        ));
                        return;
                    }
                } else {
                    $r = AQL::insert($table, $data[$table]);
                    #d($r);
                    if (!$r) {
                        $error = AQL::$errors[0];
                        $e = $error['exception'];
                        $this->addInternalError('database_error', array(
                            'message' => $e->getMessage(),
                            'trace' => $e->getTrace(),
                            'db_error' => $e->db_error,
                            'fields' => $e->fields
                        ));
                        return;
                    }
                    // update the id properties of this object with the new id value
                    $id = $r->id;
                    #d($id);
                    $this->_data->$id_field = $id;
                    $this->afterSetValue($id_field, $id);
                    // maybe another table in this object needs this id for joining
                    $fk_values[$table] = $id;
                }
            }
        }

        // update the parent object's foreign key if this is a 1-to-1 nested object
        if ($this->_parent_key) {
            $parent_key = $this->_parent_key;
            $this->_parent->$parent_key = $this->id;
        }
    }


    /**
     * Gets table names to be saved in the correct order based on foreign key dependencies
     * @return array
     */
    public static function getSaveStack($table)
    {
        $aql = static::meta('aql');
        // TODO: account for table block alias different from fk table name
        $dependencies = $aql->getBlock($table)->foreignKeys;
        $saveStack = [];
        if (count($dependencies)) {
            // if dependencies, recursively get dependencies
            // then add this table to the saveStack
            foreach ($dependencies as $dependency) {
                $tempStack = static::getSaveStack($dependency['table']);
                $saveStack = array_merge($saveStack, $tempStack);
            }
        }
        $saveStack[] = $table;
        return $saveStack;
    }


    /**
     * Gets the AQL statement that defines the data structure of this object
     * @return string
     */
    public static function getAQL()
    {
        return static::AQL;
    }


    /**
     * Deletes the record
     * @return Model returns an object with not_found error if delete was successful
     */
    public function delete()
    {
        // TODO: don't save any pending modifications during the delete process
        $this->active = 0;
        return $this->save();
    }


    /**
     * Sets multiple properties and/or properties of nested objects and validates them
     * @param array|object $data
     * @return Model $this
     */
    public function set($data)
    {
        if (is_object($data)) {
            $data = static::objectToArray($data);
        }

        if (is_array($data)) {

            // first check to see if an id is set, if so, fully load the object
            // then after original object is loaded set the modified properties
            $primary_table = static::getPrimaryTable();
            $primary_id = $primary_table . AQL\Block::FOREIGN_KEY_SUFFIX;
            $primary_ide = $primary_id . AQL\Block::ENCRYPTED_SUFFIX;

            // determine if we have an id
            $id = null;
            $id = $data['ide'] ?: $id;
            $id = $data[$primary_ide] ?: $id;
            $id = $data['id'] ?: $id;
            $id = $data[$primary_id] ?: $id;

            // load the existing data from db or cache
            if ($id) {
                $class = get_class($this);
                $temp = new $class($id);
                $this->_data = $temp->_data;
            } else if (!$this->_data) {
                $this->_data = new \stdClass;
                $this->_data->id = null; //static::FOREIGN_KEY_VALUE_TBD;
            }

            // set the properties in two passes: set fields first, then set objects
            // (so when you set the objects, you know all your fields are already set)
            $passes = ['fields', 'objects'];
            foreach ($passes as $pass) {
                foreach ($data as $property => $value) {

                    $lazy = static::$_meta['lazyObjects'][$property];

                    // if the property is a lazyObject
                    if ($lazy && $pass == 'objects') {
                        $model = $lazy['model'];
                        $nested_class = static::getNamespacedModelName($model);
                        if ($lazy['type'] == 'many') {
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
                                if (!$val[$key]) {
                                    $val[$key] = static::FOREIGN_KEY_VALUE_TBD;
                                }
                                // nest the object
                                $obj = new $nested_class($val, array(
                                    'parent' => &$this
                                ));
                                $value[] = $obj;
                            }
                        } else {

                            #d($property);
                            #d(static::$_meta['lazyObjects'][$property]);

                            $foreign_key = static::getForeignKey($property);

                            if (!$foreign_key) {
                                $foreign_key = $nested_class::getPrimaryTable()
                                         . AQL\Block::FOREIGN_KEY_SUFFIX;
                            }

                            $value = new $nested_class($value, array(
                                'parent' => &$this,
                                'parent_key' => $foreign_key
                            ));
                            $this->$foreign_key = $value->getID();
                            if (!$this->$foreign_key) {
                                $this->$foreign_key = static::FOREIGN_KEY_VALUE_TBD;
                            }
                        }
                        $this->_data->$property = $value;
                    }

                    // if the property is a regular data field
                    if (!$lazy && $pass == 'fields') {
                        $this->setValue($property, $value);
                    }

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
     * Sets additional fields that can be inferred by the given property value.
     * For example, given a model Apple:
     *   id --> ide, apple_id, apple_ide
     *   banana_ide --> banana_id
     * @param string $property the name of the property that was set
     * @param mixed $value the value of the property that was set
     * @return Model $this
     */
    public function afterSetValue($property, $value)
    {
        #elapsed(get_called_class() . '::afterSetValue(' . $property . ')');

        $_id = AQL\Block::FOREIGN_KEY_SUFFIX;
        $_ide = $_id . AQL\Block::ENCRYPTED_SUFFIX;

        $primary_table = static::getPrimaryTable();
        $primary_id = $primary_table . $_id;
        $primary_ide = $primary_table . $_ide;

        // if we just set $this->id
        if ($property == 'id') {
            $id = $value;
            if (is_numeric($id)) {
                $ide = \encrypt($value, $primary_table);
            }
            #$this->_data->id = $id;
            $this->_data->ide = $ide;
            $this->_data->$primary_id = $id;
            $this->_data->$primary_ide = $ide;

        // if we just set $this->ide
        } else if ($property == 'ide') {
            $id = \decrypt($value, $primary_table);
            $ide = $value;
            $this->_data->id = $id;
            #$this->_data->ide = $ide;
            $this->_data->$primary_id = $id;
            $this->_data->$primary_ide = $ide;

        // if we just set $this->field_id
        } else if (substr($property, -1 * strlen($_id)) == $_id) {
            $table = substr($property, 0, -3);
            if (strpos($table, '__')) {
                $alias = substr($table, 0, strpos($table, '__') + 2);
            }
            $table = str_replace($alias, '', $table);
            $field_id = $alias . $table . $_id;
            $field_ide = $alias . $table . $_ide;
            $id = $value;
            $ide = null;
            if (is_numeric($id)) {
                $ide = \encrypt($id, $table);
            }
            #$this->_data->$field_id = $id;
            $this->_data->$field_ide = $ide;
            if ($table == $primary_table && !$alias) {
                $this->_data->id = $id;
                $this->_data->ide = $ide;
            }

        // if we just set $this->field_ide
        } else if (substr($property, -1 * strlen($_ide)) == $_ide) {
            $table = substr($property, 0, -1 * strlen($_ide));
            if (strpos($table, '__')) {
                $alias = substr($table, 0, strpos($table, '__') + 2);
            }
            $table = str_replace($alias, '', $table);
            $field_id = $alias . $table . $_id;
            #$field_ide = $alias . $table . $_ide;
            $id = \decrypt($value, $table);
            $ide = $value;
            if (!$this->_modified) {
                $this->_modified = new \stdClass;
            }
            $this->_modified->$field_id = $this->_data->$field_id;
            $this->_data->$field_id = $id;
            #$this->_data->$field_ide = $ide;
            if ($table == $primary_table && !$alias) {
                $this->_data->id = $id;
                $this->_data->ide = $ide;
            }
        }

        // Anytime we are setting an id field that is not the primary table, check to see
        // if this id corresponds to a 1-to-1 lazy object, and instantiate it if applicable
        if ($field_id && $field_id != $primary_id) {
            $lazyObjects = static::meta('lazyObjects');
            if (is_array($lazyObjects)) {
                foreach ($lazyObjects as $property => $info) {
                    // TODO: model is not necessarily the table name
                    if ($info['model'] == $table) {
                        // for now, just put a placeholder for the object
                        $this->_data->$property = static::LAZY_OBJECT_MESSAGE;
                        /*
                        // instantiate the object
                        $model = $info['model'];
                        $ns_model = static::getNamespacedModelName($model);
                        $this->_data->$property = new $ns_model($id, array(
                            'parent' => &$this,
                            'parent_key' => $field_id
                        ));
                        */
                        break;
                    }
                }
            }
        }

        return $this;
    }


    /**
     * Recompiles any cached lists that correspond to a property that has been modified
     * This is executed after insert/update, but before the transaction is committed.
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
                        $aql = static::meta('aql');
                        $dbfield = null;
                        foreach ($aql->blocks as $block) {
                            foreach ($block->fields as $f) {
                                $alias = $f['alias'];
                                $field = $f['field'];
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
     * Gets the data from the database for the given ID, runs construct(), and caches
     * the object
     * @param int $id optional id of the object
     * @param array $params optional parameters
     *                  dbw - if true, reads from the master database
     * @return Model $this
     */
    public function getDataFromDatabase($id = null, $params = array())
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
        $aql = static::meta('aql');
        $primary_table = static::meta('primary_table');

        //d($aql->blocks[0]->fields);
        //$aql->blocks[0]->fields[] = [];

        // remove the objects from aql_array so they will be loaded lazily
        /*
        foreach ($aql_array as $table => $data) {
            unset($aql_array[$table]['objects']);
        }
        */

        try {
            // select the data from the database
            $rs = AQL::select($aql, [
                'where' => "$primary_table.id = $id",
                'dbw' => $params['dbw'] ? true : false
            ]);
        } catch (Exception $e) {
            d($e);
        }

        $data = $rs[0];

        // if the record is not found
        if (!count((array)$data)) {
            $this->addInternalError('not_found', array(
                'message' => 'Record not found.',
                'where' => "$primary_table.id = $id"
            ));
            return $this;
        }

        $this->_data = $data;

        // encrypt all of the other id fields
        // but first get the max # fields to process
        $max = count((array)$this->_data);
        $i = 0;
        foreach ($this->_data as $k => $v) {
            $i++;
            $this->afterSetValue($k, $v);
            if ($max == $i) {
                break;
            }
        }

        // add the placeholders for the nested objects
        $this->getNestedObjects();
        $this->callMethod('construct');
        $this->saveDataToCache();
        // don't show the cache time since this is not cached data
        unset($this->_data->_cache_time);

        return $this;
    }


    /**
     * Instantiates the given nested object. Used in conjunction with __get magic method
     * to load nested objects on-demand.
     * @param string $property the name of the property to load
     */
    public function lazyLoadProperty($property)
    {
        // determine if this property is a lazy load object
        $lazyMetadata = static::$_meta['lazyObjects'][$property];

        // if it is a lazy object and it has not yet been loaded
        if ($lazyMetadata && is_string($this->_data->$property)) {

            elapsed("lazyLoadProperty($property)");

            elapsed(get_called_class());

            // get the full class name that is nested
            $model = $lazyMetadata['model'];
            $nested_class = static::getNamespacedModelName($model);

            // get the nested object(s)
            if ($lazyMetadata['type'] == 'many') {
                #d($lazyMetadata);
                // lazy load the list of objects
                // determine the where clause to get the list of objects
                $primary_table = static::getPrimaryTable();
                $field = $nested_class::getPrimaryTable()
                        . '.'
                        . $primary_table . AQL\Block::FOREIGN_KEY_SUFFIX;
                //$search = '{$' . $field . '}';
                //$replace = $this->getID();
                $id = $this->getID();
                $objects = [];

                $where = "$field = $id";

                // if this object has an id
                if ($id) {
                    $oneToManyFK = $primary_table . AQL\Block::FOREIGN_KEY_SUFFIX;

                    //$where = str_replace($search, $replace, $lazyMetadata['sub_where']);
                    // determine if cached list is enabled for this field in the nested model
                    if (is_array($nested_class::$_meta['cachedLists'])
                        && array_search($oneToManyFK, $nested_class::$_meta['cachedLists']) !== false) {
                        $useCachedList = true;
                        // remove spaces from where so it's a better cache key
                        $cachedListKey = "list:" . str_replace(' ', '', $where);
                    }

                    // TODO don't use $_GET['refresh'] here
                    if ($useCachedList && !$_GET['refresh']) {
                        elapsed("Using cached list $cachedListKey");
                        $list = mem($cachedListKey);
                    }
                    if ($list) {
                        elapsed("Lazy loaded mem($cachedListKey)");
                    } else {
                        elapsed("Lazy loaded $nested_class objects from DB");
                        $list = $nested_class::getList([
                            'where' => $where
                        ]);
                        if ($useCachedList) {
                            mem($cachedListKey, $list);
                        }
                    }
                    // convert the list to actual objects
                    foreach ($list as $id) {
                        $objects[] = $nested_class::get($id, [
                            'parent' => &$this
                        ]);
                    }
                }

                $this->_data->$property = $objects;
                return $objects;

            } else {

                elapsed('one-to-one');

                // lazy load the single object
                $aql = static::meta('aql');
                $field = $lazyMetadata['fk'];
                if (!$field) {
                    // the foreign key was not explicitly set in the aql, so we need to
                    // determine which field in this table identifies the foreign record
                    $model = $lazyMetadata['model'];
                    $class = static::getNamespacedModelName($model);
                    $field = $class::getPrimaryTable() . AQL\Block::FOREIGN_KEY_SUFFIX;
                }
                $object = null;
                $value = $this->$field;

                if ($value) {
                    $foreign_key = static::getForeignKey($property);
                    $object = new $nested_class($value, [
                        'parent' => &$this,
                        'parent_key' => $foreign_key
                    ]);
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
     * Gets all properties that can be saved
     * @return array
     */
    public static function getWritableProperties()
    {
        // aggregate all writable fields from the aql_array into a single list
        $aql = static::meta('aql');
        $readOnly = static::meta('readOnlyTables');
        $all_fields = array();
        foreach ($aql->blocks as $block) {
            if (is_array($block->fields)) {
                foreach ($block->fields as $f) {
                    $alias = $f['alias'];
                    $field = $f['field'];
                    if (is_array($readOnly) && array_search($block->alias, $readOnly)) {
                        continue;
                    }
                    $all_fields[$alias] = $field;
                }
            }
        }
        return $all_fields;
    }


    /**
     * @return string HTML dump of this object
     */
    public function __toString()
    {
        return @d($this);
    }

    /**
     * Gets all IDs with given criteria
     * @param array $criteria
     *                  - where
     *                  - order by
     *                  - limit
     *                  - offset
     * @param bool $do_count if true, returns count instead of ids
     * @return array list of IDs
     */
    public static function getList($criteria = [], $do_count = false)
    {
        $fn = \getList::getFn(static::getAQL());
        $ids = $fn($criteria, $do_count);
        return $ids;
    }


    /**
     * Gets the quantity of objects with the given criteria
     * @param array $criteria
     *                  - where
     *                  - order by
     *                  - limit
     *                  - offset
     * @return int
     */
    public static function getCount($criteria = [])
    {
        return static::getList($criteria, true);
        //return AQL::count(static::getAQL(), $criteria);
    }


    /**
     * Backwards compatibility alias for getCount()
     * @param array $criteria
     * @return int
     */
    public static function count($criteria)
    {
        return static::getCount($criteria);

    }


    /**
     * Gets the 1-to-1 nested objects
     * @return array property names
     */
    protected static function getOneToOneProperties()
    {
        $properties = array();
        $objects = static::meta('lazyObjects');
        if (is_array($objects)) {
            foreach ($objects as $property => $object_info) {
                if ($object_info['type'] != 'many') {
                    $properties[] = $property;
                }
            }
        }
        return $properties;
    }


    /**
     * Gets the 1-to-m nested objects
     * @return array property names
     */
    protected static function getOneToManyProperties()
    {
        $properties = array();
        $objects = static::meta('lazyObjects');
        if (is_array($objects)) {
            foreach ($objects as $property => $object_info) {
                if ($object_info['type'] == 'many') {
                    $properties[] = $property;
                }
            }
        }
        return $properties;
    }


    /**
     * Gets the foreign key in the nested object that links it to this object
     * TODO: needs to support foreign key aliases
     * @return string
     */
    public static function getOneToManyKey()
    {
        return static::getPrimaryTable() . AQL\Block::FOREIGN_KEY_SUFFIX;

    }


    /**
     * Gets the field that is used to instantiate the 1-to-1 nested object given the name
     * of a property that is a 1-to-1 nested object.
     * @param string $property the name of the property
     * @return string
     */
    public static function getForeignKey($property)
    {
        return static::$_meta['lazyObjects'][$property]['fk'];
    }


    /**
     * Gets the prepend the current namespace to the given model name
     * @param string $model name of the model
     * @return string
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
     * Adds an error to _errors if a required field is missing
     * @return Model $this
     */
    public function checkRequiredFields()
    {
        $required_fields = static::meta('requiredFields');
        if (is_array($required_fields)) {
            //d($this->_data);
            foreach ($required_fields as $field => $description) {
                if (!$this->_data->$field
                    && $this->_data->$field !== '0'
                    && $this->_data->$field !== 0
                    ) {
                    $this->addInternalError('required', array(
                        'message' => sprintf(self::FIELD_IS_REQUIRED, $description),
                        'fields' => array($field)
                    ));
                }
            }
        }
        return $this;
    }


    /**
     * Initializes commonly used static property values
     */
    public static function getMetadata()
    {
        // Make sure the class specifically defines public static $_meta.
        // Otherwise, metadata gets binded to the parent class which causes insanity.
        if (!is_array(static::$_meta)) {
            $class = get_called_class();
            throw new \Exception("public static \$_meta is not defined as array in $class.");
        }

        if (!static::meta('aql')) {

            $aql = new AQL(static::getAQL());

            // identify the lazy objects in each block
            // if it is a one-to-one object, then make sure the foreign key is in
            // the block's fields array so we get the foreign key value for the object
            foreach ($aql->blocks as $i => $table) {
                $objects = $table->objects;
                if (is_array($objects)) {
                    foreach ($objects as $object) {
                        $model = $object['model'];
                        $alias = $object['alias'] ?: $model;
                        $ns_model = static::getNamespacedModelName($model);
                        if ($object['type'] == 'one') {
                            elapsed($model . ' is one-to-one object.');
                            $field = $object['fk'];
                            if (!$field) {
                                $field = $ns_model::getPrimaryTable()
                                         . AQL\Block::FOREIGN_KEY_SUFFIX;
                            }
                            $full_field = $table->table . '.' . $field;
                            $fk_field = [
                                'field' => $full_field,
                                'alias' => $field
                            ];
                            $aql->blocks[$i]->fields[] = $fk_field;
                        }
                        static::$_meta['lazyObjects'][$alias] = $object;
                    }
                }
            }

            // set aql_array
            static::meta('aql', $aql);

            static::$_meta['primary_table'] = $aql->blocks[0]->table;

            // set called class
            static::meta('class', get_called_class());

            //elapsed('done getMetadata');
        }
    }


    /**
     * Gets the primary table of the model
     * @return string
     */
    public static function getPrimaryTable()
    {
        static::getMetadata();
        return static::meta('primary_table');
    }


    /**
     * Gets this object's encrypted ID
     * @return string
     */
    public function getIDE()
    {
        return $this->ide;
    }


    /**
     * Adds a placeholder for each nested object
     */
    public function getNestedObjects()
    {
        // add a placeholder message for each of the lazy objects
        $lazyObjects = static::meta('lazyObjects');
        if (is_array($lazyObjects)) {
            foreach ($lazyObjects as $alias => $object) {
                if ($object['type'] == 'many') {
                    $this->_data->$alias = self::LAZY_OBJECTS_MESSAGE;
                } else {
                    $this->_data->$alias = self::LAZY_OBJECT_MESSAGE;
                }
            }
        }
    }


    /**
     * Gets a config value, or if there is a second parameter value, sets the config value
     * @param string $key the name of the config option to get/set
     * @param mixed $value the value of the config option to set
     */
    public static function meta($key, $value = '§k¥')
    {
        if ($value == '§k¥') {
            // read
            $value = static::$_meta[$key];
            return $value;
        } else {
            // write
            static::$_meta[$key] = $value;
        }
    }


    /**
     * Gets the cache key for an instance of an object
     * @param int $id the ID of the object
     * @return string
     * @global $db_name
     */
    public static function getCacheKey($id)
    {
        global $db_name;

        if (!$id) {
            return null;
        }

        $mod_time = static::meta('mod_time');

        return get_called_class() . ':' . $id . ':' . $db_name . ':' . $mod_time;
    }


    /**
     * Starts a database transaction and starts a memcache transaction
     * @return Model $this
     */
    protected function beginTransaction()
    {
        // if this is the first begin
        // take a snapshot of the data in case we need to rollback
        #if (!$this->_nested) {
        if (AQL::getTransactionCounter() == 0) {
            $this->_revert = static::deepClone($this->_data);
        }

        AQL::begin();
        \Sky\Memcache::begin();

        return $this;
    }


    /**
     * Clones an object and all of its nested objects
     * @param object $object
     * @return object
     */
    public static function deepClone($object) {
        if (!is_object($object)) {
            return $object;
        }
        $clone = clone $object;
        foreach ($object as $key => $value) {
            if (is_object($value)) {
                if ($key == '_parent') {
                    // don't deep clone the _parent reference, causes recursive loop
                    $clone->$key = $value;
                } else {
                    $clone->$key = static::deepClone($value);
                }
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
     * Refreshes all objects that were recently saved to ensure the data is up-to-date
     */
    protected function reloadSavedObjects()
    {
        elapsed(get_called_class() . '->reloadSavedObjects()');
        $mods = $this->getModifiedProperties();
        #d($mods);

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
        // reload and cache 1-to-many nested objects
        $objects = static::getOneToManyProperties();
        if (is_array($objects)) {
            foreach ($objects as $property) {
                if (is_array($mods->$property)) {
                    #d($mods->$property);
                    foreach ($mods->$property as $i => $object) {
                        // if this nested one-to-many object has at least 1 modified field
                        #d($object);
                        if (count((array)$object)) {
                            #d($this->$property);
                            #d($property);
                            #d($i);
                            $this->{$property}[$i]->getDataFromDatabase();
                        }
                    }
                }
            }
        }
    }


    /**
     * Executes the afterCommit() method of every object that has been recently saved
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
     * Commits the database transaction and the memcache transaction and then executes
     * the afterCommit() methods
     * @return Model $this
     */
    protected function commitTransaction()
    {
        elapsed(get_called_class() . '->commitTransaction()');
        AQL::commit();
        \Sky\Memcache::commit();

        // if this is the final commit remove _revert property
        #if (!$this->_nested) {
        if (AQL::getTransactionCounter() == 0) {

            #d('not nested');
            #d($this);

            // discard the _revert data since we are not rolling back
            unset($this->_revert);

            // all data has been committed to the master database
            // traverse all saved objects and run afterCommit hooks
            $this->callAfterCommitHooks();

            // reload each object that was successfully saved
            // remove _modified, run contruct, and save to cache
            // this is here because afterCommit needs _modified
            $this->reloadSavedObjects();
        }

        return $this;
    }


    /**
     * Rolls back the database transaction and the memcache transaction, then reverts
     * the data back to what it was prior to the save()
     * @return Model $this
     */
    protected function rollbackTransaction()
    {
        elapsed(get_called_class() . '->rollbackTransaction()');

        AQL::rollBack();
        \Sky\Memcache::rollback();

        // get all the errors from nested objects because when we revert back we will
        // lose the error messages that occurred during the save
        $this->getChildErrors();

        // if this is the final rollback, revert the data back to what it was before save
        #if (!$this->_nested) {
        if (AQL::getTransactionCounter() == 0) {
            $this->_data = static::deepClone($this->_revert);
            unset($this->_revert);
        }

        return $this;
    }


    /**
     * Determines if an error has yet occurred during save()
     * @return bool
     */
    protected function isFailedTransaction()
    {
        $failed = count(AQL::$errors);
        if ($failed) {
            elapsed(get_called_class() . '->isFailedTransaction(): yes');
        }
        return $failed;
    }


    /**
     * Determines if the current state of this object is in a nested save.
     */
    public function isNestedSave()
    {
        if (AQL::getTransactionCounter() > 1) {
            return true;
        }
        return false;
    }


    /**
     * Gets the object in a format helpful for AJAX requests
     * @final
     * @return array
     */
    final public function getResponse()
    {
        if (count($this->_errors)) {
            return array(
                'status' => 'Error',
                'errors' => array_filter($this->getErrorMessages()),
                'debug' => $this->_errors,
                'data' => $this->dataToArray(true)
            );
        }
        // if no errors
        return array(
            'status' => 'OK',
            'data' => $this->dataToArray(true),
            '_token' => $this->getToken()
        );
    }


    /**
     * Gets the error messages
     * @return array
     */
    protected function getErrorMessages()
    {
        return array_map(function($e) {
            return (is_string($e)) ? $e : $e->message;
        }, $this->_errors);
    }



    /**
     * Gets the data in array format
     * @param bool $hideIds if true, removes "_id" fields (keep _ide)
     * @return array
     */
    public function dataToArray($hideIds = false)
    {
        return self::objectToArray($this, $hideIds);
    }


    /**
     * Converts an object to an array recursively
     * @param object $obj the object to convert to an array
     * @param bool $hideIds if true, removes "_id" fields (keep _ide)
     * @return array
     */
    public static function objectToArray($obj, $hideIds = false)
    {
        if (!is_object($obj)) {
            return $obj;
        }
        if (is_a($obj, '\Sky\Model\AQLModel')) {
            $obj = $obj->_data;
            // in case the nested object has no data (i.e. not found error)
            if (!$obj) {
                $obj = [];
            }
        }
        $array = array();
        foreach ($obj as $property => $value) {
            if ($hideIds) {
                if ($property == 'id') continue;
                if (substr($property,-3) == AQL\Block::FOREIGN_KEY_SUFFIX) continue;
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
     * One-to-many subobject specific "array_map", because these are not arrays
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
        if (static::$_meta['lazyObjects'][$name]['type'] != 'many') {
        #if (!$this->isPluralObject($name)) {
            $e = 'mapSubObjects expects a valid one-to-many object param.';
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
        elapsed(get_called_class() . '->getIDByRequiredFields()');

        # if there are errors | have ID | no required fields return
        if ($this->_errors || $this->getID() || !$this->hasRequiredFields()) {
            return $this;
        }

        # set up
        $where = array();
        $clause = array('limit' => 1, 'where' => &$where);
        $aql = sprintf('%s { }', static::getPrimaryTable());
        $key = static::getPrimaryTable() . AQL\Block::FOREIGN_KEY_SUFFIX;

        # make where
        $rf = $this->getRequiredFields();
        //d($rf);
        foreach ($rf as $f) {
            if ($this->$f == static::FOREIGN_KEY_VALUE_TBD) {
                return $this;
            }
            $where[] = sprintf("%s = '%s'", $f, $this->{$f});
        }

        $rs = AQL::select($aql, $clause);
        $r = $rs[0];
        if ($r->$key) {
            $this->$key = $r->$key;
        }
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
    public function addProperty($property)
    {
        return $this;
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
            $cl = get_called_class();
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
                throw new \Exception('Paramter is an empty object');
            }

            return $id;
        }

        $cl = get_called_class();
        $tmp = new $cl;
        $tbl = $tmp->getPrimaryTable();

        $id = decrypt($o, $tbl);
        if (!$id) {
            throw new \Exception('ID not found.');
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
                throw new \Exception('Parameter is an empty object.');
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
            throw new \Exception('IDE not found.');
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
