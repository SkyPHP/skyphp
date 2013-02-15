<?php

namespace Sky\Model;

interface PHPModelInterface {

    /**
     * write a value to the cache
     */
    public static function cacheWrite($key, $value);

    /**
     * read a value from the cache
     */
    public static function cacheRead($key);

    /**
     * gets the id of each object given the criteria
     */
    public static function getList($criteria);

    /**
     * gets the quantity of objects with the given the criteria
     */
    public static function getCount($criteria);

    /**
     * get the foreign key field for the given 1-to-1 property
     */
    public static function getForeignKey($property);

    /**
     *
     */
    public static function getCacheKey($id);

    /**
     *
     */
    public static function getWritableProperties();


    /**
     * adds an error to _errors if a required field is missing
     */
    public function checkRequiredFields();

    /**
     * get the value for the specific property
     * should retrieve a nested object (or array of nested objects) on demand
     */
    public function lazyLoadProperty($property);

    /**
     * save the modified data for this single object to the database and cache
     * (assuming both are within a transaction)
     */
    public function saveDataToDatabase();

    /**
     *
     */
    public function delete();

    /**
     * this is where you would automatically encrypt certain properties, etc
     */
    public function afterSetValue($property, $value);

    public function refreshCachedLists();


    /***********************************************************

    Optional methods

    ************************************************************

    public function beforeCheckRequiredFields();

    public function validate();

    public function beforeInsert();

    public function beforeUpdate();

    public function afterInsert();

    public function afterUpdate();

    public function afterCommit();

    */

}
