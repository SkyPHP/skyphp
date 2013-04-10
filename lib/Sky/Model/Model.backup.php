<?php

namespace Sky;

/*

TODO

- make sure aql::select doesn't return models (the wrong kind of models)

- class Model extends AQLModel which extends abstract PHPModel

- detect changes in the aql so you don't need schemaModificationTime

- $this->_modified logs changed values so we don't save things that weren't modified

- cross-namespace lazy objects
    person {
        [\Cms\Model\blog_article]s
    }

- cache conflicts with same model name in different namespaces

- make all methods final

- check for max length errors and catch them gracefully

*/

/**
 *
 */
class Model
{

    /**
     * @var string
     */
    const E_FIELD_IS_REQUIRED = '%s is required.';
    const LAZY_OBJECTS_MESSAGE = '[This array of objects will be loaded on demand]';
    const LAZY_OBJECT_MESSAGE = '[This object will be loaded on demand]';

    /**
     * @var object
     */
    private $_data = null;

    /**
     * @var array
     */
    protected static $_meta = array(
        'aql' => "",
        'schemaModificationTime' => null,
        'cachedLists' => array(),
        'possibleErrors' => array(),
        'requiredFields' => array(),
        'readOnlyProperties' => array(),
        'readOnlyTables' => array(),
        'testMode' => false
    );

    /**
     * Array of possible internal errors
     * Same format as possible errors
     * @var array
     */
    private static $_internal_errors = array(
        'read_only' => array(
            'type' => 'fatal',
            'message' => 'The site is currently in "read only" mode. Changes have not been saved. Try again later.'
        ),
        'field_is_required' => array(
            'type' => 'required'
        )
    );


    #public abstract function beforeCheckRequiredFields();

    #public abstract function validate();

    #public abstract function beforeInsert();

    #public abstract function beforeUpdate();

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

        // if we are pre-populating a new object with new data
        if (is_array($data) || is_object($data) || $data == null) {
            // instantiate a new object with the given data
            $this->set($data);
            if ($this->_errors) {
                //throw new \ValidationException($this->_errors);
            } else {
                $this->callIfExists('construct');
            }
            return;
        }

        // get the object by id
        $id = $data;

        $primary_table = $this->getPrimaryTable();

        // if the id is not numeric, decrypt it
        if (!is_numeric($id)) {
            $ide = $id;
            $id = \decrypt($ide, $primary_table);
        }
        if (!is_numeric($id)) {
            $this->_errors[] = "'$ide' cannot be decrypted to a valid ID.";
            return;
        }

        // determine if this object has already been loaded from db on this page load
        $class = get_called_class();

        if ($memoize[$class][$id] || (!$params['refresh'] && !$_GET['refresh'])) {
            $data = static::getDataFromCache($id);
            if ($data) {
                $this->_data = $data;
                $this->getSubObjects();
                return;
            }
        }

        // data is not in cache, get from database and then construct
        $data = $this->getDataFromDatabase($id, $params);
        if ($data) {
            $this->_data = $data;
            $idfield = $primary_table . '_id';
            $this->id = $this->_data->$idfield;
            $this->ide = encrypt($this->id, $primary_table);
            $this->getSubObjects();
            $this->callIfExists('construct');
            $this->saveDataToCache();
            // don't show the cache time since this is not cached data
            unset($this->_data->_cache_time);
            // memoize the fact that this object has been refreshed already in this thread
            $memoize[$class][$id] = true;
            return;
        }
    }

    /**
     * Set multiple properties
     */
    public function set($data)
    {
        #$previous_state = $this->_data;

        // TODO don't set the lazy objects until all of this object's properties are set

        if (is_object($data)) {
            $data = static::object_to_array($data);
        }

        if (is_array($data)) {

            // first check to see if an id is set, if so, fully load the object
            // then after original object is loaded set the modified properties
            $primary_table = $this->getPrimaryTable();
            $primary_id = $primary_table . '_id';
            $primary_ide = $primary_table . '_ide';

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
            }

            foreach ($data as $property => $value) {
                // if the property is a lazyObject
                $lazy = static::$_meta['lazyObjects'][$property];
                if ($lazy) {
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
                            $value[] = new $nested_class($val);
                        }
                    } else {
                        $value = new $nested_class($value);
                    }
                    $this->_data->$property = $value;
                } else {
                    $this->setValue($property, $value);
                }
            }
        }

        $this->runValidation();

        // invalid values, rollback the set
        if ($this->_errors) {
            #$this->_data = $previous_state;
        }

        return $this;
    }

    /**
     * make sure the related fields are set based on this field
     * for example apple_id --> apple_ide, id, ide
     *             banana_ide -> banana_id
     */
    public function IDify($property, $value)
    {
        $primary_table = $this->getPrimaryTable();
        $primary_id = $primary_table . '_id';
        $primary_ide = $primary_table . '_ide';

        if ($property == 'id') {
            $id = $value;
            $ide = \encrypt($value, $primary_table);
            #$this->id = $id;
            $this->ide = $ide;
            $this->$primary_id = $id;
            $this->$primary_ide = $ide;

        } else if ($property == 'ide') {
            $id = \decrypt($value, $primary_table);
            $ide = $value;
            $this->id = $id;
            #$this->ide = $ide;
            $this->$primary_id = $id;
            $this->$primary_ide = $ide;

        } else if (substr($property, -3) == '_id') {
            $table = substr($property, 0, -3);
            $field_id = $table . '_id';
            $field_ide = $table . '_ide';
            $id = $value;
            $ide = \encrypt($id, $table);
            #$this->$field_id = $id;
            $this->$field_ide = $ide;
            if ($table == $primary_table) {
                $this->id = $id;
                $this->ide = $ide;
            }

        } else if (substr($property, -4) == '_ide') {
            $table = substr($property, 0, -4);
            $field_id = $table . '_id';
            $field_ide = $table . '_ide';
            $id = \decrypt($value, $table);
            $ide = $value;
            $this->$field_id = $id;
            #$this->$field_ide = $ide;
            if ($table == $primary_table) {
                $this->id = $id;
                $this->ide = $ide;
            }
        }

        return $this;
    }


    /**
     * Instantiate an object by id
     */
    public static function get($id, $params = null)
    {
        $class = get_called_class();
        $obj = new $class($id, $params);
        return $obj;
    }

    /**
     * Check if an object exists by instantiating by id
     */
    public static function exists($id, $params = null)
    {
        $class = get_called_class();
        if ($class::get($id)->id) {
            return true;
        }
        return false;
    }

    /**
     *
     */
    public static function getDataFromCache($id)
    {
        $class = get_called_class();
        elapsed("$class::getDataFromCache($id)");
        $value = mem(static::getCacheKey($id));
        // todo: check if expired
        return $value;
    }

    /**
     *
     */
    public function getDataFromDatabase($id, $params = null)
    {
        elapsed(get_called_class() . "::getDataFromDatabase($id)");
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
        return (object) $rs[0];
    }


    /**
     * so far just here for backwards compatibility
     */
    public function isInsert()
    {
        if ($this->id) {
            return false;
        }
        return true;
    }

    /**
     * so far just here for backwards compatibility
     */
    public function isUpdate()
    {
        if ($this->id) {
            return true;
        }
        return false;
    }

    /**
     *
     * @param string $property
     * @return mixed
     */
    final public function __get($property)
    {
        // we only want this property if there actually is an error for aesthetic purposes
        // so if we are appending to $this->_errors array or checking for errors when
        // there are none then return empty array
        //if ($property == '_errors') {
        if (substr($property, 0, 1) == '_') {
            return null;
        }

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
                $class = get_called_class();
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
                        $objects[] = $nested_class::get($id);
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
                    $object = new $nested_class($this->$field);
                }
                $this->_data->$property = $object;
                return $object;
            }
        }
        return $this->_data->$property;
    }

    /**
     *
     * @param string $property
     * @return mixed
     */
    final public function __set($property, $value)
    {
        // we only want this property if there actually is an error for aesthetic purposes
        // so if we are setting an error for the first time, create the property
        //if ($property == '_errors') {
        if (substr($property, 0, 1) == '_') {
            $this->$property = $value;
            return;
        }

        $this->setValue($property, $value);

        $method_name = 'validate_' . $property;
        if (method_exists($this, $method_name)) {
            $this->removePropertyErrors($property);
            $this->callIfExists($method_name);
        }
    }

    /**
     *
     */
    private function setValue($property, $value)
    {
        if ($this->_data->$property != $value) {
            $this->initModifiedProperty();
            #$this->_modified->id = $this->_data->id;
            $this->_modified->$property = $this->_data->$property;
            $this->_data->$property = $value;
            $this->IDify($property, $value);
        }
        return $this;
    }

    /**
     * Get an object with given criteria
     */
    public static function getOne($criteria = array())
    {
        return static::getMany(array_merge($criteria, array(
            'limit' => 1
        )));
    }

    /**
     * Get many objects with given criteria
     */
    public static function getMany($criteria = array())
    {
        $rs = static::getList($criteria);
        foreach ($rs as $i => $id) {
            $rs[$i] = static::get($id);
        }
        return ($criteria['limit'] === 1) ? $rs[0] : $rs;
    }

    /**
     * Get many id's with given criteria
     */
    public static function getList($criteria = array(), $do_count = false)
    {
        $fn = \getList::getFn(static::meta('aql'));
        return $fn($criteria, $do_count);
    }

    /**
     * Get the number of objects with given criteria
     */
    public static function count($criteria = array())
    {
        return static::getList($criteria, true);
    }

    /**
     * Determine if an object exists for the given id
     */
    public static function idExists($id)
    {

    }

    /**
     *
     */
    public static function insert($data = null)
    {

    }

    /**
     *
     */
    public function update()
    {

    }

    /**
     *
     */
    private function saveDataToCache()
    {
        $id = $this->getID();
        $this->_data->_cache_time = date('c');
        return mem(static::getCacheKey($id), $this->_data);
    }

    /**
     * save this single object to the database
     */
    private function saveDataToDatabase()
    {
        // see github.com/SkyPHP/skyphp/issues/139

        // traverse aql_array
        // 1. determine the records to update
        // 2. determine the records to insert
        // 3. determine the order of inserts based on foreign key dependencies
        // validate
        // beforeinsert/update
        // savearray (only save values in _modified, and don't save readOnly fields)
        // - update records
        // - insert records
        // IDify
        // beforerefreshcache
        // afterinsert/update
        // refresh cachedLists
        // return this or throw new exception
    }

    /**
     * recursively save each object that has been modified
     */
    public function save()
    {
        $this->beginTransaction();

        elapsed('save ' . get_class($this));

        $mods = $this->getModifiedPropertiesRecursively();
        elapsed('mods');
        print_r($mods);

        $objects = static::meta('lazyObjects');
        elapsed('objects');
        print_r($objects);

        # 1. save 1-to-1 nested objects
        #       because we need the nested id to save into this object
        if (is_array($objects)) {
            foreach ($objects as $property => $object_info) {
                if (!$object_info['plural']) {
                    if (count($mods->$property)) {
                        $this->$property->save();
                        // put this id into the main object
                        $foreign_key = $object_info['primary_table'] . '_id';
                        $this->$foreign_key = $this->$property->id;
                    }
                }
            }
        }


        # 2. save this object
        #       save this objects's multiple tables in the correct order
        $this->saveDataToDatabase();


        # 3. save 1-to-many (plural) objects
                # because we need this object's id before we save nested plural objects
        if (is_array($objects)) {
            foreach ($objects as $property => $object_info) {
                if ($object_info['plural']) {
                    if (is_array($mods->$property)) {
                        foreach ($mods->$property as $i => $object) {
                            if (count($object)) {
                                $this->{$property}[$i]->save();
                            }
                        }
                    }
                }
            }
        }

        # 4. if all went well, commit transaction

        // save to cache if db save was successful
        if (!$this->_db_errors) {
            $this->commitTransaction();
        } else {
            $this->rollbackTransaction();
        }

    }

    /**
     *
     */
    private function beginTransaction()
    {
        // begin db and memcache transactions
    }

    /**
     *
     */
    private function commitTransaction()
    {

    }

    /**
     *
     */
    private function rollbackTransaction()
    {

    }

    /**
     *
     */
    public function getModifiedPropertiesRecursively()
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

        // filter out the non-writable fields
        $modified = new \stdClass;
        if (count($this->_modified)) {
            $modified->id = $this->_data->id;
            // check to make sure the modified field is in the aql and not read-only
            #$modified = (object) array_merge((array) $modified, (array) $this->_modified);
            foreach ($this->_modified as $alias => $value) {
                // if this is a field that will be saved
                if ($all_fields[$alias]) {
                    $modified->$alias = $value;
                }
            }
        }

        // now do the same for each nested object
        $objects = static::meta('lazyObjects');
        if (is_array($objects)) {
            foreach ($objects as $property => $object_info) {
                if ($object_info['plural']) {
                    if (is_array($this->_data->$property)) {
                        foreach ($this->_data->$property as $object) {
                            $modified->{$property}[] = $object->getModifiedPropertiesRecursively();
                        }
                    }
                } else {
                    if (is_subclass_of($this->_data->$property, get_class())) {
                        $modified->$property = $this->_data->$property->getModifiedPropertiesRecursively();
                    }
                }
            }
        }
        return $modified;
    }

    /**
     *
     */
    public function json()
    {

    }

    /**
     *
     */
    public function dump()
    {

    }

    /**
     *
     */
    public function __toString ()
    {
        return @d($this);
    }

    /**
     *
     */
    private static function getMethods($params = null)
    {
        $class = new \ReflectionClass(get_called_class());
        $methods = $class->getMethods();
        if ($params['starting with']) {
            foreach ($methods as $i => $method) {
                if (strpos($method->name, $params['starting with']) !== 0) {
                    unset($methods[$i]);
                }
            }
        }
        return $methods;
    }

    /**
     *
     */
    public function runValidation()
    {
        unset($this->_errors);

        $this->callIfExists('beforeCheckRequiredFields');

        $this->checkRequiredFields();

        // don't continue if required fields are missing
        if (count($this->_errors)) {
            return $this;
        }

        // run the property-specific validation methods
        $prefix = 'validate_';
        $validate_methods = $this->getMethods(array(
            'starting with' => $prefix
        ));
        foreach ($validate_methods as $validate_method) {
            // only run the property-specific validation method if the property is set
            $method = $validate_method->name;
            $start = strlen($prefix);
            $field = substr($method, $start);
            if (isset($this->_data->$field)) {
                $this->$method();
            }
        }

        $this->callIfExists('validate');
    }

    /**
     * Calls a method with the given arguments if it exists
     * @param  string  $method
     * @param  mixed   arguments to pass to this methdo
     * @return mixed
     */
    private function callIfExists($method /* ,... */)
    {
        if (!method_exists($this, $method)) {
            return null;
        }

        $args = func_get_args();
        $args = array_slice($args, 1);

        return call_user_func_array(array($this, $method), $args);
    }

    /**
     *
     */
    private function checkRequiredFields()
    {
        $required_fields = static::meta('requiredFields');
        if (is_array($required_fields)) {
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
     * Adds an internal error to the stack
     * @param  string  $error_code
     * @param  array   $params
     * @return Model   $this
     */
    protected function addInternalError($error_code, array $params = array())
    {
        $this->initErrorProperty();
        $error = static::getError($error_code, $params, true);
        $this->_errors[] = $error;
        return $this;
    }

    /**
     * @param   array   $errors
     * @return  $this
     */
    public function addErrors(array $errors = array())
    {
        $this->initErrorProperty();
        $this->_errors = array_merge($this->_errors, $errors);
        return $this;
    }

    /**
     * Adds an error to the stack ($this->_errors)
     * @param  string  $error_code
     * @param  array   $params
     * @return Model   $this
     */
    public function addError($error_code, $params = array())
    {
        $this->initErrorProperty();
        $error = static::getError($error_code, $params);
        $this->_errors[] = $error;
        return $this;
    }

    /**
     * It is necessary to initialize the _errors property in order to append or merge
     * elements to the array
     */
    private function initErrorProperty()
    {
        if (!$this->_errors) {
            $this->_errors = array();
        }
    }

    /**
     * It is necessary to initialize the _errors property in order to append or merge
     * elements to the array
     */
    private function initModifiedProperty()
    {
        if (!$this->_modified) {
            $this->_modified = new \stdClass;
        }
    }

    /**
     * Gets a ValidationError object for the given $error_code
     * if it is found in static::$possible_errors || static::$_internal_errors
     * @param  string  $code
     * @param  array   $params
     * @param  Boolean $internal
     * @return ValidationError
     * @throws \Exception       if error_code is not found
     */
    public static function getError($code, $params = array(), $internal = false)
    {
        $errors = ($internal) ? self::$_internal_errors : static::meta('possibleErrors');
        if (!is_array($errors)) $errors = array();
        if (!is_string($code)
            || !array_key_exists($code, $errors)
            || !is_array($errors[$code])
        ) {
            throw new \Exception('Invalid error_code.');
        }

        # merge the predefined properties of this error_code with the specified params
        $error_params = array_merge($errors[$code], $params);
        return new \ValidationError($code, $error_params);
    }

    /**
     * Stops execution of the method and throws ValidationException with all errors
     * that have been added to the error stack.
     * @param  mixed   $a      Either a string $error_code,
     *                         Error object, or an array of error objects
     * @param  array   $params Optional array for customizing the error output
     * @throws \ValidationException
     */
    public static function error($a, $params = array())
    {
        if (is_array($a)) {
            $errors = $a;
        } elseif (is_string($a)) {
            $error_code = $a;
            $errors = array(static::getError($error_code, $params));
        } elseif (is_a($a, 'ValidationError')) {
            $error = $a;
            $errors = array($error);
        }

        throw new \ValidationException($errors);
    }

    /**
     *
     */
    private function removePropertyErrors($property = null)
    {
        if (!$property) return;
        if (is_array($this->_errors)) {
            foreach ($this->_errors as $i => $error) {
                if ($error->fields == array($property) || $error->field == $property) {
                    unset($this->_errors[$i]);
                }
            }
            $this->_errors = array_values($this->_errors);
            if (!count($this->_errors)) {
                unset($this->_errors);
            }
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

        return get_called_class() . ':' . $id . ':' . $db_name . ':' . $mod_time;
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
        $primary_table = $this->getPrimaryTable();
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
     *
     */
    public static function getMetadata()
    {
        // get aql array if we don't already have it
        if (!static::meta('aql_array')) {

            $aql_array = \aql2array(static::meta('aql'));
            // identify the lazy objects
            foreach ($aql_array as $i => $table) {
                $objects = $table['objects'];
                if (is_array($objects)) {
                    foreach ($objects as $alias => $object) {
                        $model = $object['model'];
                        $ns_model = static::getNamespacedModelName($model);
                        if (!$object['plural']) {
                            //$field = $ns_model::getPrimaryTable() . '_id';
                            $field = $object['constructor argument'];
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
        }
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
     *
     */
    public function getSubObjects()
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
    public static function getPrimaryTable()
    {
        static::getMetadata();
        return static::meta('primary_table');
    }


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


    #################################################################################
    #  Backwards Compatibility                                                      #
    #################################################################################

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
        # if there are errors | have ID | no required fields return
        if ($this->_errors || $this->getID() || !$this->hasRequiredFields()) {
            return $this;
        }

        # set up
        $where = array();
        $clause = array('limit' => 1, 'where' => &$where);
        $aql = sprintf('%s { }', $this->getPrimaryTable());
        $key = $this->getPrimaryTable() . '_id';

        # make where
        foreach ($this->getRequiredFields() as $f) {
            $where[] = sprintf("%s = '%s'", $f, $this->{$f});
        }

        $rs = \aql::select($aql, $clause);
        $this->{$key} = ($rs[0][$key]) ?: $this->{$key};
        $this->_token = ($this->_token) ?: $this->getToken();

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
     * @param string $primary_table    primary_table, default: $this->getPrimaryTable()
     * @return mixed                   string or null
     */
    public function getToken($id = null, $primary_table = null)
    {
        $primary_table = ($primary_table) ?: $this->getPrimaryTable();
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
     * @throws ModelNotFoundException if cannot find the model
     */
    public static function convertToObject($o)
    {
        if (self::isModelClass($o)) {
            return $o;
        }

        $cl = get_called_class();
        $obj = new $cl($o);

        if (!$obj->getID()) {
            throw new ModelNotFoundException('Model object not found');
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
        if (!is_object($class) &&
            (is_numeric($class) || (is_string($class) && !trim($class)))
        ) {
            return false;
        }

        try {
            $ref = new \ReflectionClass($class);
            return $ref->isSubclassOf('\Sky\Model');
        } catch (ReflectionException $e) {
            return false;
        }
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
    /**
     * returns $this->_data in array form
     * we use ModelArrayObjects instead of arrays and these need to get converted back
     *
     * @param  Boolean     $hide_ids   if true, remove "_id" fields (keep _ides)
     *                                 default false
     * @return array
     */
    public function dataToArray($hide_ids = false)
    {
        // TODO add support for hide_ids
        return self::object_to_array($this->_data);
    }

    /**
     * convert object to array recursively
     */
    public static function object_to_array($obj)
    {
        $arrObj = is_object($obj) ? get_object_vars($obj) : $obj;
        foreach ($arrObj as $key => $val) {
                $val = (is_array($val) || is_object($val)) ? $this->object_to_array($val) : $val;
                $arr[$key] = $val;
        }
        return $arr;
    }

    /**
     *
     */
    public function getModelName()
    {
        return get_class($this);
    }
}
