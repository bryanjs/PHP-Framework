<?php

class cController {
	
	var $database;
	var $user;
	var $errors;
	var $page;
	var $message;
	var $action;
	var $alert;
	var $title;
	
	var $response = array();
	
	function __construct($requireSignIn = false) {

/*
		$this->user = getCurrentUser();
		if( $requireSignIn )
			checkSignedIn($this->user);
*/

	}

	function go($action='', $params) {
		$this->action = $action;
		if( method_exists($this, $action) ) {
			return $this->$action($params);
			//return $this->response;
		}

		if( method_exists($this, '__default') ) {
			return $this->__default($params);
			//return $this->response;
		}
		return array('status' => 0, 'message' => 'No action or default method found');
	}
	
	function addResponse($key, $_response) {
		$this->response[$key] = $_response;
	}
	
	function sendServerEvent($_response, $_millis) {
		static $lastMillis = 0;

		$_millis *= 1000;
		$currentMillis = microtime(true) * 1000;
		$m = $currentMillis - $lastMillis;
		$lastMillis = $currentMillis;

		if( $m < $_millis) {
			//echo($m);
			usleep(($_millis - $m));
		}
		
		$r = $_response;
		array_walk_recursive($r, function(&$t, $key) { if( is_string($t) ) $t = (utf8_encode($t)); if( is_null($t) ) $t = 'null'; });
		$r = json_encode(array('_serverEvent' => $r));
		echo(str_pad($r, 4097));
		flush();	
	}
	
	function getErrors() {
		return $this->errors;
	}
	
	function getMessage() {
		return $this->message;
	}
	
	function loadModel($modelName, $connect) {
		$this->appInstance->loadModel($modelName, $connect);	
	}
}
?>
