<?php

class modelArrayObject extends ArrayObject {
	
	public function offsetSet($a, $b) {
		if (is_array($b)) $b = new modelArrayObject($b);
		return parent::offsetSet($a, $b);
	}

}