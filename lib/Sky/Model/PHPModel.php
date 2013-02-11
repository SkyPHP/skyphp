<?php

namespace Sky\Model;

/**
 *  $this->_data
 *  $this->_modified
 *  $this->_errors
 */
abstract class PHPModel implements iModel
{

    /**
     * @var string
     */
    const E_FIELD_IS_REQUIRED = '%s is required.';
    const LAZY_OBJECTS_MESSAGE = '[This array of objects will be loaded on demand]';
    const LAZY_OBJECT_MESSAGE = '[This object will be loaded on demand]';
    const VALIDATION_METHOD_PREFIX = 'validate_';
    const ID_FIELD = 'id';

    /**
     * @var object
     */
    protected $_data = null;

    /**
     * Array of possible internal errors
     * Same format as possible errors
     * @var array
     */
    protected static $_internalErrors = array(
        'read_only' => array(
            'message' => 'The site is currently in "read only" mode. Try again later.'
        ),
        'field_is_required' => array(),
        'database_error' => array()
    );

    /**
     *
     * @param string $property
     * @return mixed
     */
    public function __get($property)
    {
        // we only want this property if there actually is an error for aesthetic purposes
        // so if we are appending to $this->_errors array or checking for errors when
        // there are none then return empty array
        //if ($property == '_errors') {
        if (substr($property, 0, 1) == '_') {
            return null;
        }

        // get the lazy loaded property value, fallback to the _data value we already have
        return $this->lazyLoadProperty($property) ?: $this->_data->$property;
    }

    /**
     *
     * @param string $property
     * @return mixed
     */
    public function __set($property, $value)
    {
        // we only want this property if there actually is an error for aesthetic purposes
        // so if we are setting an error for the first time, create the property
        //if ($property == '_errors') {
        if (substr($property, 0, 1) == '_') {
            $this->$property = $value;
            return;
        }

        $this->setValue($property, $value);

        // run validation only for this property
        $propertyValidationMethod = static::VALIDATION_METHOD_PREFIX . $property;
        if (method_exists($this, $propertyValidationMethod)) {
            $this->removePropertyErrors($property);
            $this->callMethod($propertyValidationMethod);
        }
    }

    /**
     *
     */
    protected function setValue($property, $value)
    {
        if ($this->_data->$property != $value) {
            $this->initModifiedProperty();
            $this->_modified->$property = $this->_data->$property;
            $this->_data->$property = $value;
            $this->afterSetValue($property, $value);
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
        if ($class::get($id)->getID()) {
            return true;
        }
        return false;
    }

    /**
     *
     */
    public static function insert($data = null)
    {
        $o = new static($data);
        return $o->save();
    }

    /**
     *
     */
    public function update($data)
    {
        $this->set($data);
        $this->save();
    }

    /**
     *
     */
    public static function getDataFromCache($id)
    {
        elapsed(static::meta('class') . "::getDataFromCache($id)");

        $value = static::cacheRead(static::getCacheKey($id));
        // todo: check if expired
        return $value;
    }


    /**
     *
     */
    protected function saveDataToCache()
    {
        $id = $this->getID();
        elapsed(static::meta('class') . "->saveDataToCache($id)");
        $this->_data->_cache_time = date('c');
        return $this->cacheWrite(static::getCacheKey($id), $this->_data);
    }


    /**
     * so far just here for backwards compatibility
     */
    public function isInsert()
    {
        if ($this->getID()) {
            return false;
        }
        return true;
    }

    /**
     * so far just here for backwards compatibility
     */
    public function isUpdate()
    {
        if ($this->getID()) {
            return true;
        }
        return false;
    }

    /**
     * recursively save each object that has been modified
     */
    public function save()
    {
        $this->beginTransaction();

        if ($this->isInsert) {
            $is_insert = true;
            $this->beforeInsert();
        } else {
            $this->beforeUpdate();
        }

        $this->runValidation();

        // stop if the validation added an error or if the validation caused a db error
        if ($this->_errors || $this->isFailedTransaction()) {
            return $this->rollbackTransaction();
        }

        $mods = $this->getModifiedProperties();

        // save 1-to-1 nested objects
        // because we need the nested id to save into this object
        $objects = static::getOneToOneProperties();
        if (is_array($objects)) {
            foreach ($objects as $property) {
                // if this nested object has at least 1 modified field
                if (count((array)$mods->$property)) {


                    // stop if there's a problem instantiating a nested object
                    if ($this->isFailedTransaction()) {
                        return $this->rollbackTransaction();
                    }

                    $this->$property->save();

                    // stop if there's a problem saving a nested object
                    if ($this->isFailedTransaction()) {
                        return $this->rollbackTransaction();
                    }

                    // put this id into the main object
                    $foreign_key = static::getForeignKey($property);
                    $this->$foreign_key = $this->$property->getID();
                }
            }
        }

        // save this object's table (and joined tables) in the correct order
        $this->saveDataToDatabase();

        // stop if there is a problem saving this object to the database
        if ($this->isFailedTransaction()) {
            return $this->rollbackTransaction();
        }

        // save 1-to-many nested objects
        // because we need this object's id before we save nested plural objects
        $objects = static::getOneToManyProperties();
        if (is_array($objects)) {
            foreach ($objects as $property) {
                if (is_array($mods->$property)) {
                    foreach ($mods->$property as $i => $object) {
                        // if this nested one-to-many object has at least 1 modified field
                        if (count((array)$object)) {
                            $field = static::getOneToManyKey();
                            $this->{$property}[$i]->$field = $this->getID();


                            // stop if there's a problem instantiating a nested object
                            if ($this->isFailedTransaction()) {
                                return $this->rollbackTransaction();
                            }

                            $this->{$property}[$i]->save();

                            // stop if there is a problem saving nested objects
                            if ($this->isFailedTransaction()) {
                                return $this->rollbackTransaction();
                            }
                        }
                    }
                }
            }
        }

        if ($is_insert) {
            $this->afterInsert();
        } else {
            $this->afterUpdate();
        }

        $this->refreshCachedLists();

        // final check to see if anything went wrong before committing the transaction
        if ($this->_errors || $this->isFailedTransaction()) {
            return $this->rollbackTransaction();
        }

        $this->commitTransaction();

        return $this;

    }

    /**
     *
     */
    protected function beginTransaction()
    {
        // begin db and memcache transactions
    }

    /**
     *
     */
    protected function commitTransaction()
    {

    }

    /**
     *
     */
    protected function rollbackTransaction()
    {

    }

    /**
     *
     */
    public function getModifiedProperties()
    {
        $writable = static::getWritableProperties();

        // filter out the non-writable fields
        $modified = new \stdClass;
        if (count((array)$this->_modified)) {
            $id_field = static::ID_FIELD;
            $modified->$id_field = $this->getID();
            // check to make sure the modified field is in the aql and not read-only
            #$modified = (object) array_merge((array) $modified, (array) $this->_modified);
            foreach ($this->_modified as $alias => $value) {
                // if this is a field that is writable
                if ($writable[$alias]) {
                    $modified->$alias = $value;
                }
            }
        }

        // now do the same for each nested object

        $objects = static::getOneToOneProperties();
        if (is_array($objects)) {
            foreach ($objects as $property) {
                if (is_subclass_of($this->_data->$property, get_class())) {
                    $modified->$property = $this->_data->$property->getModifiedProperties();
                }
            }
        }

        $objects = static::getOneToManyProperties();
        if (is_array($objects)) {
            foreach ($objects as $property) {
                if (is_array($this->_data->$property)) {
                    foreach ($this->_data->$property as $object) {
                        $modified->{$property}[] = $object->getModifiedProperties();
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
    protected static function getValidationMethods($params = null)
    {
        $method_prefix = static::VALIDATION_METHOD_PREFIX;
        $class = new \ReflectionClass(get_called_class());
        $methods = $class->getMethods();
        foreach ($methods as $i => $method) {
            if (strpos($method->name, $method_prefix) !== 0) {
                unset($methods[$i]);
            }
        }
        return $methods;
    }

    /**
     *
     */
    public function runValidation()
    {
        elapsed(static::meta('class') . '->runValidation()');

        unset($this->_errors);

        $this->callMethod('beforeCheckRequiredFields');

        $this->checkRequiredFields();

        // don't continue if required fields are missing
        if (count($this->_errors)) {
            return $this;
        }

        // run the property-specific validation methods
        $validation_methods = $this->getValidationMethods();
        foreach ($validation_methods as $validation_method) {
            // only run the property-specific validation method if the property is set
            $method = $validation_method->name;
            $start = strlen($prefix);
            $field = substr($method, $start);
            if (isset($this->_data->$field)) {
                $this->$method();
            }
        }

        // run the validate() method if it is defined
        $this->callMethod('validate');
    }

    /**
     * Calls a method with the given arguments if it exists
     * @param  string  $method
     * @param  mixed   arguments to pass to this methdo
     * @return mixed
     */
    protected function callMethod($method /* ,... */)
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
    protected function removePropertyErrors($property = null)
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
     * It is necessary to initialize the _errors property in order to append or merge
     * elements to the array
     */
    protected function initErrorProperty()
    {
        if (!$this->_errors) {
            $this->_errors = array();
        }
    }

    /**
     * It is necessary to initialize the _errors property in order to append or merge
     * elements to the array
     */
    protected function initModifiedProperty()
    {
        if (!$this->_modified) {
            $this->_modified = new \stdClass;
        }
    }


    public function beforeCheckRequiredFields()
    {

    }

    public function validate()
    {

    }

    public function beforeInsert()
    {

    }

    public function beforeUpdate()
    {

    }

    public function afterInsert()
    {
        elapsed(static::meta('class') . ' afterInsert()');
    }

    public function afterUpdate()
    {
        elapsed(static::meta('class') . ' afterUpdate()');
    }

    public function afterCommit()
    {
        elapsed(static::meta('class') . ' afterCommit()');
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
     * Gets a ValidationError object for the given $error_code
     * if it is found in static::$possible_errors || static::$_internalErrors
     * @param  string  $code
     * @param  array   $params
     * @param  Boolean $internal
     * @return ValidationError
     * @throws \Exception       if error_code is not found
     */
    public static function getError($code, $params = array(), $internal = false)
    {
        $errors = ($internal) ? self::$_internalErrors : static::meta('possibleErrors');
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




}



