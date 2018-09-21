<?php


//$test = '2+3*pi';

function evaluate($expression) {
    // Remove whitespaces
    $test = preg_replace('/\s+/', '', $expression);

    $number = '(?:\d+(?:[,.]\d+)?|pi|p)'; // What is a number
    $functions = '(?:sinh?|cosh?|tanh?|abs|acosh?|asinh?|atanh?|exp|log10|deg2rad|rad2deg|sqrt|ceil|floor|round)'; // Allowed PHP functions
    $operators = '[+\/*\^%-]'; // Allowed math operators
    $regexp = '/^(('.$number.'|'.$functions.'\s*\((?1)+\)|\((?1)+\))(?:'.$operators.'(?2))?)+$/'; // Final regexp, heavily using recursive patterns

    if( preg_match($regexp, $expression) ) {
        $expression = preg_replace('!pi|p!', 'pi()', $expression); // Replace pi with pi function
        eval('$result = '.$expression.';');
    } else {
        $result = false;
    }
    return $result;
}


define('OP_CLOSE', 1);
define('OP_RENDER', 2);
define('OP_COND', 3);
define('OP_LOOP', 4);
define('OP_FUNC', 5);
define('OP_WHILE', 6);
define('OP_RESET', 7);


define('MODULES_PATH', '../../modules/');

class Contemplate {

    const RE_VAR         = '/\{([a-zA-Z0-9_]+)\}/';
	const RE_EXPR        = '/\{([a-zA-Z0-9_=\>\<\-\+\(\)\s\'\!\*%\&\|\/]+)\}/';
	const RE_INC         = '/\{include\s([a-zA-Z0-9_.\/]+)\}/';
	const RE_FOR_BEG     = '/\{foreach\s([a-zA-Z0-9_]+)\}/';
	const RE_FOR_END     = '/\{\/foreach\}/';
	const RE_IF_BEG      = '/\{if\s([a-zA-Z0-9_=\>\<\-\+\(\)\s\'\!\*%\&\|\/]+)\}/';
	const RE_IF_END      = '/\{\/if\}/';
	const RE_COM_BEG     = '/^\{\*[.]*/';
	const RE_COM_END     = '/[.]*\*\}/';

    var $context = array();
    var $modules = array();

    var $nameStack = array();

    function __construct($ctx = array()) {
        $this->context = $ctx;
    }

    function run($template) {
        $tree = $this->buildTree($template);

        var_dump($tree);

        $result = $this->expandTree($tree, $this->context);

        //var_dump($this->context);

        return $result;
    }

    function setContextVar($key, $value) {
        $this->context[$key] = $value;
    }

    function importContext($ctx) {
        $this->context = array_merge($this->context, $ctx);
    }

    function registerModule($moduleName, $modulePath) {
        $this->modules[$moduleName] = $modulePath;
    }

    private function splitTemplate($template) {

        $pattern = '/({{[\?\/\#\>\<W]?\s[\w\.\^\*;]+\s}})/';

        $tags = preg_split($pattern, $template, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        return $tags;
    }

    private function buildTree(&$tags) {

        if( !is_array($tags) )
            $tags = $this->splitTemplate($tags);

        $nodes = array();

        while( !empty($tags) ) {

            $token = array_shift($tags);

            if( substr($token, 0, 2) == '{{' ) {

                // operator character
                $operatorCharacter = substr($token, 2, 1);

                if( $operatorCharacter == '/') {
                    // close current group
                    if( !empty($node) )
                        $nodes[] = $node;
                    break;
                }

                if( $operatorCharacter == ' ' ) {
                    $node['op'] = OP_RENDER;
                    $node['expression'] = substr($token, 3, -3);
                } else {
                    if( $operatorCharacter == '?' )
                        $node['op'] = OP_COND;

                    if( $operatorCharacter == '#' )
                        $node['op'] = OP_LOOP;

                    if( $operatorCharacter == '>' )
                        $node['op'] = OP_FUNC;

                    if( $operatorCharacter == 'W' )
                        $node['op'] = OP_WHILE;

                    if( $operatorCharacter == '<' )
                        $node['op'] = OP_RESET;

                    $node['expression'] = substr($token, 4, -3);
                    if( $node['op'] == OP_LOOP ) {
                        $idx = strrpos($node['expression'], ';');
                        if( $idx !== false ) {
                            $node['limit'] = (int)trim(substr($node['expression'], $idx+1));
                            $node['expression'] = substr($node['expression'], 0, $idx);
                        }
                    }

                    if( $node['op'] != OP_RESET )
                        $node['children'] = $this->buildTree($tags);
                }

                $nodes[] = $node;
                $node = array();
            } else {
                $node['op'] = OP_RENDER;
                $node['content'] = $token;
                $nodes[] = $node;
                $node = array();
            }
        }

        return $nodes;
    }

    private function stringify($tree) {

        $text = '';

        foreach( $tree as $node ) {
            if( isset($node['content']) ) {
                $text .= $node['content'];
                continue;
            }

            $text .= '{{';
            $opChar = '';
            switch( $node['op'] ) {
                case OP_RENDER:
                    $opChar = '';
                    break;
                case OP_COND:
                    $opChar = '?';
                    break;
                case OP_LOOP:
                    $opChar = '#';
                    break;
                case OP_FUNC:
                    $opChar = '>';
                    break;
                case OP_WHILE:
                    $opChar = 'W';
                    break;
                case OP_RESET:
                    $opChar = '<';
                    break;
            }
            $text .= "$opChar {$node['expression']} }}";
            if( isset($node['children']) ) {
                $text .= $this->stringify($node['children']);
            }
            if( $opChar )
                $text .= "{{/ {$node['expression']} }}";
        }
        return $text;
    }

    private function expandTree($tree) {
        $text = '';

        $pre = null;
        $post = null;

        foreach( $tree as $node ) {

            switch( $node['op'] ) {
                case OP_RENDER:
                    if( isset($node['content']) ) {
                        $text .= $node['content'];
                    } else {
                        $expression = $node['expression'];
                        if( $expression == '*' )
                            $expression = '.*';

                        if( $expression == '_index' )
                            $expression = '._index';

                        $variable = $this->findVariable($expression);
                        //var_dump($context);
                        if( $variable !== null )
                            $text .= $variable;
                    }
                    break;
                case OP_RESET:

                    $array =& $this->findVariable($node['expression']);
                    if( is_array($array) )
                        reset($array);

                    break;
                case OP_LOOP:
                    $expression = $node['expression'];

                    $array =& $this->findVariable($expression);

                    if( !is_array($array) )
                        break;

                    if( $expression[0] == '.' )
                        $expression = substr($expression, 1);

                    array_push($this->nameStack, $expression);

                    $i = 0;
                    $limit = isset($node['limit']) ? $node['limit'] : count($array);
                    while( key($array) !== null && $i < $limit ) {
                        $array['_index'] = $i;
                        $array['*'] = current($array);
                        next($array);
                        //var_dump($context);
                        $text .= $this->expandTree($node['children']);
                        $i++;
                    }

                    array_pop($this->nameStack);

                    break;
                case OP_FUNC:
                    $func = $this->findVariable($node['expression']);

                    $module = null;
                    if( $func === null )
                        $module = $this->findModule($node['expression']);

                    if( $func || $module ) {
                        $childrenString = $this->stringify($node['children']);
                        // execute function

                        if( $func ) {
                            $response = $func($childrenString);
                        } else {
                            $response = $this->runModule($module[0], $module[1], $childrenString, array());
                            //var_dump($response);
                        }

                        if( is_array($response) ) {
                            // make array available under module namespace
                            if( $module )
                                $this->context[$module[0]] = $response;

                                //var_dump($this->context);

                            $children = $node['children'];
                        } else {
                            $children = $this->buildTree($response);
                        }
                    } else {
                        $children = $node['children'];
                    }

                    $text .= $this->expandTree($children);

                    break;
                case OP_COND:

                    include_once('./scurvy/expression.php');

                    $exp = new Expression($node['expression'], [$this, 'findVariable']);

                    //print $exp->evaluate();

                    //$variable = $this->findVariable($node['expression'], $context);

                    if( $exp->evaluate() )
                        $text .= $this->expandTree($node['children']);
                    break;
                case OP_WHILE:
                    $variable =& $this->findVariable($node['expression']);
                    $i = 0;
                    while( !empty($variable) && (key($variable) !== null) ) {
                        $text .= $this->expandTree($node['children']);
                        if( $i++ > 100 )
                            break;
                    }
                    break;
                default:
            }
        }

        return $text;
    }

    private function runModule($moduleName, $method, $children, $params) {

    	if( !include_once(MODULES_PATH."$moduleName.php") )
    		return false;

    	$moduleClass = "{$moduleName}Module";

	    $module = NULL;
    	try {
    		$module = new $moduleClass();
    	} catch( Exception $e ) {
    		return false;
    	}

    	if( empty($module) )
    		return false;

        $params['URI'] = "{$method}";
    	//$module->database = $GLOBALS['db'];

    	$result = $module->run($params);

    	return $result;
    }

    public function &findVariable($identifier) {

        if( $identifier[0] == '.' ) {
            $prefix = implode('.', $this->nameStack);
            $identifier = $prefix.$identifier;
        }

        $variable = &$this->context;

        $levels = explode('.', $identifier);

        foreach( $levels as $level ) {
            if( !isset($variable[$level]) )
                return null;

            $variable = &$variable[$level];
        }

        return $variable;
    }

    private function findModule($identifier) {
        $names = explode('.', $identifier);

        if( count($names) != 2 )
            return null;

        return $names;
    }

}

$template  = '{{> wrapped }}';
$template .= '{{ name }}';
$template .= '{{? valid }}';
$template .= ' Is Valid';
$template .= '{{/ valid }}';
$template .= '{{/ wrapped }}';

//$template .= '{{W colors }}';
$template .= '<ul>';
$template .= '{{# colors;2 }}';
$template .= '<li>{{ * }}</li>';
$template .= '{{< .Blue }}';
$template .= '{{# .Blue }}';
$template .= '<li>{{ * }}</li>';
$template .= '{{/ colors }}';
$template .= '{{/ colors }}';
$template .= '</ul>';
//$template .= '{{/ colors }}';



//$template .= "<ul>\n";
//$template .= "{{# colors }}\n";
//$template .= "<li>{{ * }}</li>\n";
//$template .= "{{/ colors }}\n";
//$template .= '</ul>';
//$template .= '{{ name }}';
//$template .= 'Red = {{ colors.0 }}';
/*
$template .= '{{> Banners.loadArray }}';
$template .= '{{# Banners.banners }}';
$template .= '<br />';
$template .= '{{ .URL }}';
$template .= '{{/ Banners.banners }}';
*/

$context = array(
    'name' => 'Willy',
    'valid' => true,
    'colors' => array('Red', 'Green', 'Blue'=>array('Light Blue', 'Dark Blue')),
    'wrapped' => function($text) {
      return "<b>" . $text . "</b>";
    },
    'count' => function($x) {
        return count($x);
    }
);

$tplt = new Contemplate($context);
$expanded = $tplt->run($template);
print $expanded;

//var_dump($context);

/*
include './scurvy/expression.php';

$exp = new Expression('count(colors)', $context, $context);

$x = $exp->evaluate();
var_dump($x);

var_dump($exp->atomList);
*/

?>
