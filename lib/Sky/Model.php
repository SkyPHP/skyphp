<?php

namespace Sky;

/**
 *
 */
class Model
{

    /**
     * @var string
     */
    const E_FIELD_IS_REQUIRED = '%s is required.';

    /**
     * @var object
     */
    private $_data = null;

    /**
     * @var array
     */
    protected static $_meta = array();

    /**
     * Array of possible internal errors
     * Same format as possible errors
     * @var array
     */
    private static $internal_errors = array(
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
        if (!$params['refresh'] && !$_GET['refresh']) {
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
            $this->getSubObjects();
            $this->callIfExists('construct');
            $this->saveDataToCache();
            // don't show the cache time since this is not cached data
            unset($this->_data->_cache_time);
            return;
        }
    }

    /**
     * Set multiple properties
     */
    public function set($data)
    {
        #$previous_state = $this->_data;

        if (is_array($data) || is_object($data)) {
            foreach ($data as $property => $value) {
                $this->_data->$property = $value;
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
     * Instantiate an object by id
     */
    public static function get($id, $params = null)
    {
        $class = get_called_class();
        $obj = new $class($id, $params);
        return $obj;
    }

    /**
     *
     */
    public static function getDataFromCache($id)
    {
        $value = mem(static::getCacheKey($id));
        // todo: check if expired
        return $value;
    }

    /**
     *
     */
    public function getDataFromDatabase($id, $params = null)
    {
        $aql = static::meta('aql');
        $primary_table = static::meta('primary_table');
        $read_from_master = $params['dbw'] ? true : false;
        $clause = array(
            'where' => "$primary_table.id = $id"
        );
        $rs = \aql::select($aql, $clause, null, null, $read_from_master);
        return (object) $rs[0];
    }

    /**
     *
     */
    public function saveDataToCache()
    {
        $id = $this->getId();
        $this->_data->_cache_time = date('c');
        return mem(static::getCacheKey($id), $this->_data);
    }

    /**
     *
     */
    public function saveDataToDatabase()
    {

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
        $lazyLoad = static::$_meta['lazyObjects'][$property];
        if ($lazyLoad && is_string($this->_data->$property)) {
            //d($this->_data->$property);
            $model = $property;
            $nested_class = static::getNamespacedModelName($model);
            // get the nested object(s)
            if ($lazyLoad['plural']) {
                $id = $class::getPrimaryTable() . '_id';
                $needle = '{$' . $id . '}';
                $haystack = $this->getId();
                $where = str_replace($needle, $haystack, $lazyLoad['sub_where']);
                $objects = $nested_class::getMany(array(
                    'where' => $where
                ));
                $this->_data->$model = $objects;
                return $objects;
            } else {
                $table = $nested_class::getPrimaryTable();
                $field = "{$table}_id";
                $object = new $nested_class($this->$field);
                $this->_data->$model = $object;
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

        $this->_data->$property = $value;

        $method_name = 'validate_' . $property;
        if (method_exists($this, $method_name)) {
            $this->removePropertyErrors($property);
            $this->callIfExists($method_name);
        }
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
    public function save()
    {
        $this->saveDataToDatabase();
        // save to cache if db save was successful
        if (!$this->_db_errors) {
            $this->saveDataToCache();
        }

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
        $required_fields = static::meta('required');
        if (is_array($required_fields)) {
            foreach ($required_fields as $field => $description) {
                if (!isset($this->_data->$field) || $this->_data->$field === '') {
                    $this->addInternalError('field_is_required', array(
                        'message' => sprintf(self::E_FIELD_IS_REQUIRED, $description),
                        'fields' => array($field)
                    ));
                }
            }
        }
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
     * Gets a ValidationError object for the given $error_code
     * if it is found in static::$possible_errors || static::$internal_errors
     * @param  string  $code
     * @param  array   $params
     * @param  Boolean $internal
     * @return ValidationError
     * @throws \Exception       if error_code is not found
     */
    public static function getError($code, $params = array(), $internal = false)
    {
        $errors = ($internal) ? self::$internal_errors : static::meta('possible_errors');
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
    public function getId()
    {
        $primary_table = static::meta('primary_table');
        $field = $primary_table . '_id';
        $field_ide = $field . 'e' ;

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
                    foreach ($objects as $object) {
                        $model = $object['model'];
                        $ns_model = static::getNamespacedModelName($model);
                        if (!$object['plural']) {
                            $field = $ns_model::getPrimaryTable() . '_id';
                            $full_field = $table['table'] . '.' . $field;
                            $aql_array[$i]['fields'][$field] = $full_field;
                        }
                        static::$_meta['lazyObjects'][$model] = $object;
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
        $lazy_objects = static::meta('lazyObjects');
        if (is_array($lazy_objects)) {
            foreach ($lazy_objects as $object) {
                $model_name = $object['model'];
                $val = "This object will be loaded on demand";
                if ($object['plural']) {
                    $val = "This array of objects will be loaded on demand";
                }
                $this->$model_name = "[$val]";
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

}
