<?php

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
		register_shutdown_function( 'check_for_fatal' );
		set_error_handler( 'appErrorHandler' );
		set_exception_handler( 'appExceptionHandler' );
		ini_set( 'display_errors', 'On' );
		error_reporting( E_ALL ); 
	}	
	
	function check_for_fatal() {
	    $error = error_get_last();
	    if( $error !== null )
	    	appErrorHandler( $error['type'], $error['message'], $error['file'], $error['line'] );
	}
	
	function appErrorHandler($errno, $errstr, $errfile, $errline) {
		appExceptionHandler(new ErrorException( $errstr, 0, $errno, $errfile, $errline ) );
		return true;
	}
	
	function appExceptionHandler($e) {	
		$message = "Type: " . get_class( $e ) . ", Message: {$e->getMessage()}, File: {$e->getFile()}, Line: {$e->getLine()}";
	
		header('HTTP/1.1 200 OK', true, 200);
		if( isset($GLOBALS['isAsync']) && $GLOBALS['isAsync'] == true ) {
			$async = new Async('JSON');
			$async->sendError($message);
		} else {
			echo($message);
		}	
		//exit();
	}
	
	setErrorHandling();
?>