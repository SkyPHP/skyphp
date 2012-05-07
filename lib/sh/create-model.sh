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
echo -n "<?

class $1 extends Model {

	public \$_ignore = array();
	public \$_required_fields = array();
	public \$_belongs_to = array();

	# constructor hook
	public function construct() { }

	######################################################################
	## These hooks are \"surrounding\" Model::validate()				##
	## If there are errors set in preValidate(), validate() will abort  ##
	## If there are errors in validate(), postValidate() will not run   ##
	######################################################################

	# runs before standard validation
	public function preValidate() { }

	# runs after standard validation
	public function postValidate() { }

	######################################################################
	## These hooks are executed after validating if there are no errors ##
	######################################################################

	public function before_insert() { }

	public function after_insert() { }

	public function before_update() { }

	public function after_update() { }

	public function before_delete() { }

	public function after_delete() { }

		
}

" > $1/class.$1.php
echo " - done"
echo ""
echo ""
