#! /bin/bash

echo ""
echo ""
echo --------------------
echo building model $1
echo --------------------
echo ""
echo ""
echo " - checking for directory"

if [ -d "$1" ]; then
    echo " - directory already exists!!"
    echo " --ABORT--"
    echo ""
    echo ""
    exit
fi

echo - creating directory
mkdir $1
echo - creating .aql file
touch $1/$1.aql
echo - writing table definition

echo -n "$1 {

}" > $1/$1.aql

echo - creating class file
touch $1/class.$1.php
echo - writing class definition
echo -n "<?php

class $1 extends Model
{

    /**
     *  @var array
     */
    public \$_ignore = array();

    /**
     *  key value pairs: field => display_name
     *  @var array
     */
    public \$_required_fields = array();

    /**
     *  associative array of array('model_name' => 'constructor_field')
     *  see Model::refreshBelongsTo()
     *  @var array
     */
    public \$_belongs_to = array();

    /**
     *  runs as a construct hook, do not override __construct()
     *  also gets executed after Model::reload()
     */
    public function construct()
    {

    }

    ######################################################################################
    ## These hooks are \"surrounding\" Model::validate()                                ##
    ## If there are errors set in preValidate(), validate() will abort                  ##
    ## If there are errors in validate(), postValidate() will not run                   ##
    ######################################################################################

    /**
     *  runs before standard validation
     */
    public function beforeCheckRequiredFields()
    {

    }

    /**
     *  runs after standard validation if there are no errors
     */
    public function validate()
    {

    }

    ######################################################################################
    ## These hooks are executed after validating if there are no errors                 ##
    ######################################################################################

    public function beforeInsert()
    {

    }

    public function afterInsert()
    {

    }

    public function beforeUpdate()
    {

    }

    public function afterUpdate()
    {

    }

    public function beforeDelete()
    {

    }

    public function afterDelete()
    {

    }

}

" > $1/class.$1.php
echo " - done"
echo ""
echo ""
