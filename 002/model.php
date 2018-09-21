<?php

class cModel {	
	var $database = null;
	var $appInstance = null;
	
	public function setDB($db) {	
		$this->database = $db;
	}

	public function connectToDB($dbInfo = null) {
		// no database set and no db info provided
		// can't connect to anything
		if( !$this->database && $dbInfo == null )
			return false;

		if( !$this->database && is_array($dbInfo) ) {
			$this->appInstance->loadDB($dbInfo);
			return false;		
		}
	}
}
?>
