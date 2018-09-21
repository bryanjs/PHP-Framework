<?php

class App {

	/*
	execution mode: standard	(executed from a standard HTTP request)
					async		(executed from an asynchronous HTTP request)
					embed		(executed from another script)
	*/

	var	$mode = 'embed';
	var $appParams = array();
	var $URI = null;

	var $requestIP = null;

	var $database;
	var $config;

	function __construct() {
		$this->setErrorHandling();
		if( method_exists($this, 'initialize') )
			$this->initialize();
	}

	function createController($controller) {
		if( empty($this->route[$controller]) ) {
			$emptyController = new stdClass();
			$emptyController->go = function() { return null; };
			return $emptyController;
		}

		$route = $this->route[$controller];

		include_once($route[0]);
		$controller = new $route[1];

		$controller->database = $this->database;
		$controller->appInstance = $this;
		$controller->config = $this->config;

		return $controller;
	}

	function loadModel($modelName, $connect) {
		$fullModelName = $this->config['model-path'].'/'.$modelName.'.php';
		if( !file_exists($fullModelName) )
			return null;

		include_once($fullModelName);

		$slashPos = strrpos($modelName, '/');
		if( $slashPos )
			$modelName = substr($modelName, $slashPos+1);

		$model = new $modelName;

		if( !$model )
			return null;

		$model->appInstance = $this;

		if( $connect ) {
			// use application database
			$model->setDB($this->database);
			$model->connectToDB();
		}

		return $model;
	}

	function getIPAddress() {
		return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
	}

	// get params that have been POSTed
	private function getPostParams() {

		if( isset($_POST['_post']) ) {
			$post = $_POST['_post'];
		} else {
			$post = file_get_contents('php://input');
		}

		if( empty($post) )
			return array();

		$json_params = json_decode($post, true);

		if( $json_params && (json_last_error() == JSON_ERROR_NONE) ) {
			return $json_params;
		} else {
			return array();
			/*
				if( json_last_error() != JSON_ERROR_NONE ) {
					new ErrorException( 'JSON Error', 0, json_last_error(), __FILE__, __LINE__ );
					$async = new Async('JSON');
					$async->sendError(getLastJSONError());
					return null;
				}
			*/

		}
	}

	private function getURIParts($URI, $domains) {

		// get URI params
		$URIparams = explode('/', $URI);

		$URIparts = array('domain' => 'main', 'params' => array(), 'domain-params' => array(), 'async' => false);

		if( $URIparams[0] == '' )
			array_shift($URIparams);

		if( !empty($URIparams) ) {
			if( $URIparams[0] == 'async' ) {
				$URIparts['async'] = true;
				array_shift($URIparams);
			}

			if( is_array($domains) && array_key_exists($URIparams[0], $domains) ) {
				$domain = array_shift($URIparams);
				$URIparts['domain'] = $domain;
			}
		}

		if( array_key_exists($URIparts['domain'], $domains) ) {
			$domain = $URIparts['domain'];

			// grab parameters for this domain
			foreach($domains[$domain] as $paramName) {
				$URIparts['domain-params'][] = isset($URIparams[0]) ? array_shift($URIparams) : null;
			}
		}

		for($i=0;$i<count($URIparams);$i+=2) {
			if( $i+1 >= count($URIparams) )
				break;

			// todo: filter param names: 'domain' and all domain param names

			$paramName = $URIparams[$i];
			if( !array_key_exists($paramName, $URIparts['params']) ) {
				$URIparts['params'][$paramName] = $URIparams[$i+1];
			}
		}
		return $URIparts;
	}

	function run($params = null) {

		if( $params == null ) {
			// assume mode is 'standard', might get changed to 'async' later
			$this->mode = 'standard';
			$this->URI = empty($_GET['URI']) ? null : $_GET['URI'];
			$appParams = $_GET;
			$this->requestIP = $this->getIPAddress();
		} else {
			// params array exists
			$this->mode = 'embed';
			$this->URI = empty($params['URI']) ? null : $params['URI'];
			$appParams = $params;
		}
		unset($appParams['URI']);

		$URIparts = $this->getURIParts($this->URI, $this->domains);

		//var_dump($URIparts);

		if( $this->mode == 'standard' && $URIparts['async'] )
			$this->mode = 'async';

		$postParams = $this->getPostParams();

		$this->loadConfig();
		$this->database = $this->loadDB();

		if( $this->mode == 'async' && isset($postParams['multirequest']) && is_array($postParams['multirequest']) ) {
			$response = array();
			$i = 0;
			$multiRequest = $postParams['multirequest'];
			unset($postParams['multirequest']);

			$params = array_merge($postParams, $appParams);
			foreach( $multiRequest as $request ) {
				if( empty($request['URI']) )
					continue;

				$reqURIParts = $this->getURIParts($request['URI'], $this->domains);

				$reqName = isset($request['name']) ? $request['name'] : "request$i";
				$reqParams = isset($request['params']) ? $request['params'] : array();
				$reqParams = array_merge($reqURIParts['params'], $reqParams, $params);
				$response[$reqName] = $this->doRequest($reqURIParts['domain'], $reqURIParts['domain-params'], $reqParams);
				$i++;
			}
		} else {
			$params = array_merge($appParams, $URIparts['params'], $postParams);
			$response = $this->doRequest($URIparts['domain'], $URIparts['domain-params'], $params);
		}

		if( $this->mode == 'embed' ) {
			return $response;
		} else if( $this->mode == 'async' ) {
			$async = new Async('JSON');
			$async->addResponseFromArray($response);
			$async->sendResponse();
		}
	}

	function doRequest($domain, $domainParams, $params) {
		if( method_exists($this, $domain) ) {
			$domainParams[] = $params;
			return call_user_func_array(array($this, $domain), $domainParams);
		} else {
			return array('status' => 0, 'message' => "Domain '$domain' not found");
		}
	}

	function loadConfig() {
		// TODO: allow custom ini filename?

		$this->config = parse_ini_file('settings.ini');

		//var_dump($this->config);
	}

	function loadDB($dbInfo = null) {
		if( is_array($dbInfo) ) {
			$database = new Database($dbInfo);
			return $database;
		}

		if( !$this->database )
			$this->database = new Database($this->config);

		return $this->database;
	}

	function getLastJSONError() {
		switch( json_last_error() ) {
			case JSON_ERROR_NONE:
	            return 'No Error';
	        case JSON_ERROR_DEPTH:
	            return 'Maximum stack depth exceeded';
	        case JSON_ERROR_STATE_MISMATCH:
	            return 'Underflow or the modes mismatch';
	        case JSON_ERROR_CTRL_CHAR:
	            return 'Unexpected control character found';
	        case JSON_ERROR_SYNTAX:
	            return 'Syntax error, malformed JSON';
	        case JSON_ERROR_UTF8:
	            return 'Malformed UTF-8 characters, possibly incorrectly encoded';
	        default:
	            return 'Unknown error';
	    }
	}

	function setErrorHandling() {
		register_shutdown_function( array($this, 'check_for_fatal') );
		set_error_handler( array($this, 'appErrorHandler') );
		set_exception_handler( array($this, 'appExceptionHandler') );
		ini_set( 'display_errors', 'On' );
		error_reporting( E_ALL );
	}

	function check_for_fatal() {
	    $error = error_get_last();
	    if( $error !== null )
	    	$this->appErrorHandler( $error['type'], $error['message'], $error['file'], $error['line'] );
	}

	function appErrorHandler($errno, $errstr, $errfile, $errline) {
		$this->appExceptionHandler(new ErrorException( $errstr, 0, $errno, $errfile, $errline ) );
		return true;
	}

	function appExceptionHandler($e) {
		$message = "Type: " . get_class( $e ) . ", Message: {$e->getMessage()}, File: {$e->getFile()}, Line: {$e->getLine()}";

		header('HTTP/1.1 200 OK', true, 200);
		if( $this->mode == 'async' ) {
			$async = new Async('JSON');
			$async->sendError($message);
		} else {
			echo($message);
		}
		//exit();
	}
}
?>