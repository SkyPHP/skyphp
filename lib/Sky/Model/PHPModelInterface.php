<?php

namespace Sky\Model;

interface PHPModelInterface {

    public static function cacheRead($key);

    public static function cacheWrite($key, $value);

    public static function getList($criteria);

    public static function getOne(array $criteria);

    public static function getMany(array $criteria);

    public static function getCount($criteria);

    public static function getForeignKey($property);

    public static function getCacheKey($id);

    static function getWritableProperties();

    public function checkRequiredFields();

    public function lazyLoadProperty($property);

    public function saveDataToDatabase();

    public function delete();

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
