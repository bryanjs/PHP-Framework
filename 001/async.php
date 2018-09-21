<?php

/*

response : { error : "error description" } }

*/

function flush_buffers(){
    ob_end_flush();
    ob_flush();
    flush();
    ob_start();
}

class Async {

	var $response;
	var $mode;

	function Async($responseMode) {
		$this->mode = $responseMode;
		switch( $this->mode ) {
			case "XML":
				// Let the client know we're sending back XML
				header("Content-Type: text/xml");
				$this->response = "<?xml version=\"1.0\" encoding=\"utf-8\"?>";
				$this->response .= "<response>";
				break;
			case "JSON":
				// Let the client know we're sending back text
				//header("Content-Type: application/json");
				//@ini_set("output_buffering", "0");
				//@ini_set('implicit_flush', 1);
				//@ini_set('zlib.output_compression', 0);
				$this->response = '';
				break;
			default:
		}
	}

	function addResponse($response) {
		$this->response .= $response;
	}

	function addResponseFromArray($array, $tagName = '') {
		$response = '';
		if( !is_array($array) )
			return;

		if( $this->mode == 'JSON' ) {
			if( count($array) == 0 ) {
				$this->response .= '"' . $tagName . '": [ ]';
				return;
			}
			array_walk_recursive($array, function(&$t, $key) { if( is_string($t) ) $t = utf8_encode($t); /*if( is_null($t) ) $t = 'null';*/ });
			$this->response = json_encode($array);
		} else {
			foreach( $array as $firstElement ) {
				if( !is_array($firstElement) ) {
					$a = false;
					$newArray[] = $array;
				} else {
					$a = true;
					$newArray = $array;
				}
				break;
			}
			foreach( $newArray as $i ) {
				$response .= "<".$tagName.">";
				foreach( $i as $key => $value ) {
					if( $value ) {
						$response .= "<".$key.">".htmlspecialchars($value)."</".$key.">";
					} else {
						$response .= "<".$key." />";
					}
				}
				$response .= "</".$tagName.">";
			}
			$this->response .= $response;
		}
	}

	function sendError($errorMsg) {
		if( $this->mode == "XML" ) {
			$this->response .= "<error>".$errorMsg."</error>";
		} else if( $this->mode == "JSON" ) {
			$this->response = json_encode(array('error' => array('message' => $errorMsg)));
		} else {
			$this->response = "error :".$errorMsg.";";
		}
		$this->sendResponse();
	}		

	function sendResponse() {
		flush();
		if( strlen($this->response) < 4096 ) {
			echo(str_pad($this->response, 4096));
		} else {
			echo($this->response);
		}
		flush();
		//usleep(20000);
	}
}
?>