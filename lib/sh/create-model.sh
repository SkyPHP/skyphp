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

	public function construct() {
		
	}

	public function preValidate() {
		
	}

	public function postValidate() {
		
	}

	public function after_insert() {
		
	}

	public function after_update() {
		
	}
		
}
" > $1/class.$1.php
echo " - done"
echo ""
echo ""
