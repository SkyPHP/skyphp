<?php

class ModelArrayObject extends ArrayObject {
	
	public function __construct() {
		$args = func_get_args();
		call_user_func_array(array('ArrayObject', __FUNCTION__), $args);
		if ($this->getFlags()) return;
		$this->setFlags(ArrayObject::ARRAY_AS_PROPS);
	}

	public function offsetSet($a, $b) {
		if (is_array($b)) $b = new ModelArrayObject($b);
		return parent::offsetSet($a, $b);
	}

}