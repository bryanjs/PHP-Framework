<?php

define('MODE_STANDARD', 1);
define('MODE_ASYNC', 2);
define('MODE_EMBED', 3);
define('MOD_CLI', 4);


define('CONTENT_TEXT', 1);
define('CONTENT_HTML', 2);
define('CONTENT_JSON', 3);
define('CONTENT_XML', 4);
define('CONTENT_BIN', 5);

require_once(__DIR__.'/async.php');
require_once(__DIR__.'/database.php');
require_once(__DIR__.'/config.php');
require_once(__DIR__.'/app.php');

function scriptAccessedDirectly( $fileName ) {
    return $fileName == realpath( $_SERVER['SCRIPT_FILENAME'] );
}

?>
