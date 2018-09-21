<?php

abstract class App {

	/*
	execution mode:	cli 		(executed from command-line)
					standard	(executed from a standard HTTP request)
					async		(executed from an asynchronous HTTP request)
					embed		(executed from another script)
	*/

	var	$mode = 'embed';
	var $appParams = array();
	var $URI = NULL;

	var $requestIP = NULL;

	var $database = NULL;
	var $config = array();

	var $entryPoints = NULL;

	var $customExceptionHandler = NULL;

	function __construct() {
		$this->setErrorHandling();
		if( method_exists($this, 'initialize') )
			$this->initialize();
	}

	function createController($controllerName) {

		require_once(__DIR__.'/controller.php');

		if( empty($this->route[$controllerName]) ) {
			$emptyController = new stdClass();
			$emptyController->go = function() { return null; };
			return $emptyController;
		}

		$route = $this->route[$controllerName];

		include_once($route[0]);
		$controller = new $route[1];

		$controller->database = $this->database;
		$controller->appInstance = $this;
		$controller->config = $this->config;

		return $controller;
	}

	function loadModel($modelName, $connect) {

		require_once(__DIR__.'/model.php');

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
			$postString = $_POST['_post'];
		} else {
			$postString = file_get_contents('php://input');
		}

		$post = $_POST;

		if( empty($postString) )
			return $post;

		$json_params = json_decode($postString, true);

		if( $json_params && (json_last_error() == JSON_ERROR_NONE) ) {
			$post = array_merge($json_params, $_POST);
			if( $this->mode == 'standard' )
				$this->mode = 'async';
		} else {
			/*
				if( json_last_error() != JSON_ERROR_NONE ) {
					new ErrorException( 'JSON Error', 0, json_last_error(), __FILE__, __LINE__ );
					$async = new Async('JSON');
					$async->sendError(getLastJSONError());
					return null;
				}
			*/
		}
		return $post;
	}

	private function getURIParts($URI) {

		$URIparts = array('entry' => 'main', 'params' => array(), 'entry-params' => array(), 'async' => false);

		// get URI params
		$URIparams = explode('/', $URI);

		//var_dump($URI);
		//var_dump($URIparams);

		if( $URIparams[0] == '' )
			array_shift($URIparams);

		if( $URIparams[0] == '' )
			array_shift($URIparams);

		if( !empty($URIparams) ) {
			if( $URIparams[0] == 'async' ) {
				$URIparts['async'] = true;
				array_shift($URIparams);
			}

			if( array_key_exists($URIparams[0], $this->entryPoints) )
				$URIparts['entry'] = array_shift($URIparams);
		}

		if( array_key_exists($URIparts['entry'], $this->entryPoints) ) {
			$entryPoint = $URIparts['entry'];

			// grab parameters for this entry point
			foreach($this->entryPoints[$entryPoint] as $paramName)
				$URIparts['entry-params'][] = isset($URIparams[0]) ? array_shift($URIparams) : null;
		}

		for( $i=0; $i<count($URIparams); $i+=2) {
			if( $i+1 >= count($URIparams) )
				break;

			// todo: filter param names: 'entry' and all entry param names

			$paramName = $URIparams[$i];
			if( !array_key_exists($paramName, $URIparts['params']) )
				$URIparts['params'][$paramName] = $URIparams[$i+1];
		}

		//var_dump($URIparts);
		return $URIparts;
	}

	function run($params = null) {

		//print get_class($this);

		if( empty($this->entryPoints) ) {
			$methods = get_class_methods(get_class($this));
			foreach( $methods as $method )
				$this->entryPoints[$method] = array();

			//var_dump($this->entryPoints);
		}

		//var_dump($params);

 		if( !empty($params) ) {
			// params array exists
			$this->mode = 'embed';
			$this->URI = empty($params['URI']) ? null : $params['URI'];
			$appParams = $params;
		} else if( php_sapi_name() == 'cli' OR defined('STDIN') ) {
			$this->mode = 'cli';
			parse_str(implode('&', array_slice($argv, 1)), $appParams);
			$this->URI = empty($appParams['URI']) ? null : $appParams['URI'];
		} else {
			// assume mode is 'standard', might get changed to 'async' later
			$this->mode = 'standard';
			$this->URI = !empty($_GET['URI']) ? $_GET['URI'] : preg_replace('/\/+/', '/', $_SERVER['REQUEST_URI']);
			$appParams = $_GET;
			$this->requestIP = $this->getIPAddress();
		}


		//print $this->URI;

		unset($appParams['URI']);

		$URIparts = $this->getURIParts($this->URI);

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

				$reqURIParts = $this->getURIParts($request['URI']);

				$reqName = isset($request['name']) ? $request['name'] : "request$i";
				$reqParams = isset($request['params']) ? $request['params'] : array();
				$reqParams = array_merge($reqURIParts['params'], $reqParams, $params);
				$response[$reqName] = $this->doRequest($reqURIParts['entry'], $reqURIParts['entry-params'], $reqParams);
				$i++;
			}
		} else {
			$params = array_merge($appParams, $URIparts['params'], $postParams);
			$response = $this->doRequest($URIparts['entry'], $URIparts['entry-params'], $params);
		}

		//var_dump($response);

		if( $this->mode == 'async' ) {
			$async = new Async('JSON');
			$async->addResponseFromArray($response);
			$async->sendResponse();
		} else if( $this->mode == 'standard' ) {
			echo $response;
		} else {
			return $response;
		}
	}

	function doRequest($entryPoint, $entryParams, $params) {
		if( method_exists($this, $entryPoint) ) {
			$entryParams[] = $params;
			return call_user_func_array(array($this, $entryPoint), $entryParams);
		} else {
			return array('status' => 0, 'message' => "Entrypoint '$entryPoint' not found");
		}
	}

	function loadConfig() {
		// TODO: allow custom ini filename?

		if( file_exists('settings.ini') )
			$this->config = parse_ini_file('settings.ini');

		//var_dump($this->config);
	}

	function loadDB($dbInfo = null) {

		if( is_array($dbInfo) ) {
			$database = new Database($dbInfo);
			return $database;
		}

		//return null;

		if( !$this->database && !empty($this->config) )
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

		if( $this->customExceptionHandler && method_exists($this, $this->customExceptionHandler) ) {
			call_user_func_array($this, $this->customExceptionHandler, array($e, $message));
			return;
		}

		header('HTTP/1.1 200 OK', true, 200);
		if( $this->mode == 'async' ) {
			$async = new Async('JSON');
			$async->sendError($message);
		} else {
			echo($message);
		}
		//exit();
	}

	function saveUpload($upload, $path, $allowedExt = NULL, $keepOriginalName = FALSE) {

		$response = array();

		$err = $upload['error'];
		$response['errorCode'] = $err;

		if( $err > 0 ) {
			$response['errorCode'] = $err;
			switch( $err ) {
				case '1':
					$response['message'] = 'php.ini max file size exceeded. Please make your file smaller and try again.';
					break;
				case '2':
					$response['message'] = 'max file size exceeded. Please make your file smaller and try again.';
					break;
				case '3':
					$response['message'] = 'File upload was interrupted. Please try again.';
					break;
				case '4':
					$response['message'] = 'No file was attached.';
					break;
				case '7':
					$response['message'] = 'File permission error.';
					break;
				default :
					$response['message'] = 'Unexpected error.';
			}
			return $response;
		}

		$ext = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));

		if( !empty($allowedExt) && !in_array($ext, $allowedExt) ) {
			$response['message'] = 'File type is not allowed.';
			$response['errorCode'] = 98;
			return $response;
		}

		$response['originalName'] = $upload['name'];
		$response['ext'] = $ext;

		if( empty($path) ) {
			// do base64 instead
			$response['base64'] = base64_encode(file_get_contents($upload['tmp_name']));
			return $response;
		}

		if( $keepOriginalName ) {
			$i = 1;
			$modifier = '';
			$response['name'] = pathinfo($upload['name'], PATHINFO_FILENAME);
			while( file_exists($path.$response['name'].$modifier.'.'.$ext) ) {
				$modifier = " ($i)";
				if( $i++ > 100 )
					break;
			}
			$response['name'] = $response['name'].$modifier.'.'.$ext;
		} else {
			do {
				$response['name'] = sha1( ((time() & 0xFFFF) * mt_rand()).$upload['tmp_name']).'.'.$ext;
			} while( file_exists($path.$response['name']) );
		}

		if( !move_uploaded_file($upload['tmp_name'], $path.$response['name']) ) {
			$response['message'] = 'Uploaded file could not be saved.';
			unset($response['name']);
			$response['errorCode'] = 99;
		} else {
			$response['message'] = 'File has been successfully uploaded.';
		}
		return $response;
	}
}
?>
