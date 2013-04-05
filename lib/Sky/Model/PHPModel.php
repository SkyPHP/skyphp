<?php

namespace Sky\Model;

/**
 * An abstract implementation of a Model with the following features:
 *
 *  - Lazy loading of 1-to-1 nested objects and 1-to-many nested objects
 *  - Real-time validation of current data state
 *  - Cached objects
 *  - Cached lists of 1-to-many nested object IDs
 *  - CRUD operations
 *  - Required fields, read-only properties,
 *  - Only save modified properties to the database to minimize multi-user interference
 *  - Use transactions when saving multiple objects
 */
abstract class PHPModel implements PHPModelInterface
{

    const FIELD_IS_REQUIRED = '%s is required.';
    const LAZY_OBJECTS_MESSAGE = '[This array of objects will be loaded on demand]';
    const LAZY_OBJECT_MESSAGE = '[This object will be loaded on demand]';
    const VALIDATION_METHOD_PREFIX = 'validate_';
    const ID_FIELD = 'id';
    const FOREIGN_KEY_VALUE_TBD = '[This value will be determined on save]';


    /**
     * The data contained within the object
     * @var object
     */
    protected $_data = null;


    /**
     * Configuration array for this model
     *
     *  schemaModificationTime
     *      cached data older than this time string is discarded
     *
     *  cachedLists
     *      array of field names whose unique values each correspond to a cached list of IDs
     *
     *  possibleErrors
     *      array of possible errors
     *      i.e. array('error_code' => array())
     *
     *  requiredFields
     *      array of required fields
     *      i.e. array('field_name' => 'Field name')
     *
     *  readOnlyProperties
     *      array of properties which are to be read-only
     *      (field names and/or nested object names)
     *
     *  readOnlyTables
     *      array of table names that are joined tables for which all of its fields are
     *      to be read-only
     *
     * @var array
     */
    protected static $_meta = array(
        'schemaModificationTime' => null,
        'cachedLists' => array(),
        'possibleErrors' => array(),
        'requiredFields' => array(),
        'readOnlyProperties' => array(),
        'readOnlyTables' => array()
    );


    /**
     * Array of possible internal errors
     * Same format as possible errors
     * @var array
     */
    protected static $_internalErrors = array(
        'read_only' => array(
            'message' => 'Currently in "read only" mode. Try again later.'
        ),
        'required' => array(),
        'not_found' => array(),
        'database_error' => array()
    );


    /**
     * Gets the value of a data property
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
     * Sets the value of a data property
     * @param string $property
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

        $this->validateProperty($property);
    }


    /**
     * Sets a property value and logs the original value that was modified.
     * Validation is purposely not executed in this method for performance reasons.
     * It is your responsibility to validateProperty() or runValidation() after calling
     * this method.
     * @param string $property
     * @param mixed $value
     * @return $this
     */
    protected function setValue($property, $value)
    {
        if (!$this->_data) {
            $this->_data = new \stdClass;
        }
        if ($this->_data->$property !== $value) {
            $this->initModifiedProperty();
            // only log the original value
            if (!property_exists($this->_modified, $property)) {
                $this->_modified->$property = $this->_data->$property;
            }
            $this->_data->$property = $value;
            $this->afterSetValue($property, $value);
        }
        return $this;
    }


    /**
     * Gets one object with given criteria
     * @param array $criteria
     *                  - where
     *                  - order by
     *                  - limit
     *                  - offset
     * @return Model
     */
    public static function getOne($criteria = array())
    {
        return static::getMany(array_merge($criteria, array(
            'limit' => 1
        )));
    }


    /**
     * Gets many objects with given criteria
     * @param array $criteria
     *                  - where
     *                  - order by
     *                  - limit
     *                  - offset
     * @return array
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
     * Gets an object by ID
     * @param mixed $id the id of the object to get
     * @param array
     * @return object
     */
    public static function get($id, $params = null)
    {
        return new static($id, $params);
    }


    /**
     * Determine if an object exists with the given ID
     * @param mixed $id
     * @return bool
     */
    public static function exists($id)
    {
        if (static::get($id)->getID()) {
            return true;
        }
        return false;
    }


    /**
     * Inserts a new object into the database with the given data
     * @param array|object $data
     * @return object
     */
    public static function insert($data)
    {
        $o = new static($data);
        return $o->save();
    }


    /**
     * Updates an existing object in the database with the given data
     * @param array|object $data
     * @return $this
     */
    public function update($data)
    {
        $this->set($data);
        $this->save();
        return $this;
    }


    /**
     * Gets an object's data with the given ID from the cache
     * @param int $id
     * @return mixed
     */
    public static function getDataFromCache($id)
    {
        elapsed(static::meta('class') . "::getDataFromCache($id)");
        $value = static::cacheRead(static::getCacheKey($id));
        // todo: check if expired
        return $value;
    }


    /**
     * Saves the object's data to the cache
     */
    protected function saveDataToCache()
    {
        $id = $this->getID();
        elapsed(static::meta('class') . "->saveDataToCache($id)");
        $this->_data->_cache_time = date('c');
        return $this->cacheWrite(static::getCacheKey($id), $this->_data);
    }


    /**
     * Determines if this object does not yet exist in the database. Returns true if
     * save() will cause the object to be inserted into the database.
     * @return bool
     */
    public function isInsert()
    {
        $id = $this->getID();
        if ($id) {
            return false;
        }
        return true;
    }


    /**
     * Determines if this object exists in the database. Returns true if save() will cause
     * the object to be updated in the database.
     * @return bool
     */
    public function isUpdate()
    {
        return !$this->isInsert();
    }


    /**
     * Saves the modified properties in the object as well as modifications to nested
     * objects into the database.
     * If any object has an error during validation, the entire save() transaction is
     * rolled back.  Executes beforeInsert() and/or beforeUpdate(), afterUpdate() and/or
     * afterInsert(). If the transaction is committed to the database successfully, the
     * afterCommit() hook is executed for each saved object.
     * @return $this
     */
    public function save()
    {
        $this->beginTransaction();

        // get the modified properties
        $mods = $this->getModifiedProperties();
        //elapsed(get_called_class());
        //d($mods);
        //d($this);

        // save 1-to-1 nested objects
        // because we need the nested id to save into this object
        $objects = static::getOneToOneProperties();
        if (is_array($objects)) {
            foreach ($objects as $property) {
                // if this nested object has at least 1 modified field
                #elapsed("mods $property");
                #d($mods->$property);
                if (count((array)$mods->$property)) {


                    // stop if there's a problem instantiating a nested object
                    if ($this->isFailedTransaction()) {
                        return $this->rollbackTransaction();
                    }

                    elapsed(static::meta('class') . '->' . $property . '->save();');
                    //d($this->$property);

                    $this->$property->_nested = true;
                    $this->$property->save();
                    unset($this->$property->_nested);

                    // stop if there's a problem saving a nested object
                    if ($this->isFailedTransaction()) {
                        return $this->rollbackTransaction();
                    }

                    // this is redundant because we already updated using _parent_key
                    // put this id into the main object
                    // $foreign_key = static::getForeignKey($property);
                    // elapsed(static::meta('class') . '->' . $foreign_key . '=' . $this->$property->getID());
                    // $this->$foreign_key = $this->$property->getID();
                }
            }
        }

        // validate and save this object's properties
        $this->runValidation();

        // stop if the validation added an error or if the validation caused a db error
        if ($this->_errors || $this->isFailedTransaction()) {
            d($this->_errors);
            return $this->rollbackTransaction();
        }

        // before insert / before update
        $isInsert = $this->isInsert();
        if ($isInsert) {
            $this->callMethod('beforeInsert');
        } else {
            $this->callMethod('beforeUpdate');
        }

        // stop if the validation added an error or if the validation caused a db error
        if ($this->_errors || $this->isFailedTransaction()) {
            return $this->rollbackTransaction();
        }

        // save this object's table (and joined tables) in the correct order
        $this->saveDataToDatabase();

        // stop if there is a problem saving this object to the database
        if ($this->isFailedTransaction()) {
            return $this->rollbackTransaction();
        }

        // save 1-to-many nested objects
        // because we need this object's id before we save nested one-to-many objects
        $objects = static::getOneToManyProperties();
        if (is_array($objects)) {
            foreach ($objects as $property) {
                if (is_array($mods->$property)) {
                    //d($mods->$property);
                    foreach ($mods->$property as $i => $object) {
                        // if this nested one-to-many object has at least 1 modified field
                        if (count((array)$object)) {
                            $field = static::getOneToManyKey();
                            $this->{$property}[$i]->$field = $this->getID();


                            // stop if there's a problem instantiating a nested object
                            if ($this->isFailedTransaction()) {
                                return $this->rollbackTransaction();
                            }

                            elapsed(static::meta('class') . '->' . $property . '[' . $i . ']->save();');

                            $this->{$property}[$i]->_nested = true;
                            $this->{$property}[$i]->save();
                            unset($this->{$property}[$i]->_nested);

                            //d($this->{$property}[$i]);

                            // stop if there is a problem saving nested objects
                            if ($this->isFailedTransaction()) {
                                return $this->rollbackTransaction();
                            }
                        }
                    }
                }
            }
        }

        // remember from earlier if this was an insert since the new id has been created
        // so calling isInsert() will always be false at this point
        if ($isInsert) {
            $this->callMethod('afterInsert');
        } else {
            $this->callMethod('afterUpdate');
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
     * Gets the original values of the properties that have been modified.
     * @return stdClass
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
                #if ($writable[$property]) {
                    if (is_subclass_of($this->_data->$property, get_class())) {
                        $mods = $this->_data->$property->getModifiedProperties();
                        if (count((array)$mods)) {
                            $modified->$property = $mods;
                        }
                    }
                #}
            }
        }
        $objects = static::getOneToManyProperties();
        if (is_array($objects)) {
            foreach ($objects as $property) {
                #if ($writable[$property]) {
                    if (is_array($this->_data->$property)) {
                        foreach ($this->_data->$property as $object) {
                            $mods = $object->getModifiedProperties();
                            if (count((array)$mods)) {
                                $modified->{$property}[] = $mods;
                            }
                        }
                    }
                #}
            }
        }
        return $modified;
    }


    /**
     * Gets an array of field-specific validate methods.
     * @return array ReflectionMethod objects
     */
    protected static function getValidationMethods()
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
     * Executes every validation method.
     *
     *  $this->beforeCheckRequiredFields()
     *  $this->checkRequiredFields()
     *  $this->validate_myfield()
     *  $this->validate_myfield2()
     *  $this->validate()
     *
     * If $this->myfield is blank, skip the corresponding method $this->validate_myfield()
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
            $methodName = $validation_method->name;
            $start = strlen(static::VALIDATION_METHOD_PREFIX);
            $property = substr($methodName, $start);
            $this->validateProperty($property);
        }

        // run the validate() method if it is defined
        $this->callMethod('validate');
    }


    /**
     * Executes the given property's specific validation method.
     * @return object $this
     */
    protected function validateProperty($property = null)
    {
        if ($property) {
            // only validate if the property is set
            if (!isset($this->_data->$property)) {
                return $this;
            }

            $this->removePropertyErrors($property);

            // run validation only for this property
            $methodName = static::VALIDATION_METHOD_PREFIX . $property;
            if (method_exists($this, $methodName)) {
                // the validate_PROPERTY() method may or may not be defined with a parameter
                // if the method has a parameter, pass the property value as the param
                // otherwise it is the method's responsibility to grab $this->$property
                $method = new \ReflectionMethod($this, $methodName);
                $params = $method->getParameters();
                //elapsed($methodName);
                if (count($params)) {
                    $this->callMethod($methodName, $this->$property);
                } else {
                    $this->callMethod($methodName);
                }
            }
        }
        return $this;
    }


    /**
     * Gets this object's ID
     * @return mixed
     */
    public function getID()
    {
        return $this->id;
    }


    /**
     * Calls a method with the given arguments if it exists
     * @param  string  $method
     * @param  mixed   arguments to pass to this method
     * @return mixed
     */
    protected function callMethod($method /* ,... */)
    {
        if (!method_exists($this, $method)) {
            return null;
        }

        elapsed(get_called_class() . "->callMethod($method)");

        $args = func_get_args();
        $args = array_slice($args, 1);

        return call_user_func_array(array($this, $method), $args);
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

        // add some useful info to the error
        $params['class'] = static::meta('class');

        $error = static::getError($error_code, $params, true);
        $this->_errors[] = $error;
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

        // add some useful info to the error
        $params['class'] = static::meta('class');

        $error = static::getError($error_code, $params);
        $this->_errors[] = $error;

        #$this->addErrorToParent($error);

        return $this;
    }


    /**
     * Removes all errors pertaining to the given property. Because references are used,
     * we also remove the errors from parent objects.
     * @param string $property the name of the property
     */
    protected function removePropertyErrors($property = null)
    {
        if (!$property) return;
        if (is_array($this->_errors)) {
            foreach ($this->_errors as $i => $error) {
                if ($error->fields == array($property) || $error->field == $property) {
                    // unset will not work here, set to null instead
                    $this->_errors[$i] = null;
                }
            }
            $this->_errors = array_values($this->_errors);
            if (!count($this->_errors)) {
                unset($this->_errors);
            }
        }
        // remove null entries from the $this->_errors array
        $this->cleanErrors();
    }


    /**
     * Removes null entries from $this->_errors and repeat for all parent objects
     */
    private function cleanErrors()
    {
        if (is_array($this->_errors)) {
            $this->_errors = array_filter($this->_errors);
        }
        if (!count($this->_errors)) {
            unset($this->_errors);
        }
        if ($this->_parent) {
            $this->_parent->cleanErrors();
        }
    }


    /**
     * Initializes the _errors property if it has not already been initialized so
     * elements can be appended/merged to the array
     */
    protected function initErrorProperty()
    {
        if (!$this->_errors) {
            $this->_errors = array();
        }
    }


    /**
     * Initializes the _modified property if it has not already been initialized
     */
    protected function initModifiedProperty()
    {
        if (!$this->_modified) {
            $this->_modified = new \stdClass;
        }
    }


    /**
     * Gets errors from nested objects and adds them to this object's list of errors
     */
    public function getChildErrors()
    {
        $objects = static::getOneToOneProperties();
        if (is_array($objects)) {
            foreach ($objects as $property) {
                $this->mergeErrors($this->_data->$property);
            }
        }

        $objects = static::getOneToManyProperties();
        if (is_array($objects)) {
            foreach ($objects as $property) {
                if (is_array($this->_data->$property)) {
                    foreach ($this->_data->$property as $object) {
                        $this->mergeErrors($object);
                    }
                }
            }
        }
    }


    /**
     * Used by getChildErrors() to merge the child errors into this object's errors
     * preserving the reference so if the child error is nullified, it will nullify
     * in parent objects also
     * @param Model $obj
     */
    private function mergeErrors($obj)
    {
        if (is_array($obj->_errors)) {
            foreach ($obj->_errors as &$error) {
                $this->initErrorProperty();
                if (!in_array($error, $this->_errors)) {
                    $this->_errors[] = &$error;
                }
            }
        }
    }


    /**
     * Gets the errors
     * @return array ValidationError objects
     */
    public static function getErrors()
    {
        return $this->_errors;
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
