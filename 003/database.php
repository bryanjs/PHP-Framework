<?php

//a list of custom functions that can be applied to columns in the databases
//make sure to define every function below if it is not a core PHP function
$custom_functions = array('md5', 'md5rev', 'sha1', 'sha1rev', 'time', 'mydate', 'strtotime', 'myreplace');

//define all the non-core custom functions
function md5rev($value) {
	return strrev(md5($value));
}
function sha1rev($value) {
	return strrev(sha1($value));
}
function mydate($value) {
	return date("g:ia n/j/y", intval($value));
}
function myreplace($value) {
	return ereg_replace("[^A-Za-z0-9]", "", strval($value));
}

//data types array
$types = array('INTEGER', 'REAL', 'TEXT', 'BLOB');
define("DATATYPES", serialize($types));

//available SQLite functions array (don't add anything here or there will be problems)
$functions = array('abs', 'hex', 'length', 'lower', 'ltrim', 'random', 'round', 'rtrim', 'trim', 'typeof', 'upper');
define("FUNCTIONS", serialize($functions));
define("CUSTOM_FUNCTIONS", serialize($custom_functions));
define("FORCETYPE", false); //force the extension that will be used (set to false in almost all circumstances except debugging)

//function that allows SQL delimiter to be ignored inside comments or strings
function explode_sql($delimiter, $sql) {
	$ign = array('"' => '"', "'" => "'", "/*" => "*/", "--" => "\n"); // Ignore sequences.
	$out = array();
	$last = 0;
	$slen = strlen($sql);
	$dlen = strlen($delimiter);
	$i = 0;
	while($i < $slen) {
		// Split on delimiter
		if($slen - $i >= $dlen && substr($sql, $i, $dlen) == $delimiter) {
			array_push($out, substr($sql, $last, $i - $last));
			$last = $i + $dlen;
			$i += $dlen;
			continue;
		}
		// Eat comments and string literals
		foreach($ign as $start => $end) {
			$ilen = strlen($start);
			if($slen - $i >= $ilen && substr($sql, $i, $ilen) == $start) {
				$i+=strlen($start);
				$elen = strlen($end);
				while($i < $slen) {
					if($slen - $i >= $elen && substr($sql, $i, $elen) == $end) {
						// SQL comment characters can be escaped by doubling the character. This recognizes and skips those.
						if($start == $end && $slen - $i >= $elen*2 && substr($sql, $i, $elen*2) == $end.$end) {
							$i += $elen * 2;
							continue;
						} else {
							$i += $elen;
							continue 3;
						}
					}
					$i++;
				}
				continue 2;
			}
		}
		$i++;
	}
	if($last < $slen)
		array_push($out, substr($sql, $last, $slen - $last));
	return $out;
}

// function to encode value into HTML just like htmlentities, but with adjusted default settings
function htmlencode($value, $flags=ENT_QUOTES, $encoding='UTF-8') {
	return htmlentities($value, $flags, $encoding);
}

// 22 August 2011: gkf added this function to support display of
//                 default values in the form used to INSERT new data.
function deQuoteSQL($s) {
	return trim(trim($s), "'");
}

//
// Database class
// Generic database abstraction class to manage interaction with database without worrying about SQLite vs. PHP versions
//

const DB_FETCH_ASSOC = 0;
const DB_FETCH_NUM   = 1;
const DB_FETCH_BOTH  = 2;

class Database {
	protected $db; 		// reference to the DB object
	protected $type; 	// the extension for PHP that handles SQLite
	protected $data;
	protected $lastResult;
	protected $fns;

	protected $engine;

	private $fetchModes = array();

	public function __construct($data) {
		$this->data = $data;
		$this->fns = array();

		$this->engine = isset($data['engine']) ? $data['engine'] : 'sqlite';

		//try {
			if( $this->engine == 'sqlite' ) {
				if( !file_exists($this->data["path"]) && !is_writable(dirname($this->data["path"]))) { //make sure the containing directory is writable if the database does not exist
					echo "<div class='confirm' style='margin:20px;'>";
					echo "The database, '".htmlencode($this->data["path"])."', does not exist and cannot be created because the containing directory, '".htmlencode(dirname($this->data["path"]))."', is not writable. The application is unusable until you make it writable.";
					echo "</div><br/>";
					exit();
				}
			}

			$ver = $this->getVersion();

			switch( true ) {
				case( $this->engine == 'sqlsrv' ):
					if( strpos($this->data['server'], '(local') !== false ) {
						try {
							//$this->db = new PDO("sqlsrv:server={$this->data['server']};database=ERP", 'PC-PC\PC', NULL);
							$this->db = new PDO("sqlsrv:server=(localdb)\\V11.0;database=ERP");
						} catch(Exception $e) {
							print_r($e->getMessage());
						}
					} else {
						//$this->db = new PDO("sqlsrv:Server={$this->data['server']};Database={$this->data['dbname']}", $this->data['user'], $this->data['password']);
						$connectionInfo = array('UID' => $this->data['user'], 'PWD' => $this->data['password'], 'Database' => $this->data['dbname']);
						$this->db = sqlsrv_connect($this->data['server'], $connectionInfo);
					}
					if( $this->db != NULL ) {
						$this->type = 'SQLSRV';
						$this->fetchModes = array(SQLSRV_FETCH_ASSOC, SQLSRV_FETCH_NUMERIC, SQLSRV_FETCH_BOTH);
					}
					break;
				case( FORCETYPE == 'PDO' || ((FORCETYPE==false || $ver!=-1) && class_exists('PDO') && ($ver==-1 || $ver==3))):
					$this->db = new PDO('sqlite:'.$this->data['path']);
					if( $this->db != NULL ) {
						$this->type = 'PDO';
						$cfns = unserialize(CUSTOM_FUNCTIONS);
						for( $i=0; $i<sizeof($cfns); $i++ ) {
							$this->db->sqliteCreateFunction($cfns[$i], $cfns[$i], 1);
							$this->addUserFunction($cfns[$i]);
						}
						$this->fetchModes = array(PDO::FETCH_ASSOC, PDO::FETCH_NUM, PDO::FETCH_BOTH);
						break;
					}
				case( FORCETYPE == 'SQLite3' || ((FORCETYPE==false || $ver!=-1) && class_exists('SQLite3') && ($ver==-1 || $ver==3))):
					$this->db = new SQLite3($this->data['path']);
					if( $this->db != NULL ) {
						$cfns = unserialize(CUSTOM_FUNCTIONS);
						for($i=0; $i<sizeof($cfns); $i++) {
							$this->db->createFunction($cfns[$i], $cfns[$i], 1);
							$this->addUserFunction($cfns[$i]);
						}
						$this->type = 'SQLite3';
						$this->fetchModes = array(SQLITE3_ASSOC, SQLITE3_NUM, SQLITE3_BOTH);
						break;
					}
				case( FORCETYPE == 'SQLiteDatabase' || ((FORCETYPE==false || $ver!=-1) && class_exists('SQLiteDatabase') && ($ver==-1 || $ver==2))):
					$this->db = new SQLiteDatabase($this->data['path']);
					if( $this->db != NULL ) {
						$cfns = unserialize(CUSTOM_FUNCTIONS);
						for($i=0; $i<sizeof($cfns); $i++) {
							$this->db->createFunction($cfns[$i], $cfns[$i], 1);
							$this->addUserFunction($cfns[$i]);
						}
						$this->type = 'SQLiteDatabase';
						$this->fetchModes = array(SQLITE_ASSOC, SQLITE_NUM, SQLITE_BOTH);
						break;
					}
				default:
					$this->showError();
					exit();
			}
		//}
		//catch(Exception $e)
		//{
		//	$this->showError();
		//	exit();
		//}
	}

	public function close() {
		if( $this->type == 'PDO' )
			$this->db = NULL;
		else if( $this->type == 'SQLSRV' )
			sqlsrv_close($this->db);
		else if( $this->type == 'SQLite3' )
			$this->db->close();
		else if( $this->type == 'SQLiteDatabase' )
			$this->db = NULL;
	}

	public function getUserFunctions() {
		return $this->fns;
	}

	public function addUserFunction($name) {
		array_push($this->fns, $name);
	}

	public function getError() {
		if( $this->type == 'PDO' ) {
			$e = $this->db->errorInfo();
			return $e[2];
		} else if( $this->type == 'SQLite3' ) {
			return $this->db->lastErrorMsg();
		} else {
			return sqlite_error_string($this->db->lastError());
		}
	}

	public function showError() {
		$classPDO = class_exists('PDO');
		$classSQLite3 = class_exists('SQLite3');
		$classSQLiteDatabase = class_exists('SQLiteDatabase');
		$strPDO = $classPDO ? 'installed' : 'not installed';
		$strSQLite3 = $classSQLite3 ? 'installed' : 'not installed';
		$strSQLiteDatabse = $classSQLiteDatabase ? 'installed' : 'not installed';

		echo "<div class='confirm' style='margin:20px;'>";
		echo "There was a problem setting up your database, ".$this->getPath().". An attempt will be made to find out what's going on so you can fix the problem more easily.<br/><br/>";
		echo "<i>Checking supported SQLite PHP extensions...<br/><br/>";
		echo "<b>PDO</b>: ".$strPDO."<br/>";
		echo "<b>SQLite3</b>: ".$strSQLite3."<br/>";
		echo "<b>SQLiteDatabase</b>: ".$strSQLiteDatabase."<br/><br/>...done.</i><br/><br/>";
		if(!$classPDO && !$classSQLite3 && !$classSQLiteDatabase)
			echo "It appears that none of the supported SQLite library extensions are available in your installation of PHP. You may not use ".PROJECT." until you install at least one of them.";
		else {
			if(!$classPDO && !$classSQLite3 && $this->getVersion()==3)
				echo "It appears that your database is of SQLite version 3 but your installation of PHP does not contain the necessary extensions to handle this version. To fix the problem, either delete the database and allow ".PROJECT." to create it automatically or recreate it manually as SQLite version 2.";
			else if(!$classSQLiteDatabase && $this->getVersion()==2)
				echo "It appears that your database is of SQLite version 2 but your installation of PHP does not contain the necessary extensions to handle this version. To fix the problem, either delete the database and allow ".PROJECT." to create it automatically or recreate it manually as SQLite version 3.";
			else
				echo "The problem cannot be diagnosed properly. Please file an issue report at http://phpliteadmin.googlecode.com.";
		}
		echo "</div><br/>";
	}

	public function __destruct() {
		if( $this->db )
			$this->close();
	}

	//get the exact PHP extension being used for SQLite
	public function getType() {
		return $this->type;
	}

	//get the name of the database
	public function getName() {
		return $this->data['name'];
	}

	//get the filename of the database
	public function getPath() {
		return $this->data['path'];
	}

	//get the version of the database
	public function getVersion() {
		return 3;
		if(file_exists($this->data['path'])) { //make sure file exists before getting its contents
			$content = strtolower(file_get_contents($this->data['path'], NULL, NULL, 0, 40)); //get the first 40 characters of the database file
			$p = strpos($content, "** this file contains an sqlite 2"); //this text is at the beginning of every SQLite2 database
			return ($p!==false) ? 2 : 3;
		} else { //return -1 to indicate that it does not exist and needs to be created
			return -1;
		}
	}

	//get the size of the database
	public function getSize() {
		return round(filesize($this->data['path'])*0.0009765625, 1).' KB';
	}

	//get the last modified time of database
	public function getDate() {
		return date("g:ia \o\\n F j, Y", filemtime($this->data["path"]));
	}

	//get number of affected rows from last query
	public function getAffectedRows() {
		if( $this->type == 'PDO' )
			return $this->lastResult->rowCount();
		else if( $this->type == 'SQLite3' )
			return $this->db->changes();
		else if( $this->type == 'SQLiteDatabase')
			return $this->db->changes();
	}

	public function getLastInsertedRecord($table) {
		$lastID = 0;

		if( $this->type == 'SQLSRV' )
			return null;
		elseif( $this->type == 'PDO' )
			$lastID = $this->db->lastInsertId();
		else if( $this->type == 'SQLite3' )
			$lastID = $this->db->lastInsertRowID();
		else if( $this->type == 'SQLiteDatabase' )
			$lastID = $this->db->lastInsertRowid();

		return $this->select("SELECT * FROM $table WHERE rowid = $lastID");
	}


	public function beginTransaction() {
		$this->query('BEGIN');
	}

	public function commitTransaction() {
		$this->query('COMMIT');
	}

	public function rollbackTransaction() {
		$this->query('ROLLBACK');
	}

	//generic query wrapper
	public function query($query, $ignoreAlterCase=false) {
		if( strtolower(substr(ltrim($query),0,5)) == 'alter' && $ignoreAlterCase == false) {
			// this query is an ALTER query - call the necessary function
			preg_match("/^\s*ALTER\s+TABLE\s+\"((?:[^\"]|\"\")+)\"\s+(.*)$/i",$query,$matches);
			if( !isset($matches[1]) || !isset($matches[2]) ) {
				return false;
			}
			$tablename = str_replace('""','"',$matches[1]);
			$alterdefs = $matches[2];
			$result = $this->alterTable($tablename, $alterdefs);
		} else {
			//this query is normal - proceed as normal
			if( $this->type == 'SQLSRV' ) {
				$result = sqlsrv_query($this->db, $query);
			} else {
				$result = $this->db->query($query);
			}
		}
		if( $result === false )
			return false;

		$this->lastResult = $result;
		return $result;
	}

	// returns 1 or more rowsets for query
	public function execute($query, $mode = DB_FETCH_ASSOC, $returnRows = true) {
		$result = $this->query($query);

		if( !$returnRows || $result === false )
			return $result;

		$tables = array();

		do {
			$tables[] = $this->fetchAllRows($result, $mode);
		} while ( $this->nextRowset($result) );

		return $tables;
	}

	public function nextRowset($result) {
		if( $this->type == 'SQLSRV' )
			return sqlsrv_next_result($result);
		else
			return $result->nextRowset();
	}

	public function fetchRow($result, $mode = DB_FETCH_ASSOC) {
		if( $result === false )
			return NULL;

		$mode = $this->fetchModes[$mode];

		if( $this->type == 'SQLSRV' ) {
			return sqlsrv_fetch_array($result, $mode);
		} else if( $this->type == 'PDO' ) {
			return $result->fetch($mode);
		} else if( $this->type == 'SQLite3' ) {
			return $result->fetchArray($mode);
		} else if( $this->type == 'SQLiteDatabase' ) {
			return $result->fetch($mode);
		}
	}

	public function fetchAllRows($queryResult, $mode = DB_FETCH_ASSOC) {
		if( $queryResult === false )
			return NULL;

		$mode = $this->fetchModes[$mode];

		if( $this->type == 'SQLSRV' ) {
			$rows = array();
			while($row = sqlsrv_fetch_array($queryResult, $mode) ) {
				$rows[] = $row;
			}
			return $rows;
		} else if( $this->type == 'PDO' ) {
			return $queryResult->fetchAll($mode);
		} else if( $this->type == 'SQLite3' ) {
			$rows = array();
			while( $row = $queryResult->fetchArray($mode) ) {
				$rows[] = $row;
			}
			return $rows;
		} else if( $this->type == 'SQLiteDatabase' ) {
			return $queryResult->fetchAll($mode);
		}
		return $rows;
	}

	//returns 1 row
	public function select($query, $mode = DB_FETCH_ASSOC) {
		return $this->fetchRow($this->query($query), $mode);
	}

	//returns an array of rows
	public function selectAll($query, $mode = DB_FETCH_ASSOC) {
		return $this->fetchAllRows($this->query($query), $mode);
	}

	//wrapper for an INSERT and returns the ID of the inserted row
	public function insert($table, $data) {

		$q = 'INSERT INTO '.$this->quote_id($table).' ';
		$v=''; $n='';

		foreach( $data as $key=>$val ) {
			$n .= "$key, ";
			if( strtolower($val) == 'null' )
				$v .= 'NULL, ';
			elseif( strtolower($val) == 'now()' )
				$v .= $this->quote(date('Y-m-d H:i:s')).', ';
			else
				$v .= $this->quote($val).", ";
		}

		$q .= '('. rtrim($n, ', ') .')' . (($this->type == 'SQLSRV') ? ' OUTPUT INSERTED.*' : '') . ' VALUES ('. rtrim($v, ', ') .')';

		if( $this->type == 'SQLSRV' ) {
			$result = $this->query($q);
			if( $result ) {
				return sqlsrv_fetch_array($result, SQLSRV_FETCH_ASSOC);
			}
		} else {
			$result = $this->query($q);
			return $this->getLastInsertedRecord($table);
		}
	}

	// desc: does an update query with an array
	// param: table (no prefix), assoc array with data (doesn't need escaped), where condition
	// returns: (query_id) for fetching results etc
	public function update($table, $data, $where='1') {
		$whereSQL = '';
		if( is_array($where) ) {
			foreach( $where as $key => &$value )
				$value = "($key='$value')";
			$whereSQL = implode(' AND ', $where);
		} else {
			$whereSQL = $where;
		}

		if( $this->type != 'SQLSRV' )
			$table = "'$table'";

		$q = "UPDATE $table SET ";

		foreach($data as $key=>$val) {
			if( $this->type != 'SQLSRV' )
				$key = "'$key'";

			if(strtolower($val)=='null')
				$q.= "$key = NULL, ";
			elseif( strtolower($val) == 'now()' )
				$q.= "$key = '" . date('Y-m-d H:i:s') . "', ";
			else
				$q.= "$key=".$this->quote($val).", ";
		}

		$q = rtrim($q, ', ') . ' WHERE '.$whereSQL.';';
		return $this->query($q);
	}


	// desc: deletes rows from table
	// param: table (no prefix), where condition
	// returns: (query_id) for fetching results etc
	public function delete($table, $where='1') {
		$whereSQL = '';
		if( is_array($where) ) {
			foreach( $where as $key => &$value )
				$value = "($key='$value')";
			$whereSQL = implode(" AND ", $where);
		} else {
			$whereSQL = $where;
		}

		if( $this->type == 'SQLSRV' ) {
			$q = "DELETE FROM $table WHERE $whereSQL;";
		} else {
			$q = "DELETE FROM '$table' WHERE $whereSQL;";
		}
		return $this->query($q);
	}

	// SQlite supports multiple ways of surrounding names in quotes:
	// single-quotes, double-quotes, backticks, square brackets.
	// As sqlite does not keep this strict, we also need to be flexible here.
	// This function generates a regex that matches any of the possibilities.
	private function sqlite_surroundings_preg($name,$preg_quote=true,$notAllowedIfNone="'\"") {
		if( $name == '*' || $name == '+' ) {
			$nameSingle   = "(?:[^']|'')".$name;
			$nameDouble   = "(?:[^\"]|\"\")".$name;
			$nameBacktick = "(?:[^`]|``)".$name;
			$nameSquare   = "(?:[^\]]|\]\])".$name;
			$nameNo = "[^".$notAllowedIfNone."]".$name;
		} else {
			if($preg_quote) $name = preg_quote($name,"/");

			$nameSingle = str_replace("'","''",$name);
			$nameDouble = str_replace('"','""',$name);
			$nameBacktick = str_replace('`','``',$name);
			$nameSquare = str_replace(']',']]',$name);
			$nameNo = $name;
		}

		$preg =	"(?:'".$nameSingle."'|".   // single-quote surrounded or not in quotes (correct SQL for values/new names)
				$nameNo."|".               // not surrounded (correct SQL if not containing reserved words, spaces or some special chars)
				"\"".$nameDouble."\"|".    // double-quote surrounded (correct SQL for identifiers)
				"`".$nameBacktick."`|".    // backtick surrounded (MySQL-Style)
				"\[".$nameSquare."\])";    // square-bracket surrounded (MS Access/SQL server-Style)
		return $preg;
	}

	// function that is called for an alter table statement in a query
	// code borrowed with permission from http://code.jenseng.com/db/
	// this has been completely debugged / rewritten by Christopher Kramer
	public function alterTable($table, $alterdefs) {
		if( $alterdefs != '' ) {
			$recreateQueries = array();
			$tempQuery = "SELECT sql,name,type FROM sqlite_master WHERE tbl_name = ".$this->quote($table)." ORDER BY type DESC";
			$result = $this->query($tempQuery);
			$resultArr = $this->selectArray($tempQuery);
			if( $this->type == 'PDO' )
				$result->closeCursor();

			if( sizeof($resultArr) < 1)
				return false;

			for($i=0; $i<sizeof($resultArr); $i++) {
				$row = $resultArr[$i];
				if($row['type'] != 'table') {
					// store the CREATE statements of triggers and indexes to recreate them later
					$recreateQueries[] = $row['sql']."; ";
				} else {
					// ALTER the table
					$tmpname = 't'.time();
					$origsql = $row['sql'];
					$createtemptableSQL = "CREATE TEMPORARY TABLE ".$this->quote($tmpname)." ".
						preg_replace("/^\s*CREATE\s+TABLE\s+".$this->sqlite_surroundings_preg($table)."\s*(\(.*)$/i", '$1', $origsql, 1);

					$createindexsql = array();
					preg_match_all("/(?:DROP|ADD|CHANGE|RENAME TO)\s+(?:\"(?:[^\"]|\"\")+\"|'(?:[^']|'')+')((?:[^,')]|'[^']*')+)?/i",$alterdefs,$matches);
					$defs = $matches[0];

					$get_oldcols_query = "PRAGMA table_info(".$this->quote_id($table).")";
					$result_oldcols = $this->selectArray($get_oldcols_query);
					$newcols = array();
					$coltypes = array();
					foreach($result_oldcols as $column_info) {
						$newcols[$column_info['name']] = $column_info['name'];
						$coltypes[$column_info['name']] = $column_info['type'];
					}
					$newcolumns = '';
					$oldcolumns = '';
					reset($newcols);
					while( list($key, $val) = each($newcols) ) {
						$newcolumns .= ($newcolumns?', ':'').$this->quote_id($val);
						$oldcolumns .= ($oldcolumns?', ':'').$this->quote_id($key);
					}
					$copytotempsql = 'INSERT INTO '.$this->quote_id($tmpname).'('.$newcolumns.') SELECT '.$oldcolumns.' FROM '.$this->quote_id($table);
					$dropoldsql = 'DROP TABLE '.$this->quote_id($table);
					$createtesttableSQL = $createtemptableSQL;
					if( count($defs) < 1 ) {
						return false;
					}
					foreach($defs as $def) {
						$parse_def = preg_match("/^(DROP|ADD|CHANGE|RENAME TO)\s+(?:\"((?:[^\"]|\"\")+)\"|'((?:[^']|'')+)')((?:\s+'((?:[^']|'')+)')?\s+(TEXT|INTEGER|BLOB|REAL).*)?\s*$/i",$def,$matches);
						if( $parse_def === false )
							return false;

						if( !isset($matches[1]) )
							return false;

						$action = strtolower($matches[1]);
						if($action == 'add' || $action == 'rename to')
							$column = str_replace("''","'",$matches[3]);		// enclosed in ''
						else
							$column = str_replace('""','"',$matches[2]);		// enclosed in ""

						$column_escaped = str_replace("'","''",$column);

						/* we build a regex that devides the CREATE TABLE statement parts:
						  Part example								Group	Explanation
						  1. CREATE TABLE t... (					$1
						  2. 'col1' ..., 'col2' ..., 'colN' ...,	$3		(with col1-colN being columns that are not changed and listed before the col to change)
						  3. 'colX' ...,							-		(with colX being the column to change/drop)
						  4. 'colX+1' ..., ..., 'colK')				$5		(with colX+1-colK being columns after the column to change/drop)
						*/
						$preg_create_table = "\s*(CREATE\s+TEMPORARY\s+TABLE\s+'?".preg_quote($tmpname,"/")."'?\s*\()";   // This is group $1 (keep unchanged)
						$preg_column_definiton = "\s*".$this->sqlite_surroundings_preg("+",false," '\"\[`")."(?:\s+".$this->sqlite_surroundings_preg("*",false,"'\",`\[) ").")+";		// catches a complete column definition, even if it is
														// 'column' TEXT NOT NULL DEFAULT 'we have a comma, here and a double ''quote!'
						$preg_columns_before =  // columns before the one changed/dropped (keep)
							"(?:".
								"(".			// group $2. Keep this one unchanged!
									"(?:".
										"$preg_column_definiton,\s*".		// column definition + comma
									")*".								// there might be any number of such columns here
									$preg_column_definiton.				// last column definition
								")".			// end of group $2
								",\s*"			// the last comma of the last column before the column to change. Do not keep it!
							.")?";    // there might be no columns before
						if($debug) echo "preg_columns_before=(".$preg_columns_before.")<hr />";
						$preg_columns_after = "(,\s*([^)]+))?"; // the columns after the column to drop. This is group $3 (drop) or $4(change) (keep!)
												// we could remove the comma using $6 instead of $5, but then we might have no comma at all.
												// Keeping it leaves a problem if we drop the first column, so we fix that case in another regex.
						$table_new = $table;

						switch($action)	{
							case 'add':
								if( !isset($matches[4]) ) {
									return false;
								}
								$new_col_definition = "'$column_escaped' ".$matches[4];
								$preg_pattern_add = "/^".$preg_create_table."(.*)\\)\s*$/";
								// append the column definiton in the CREATE TABLE statement
								$newSQL = preg_replace($preg_pattern_add, '$1$2, ', $createtesttableSQL).$new_col_definition.')';
								if( $newSQL==$createtesttableSQL ) // pattern did not match, so column removal did not succed
									return false;
								$createtesttableSQL = $newSQL;
								break;
							case 'change':
								if(!isset($matches[5]) || !isset($matches[6])) {
									return false;
								}
								$new_col_name = $matches[5];
								$new_col_type = $matches[6];
								$new_col_definition = "'$new_col_name' $new_col_type";
								$preg_column_to_change = "\s*".$this->sqlite_surroundings_preg($column)."(?:\s+".preg_quote($coltypes[$column]).")?(\s+(?:".$this->sqlite_surroundings_preg("*",false,",'\")`\[").")+)?";
												// replace this part (we want to change this column)
												// group $3 contains the column constraints (keep!). the name & data type is replaced.
								$preg_pattern_change = "/^".$preg_create_table.$preg_columns_before.$preg_column_to_change.$preg_columns_after."\s*\\)\s*$/";

								// replace the column definiton in the CREATE TABLE statement
								$newSQL = preg_replace($preg_pattern_change, '$1$2,'.strtr($new_col_definition, array('\\' => '\\\\', '$' => '\$')).'$3$4)', $createtesttableSQL);
								// remove comma at the beginning if the first column is changed
								// probably somebody is able to put this into the first regex (using lookahead probably).
								$newSQL = preg_replace("/^\s*(CREATE\s+TEMPORARY\s+TABLE\s+'".preg_quote($tmpname,"/")."'\s+\(),\s*/",'$1',$newSQL);
								if($newSQL==$createtesttableSQL || $newSQL=="") // pattern did not match, so column removal did not succed
									return false;
								$createtesttableSQL = $newSQL;
								$newcols[$column] = str_replace("''","'",$new_col_name);
								break;
							case 'drop':
								$preg_column_to_drop = "\s*".$this->sqlite_surroundings_preg($column)."\s+(?:".$this->sqlite_surroundings_preg("*",false,",')\"\[`").")+";      // delete this part (we want to drop this column)
								$preg_pattern_drop = "/^".$preg_create_table.$preg_columns_before.$preg_column_to_drop.$preg_columns_after."\s*\\)\s*$/";

								// remove the column out of the CREATE TABLE statement
								$newSQL = preg_replace($preg_pattern_drop, '$1$2$3)', $createtesttableSQL);
								// remove comma at the beginning if the first column is removed
								// probably somebody is able to put this into the first regex (using lookahead probably).
								$newSQL = preg_replace("/^\s*(CREATE\s+TEMPORARY\s+TABLE\s+'".preg_quote($tmpname,"/")."'\s+\(),\s*/",'$1',$newSQL);
								if($newSQL==$createtesttableSQL || $newSQL=="") // pattern did not match, so column removal did not succed
									return false;
								$createtesttableSQL = $newSQL;
								unset($newcols[$column]);
								break;
							case 'rename to':
								// don't change column definition at all
								$newSQL = $createtesttableSQL;
								// only change the name of the table
								$table_new = $column;
								break;
							default:
								if($default) echo 'ERROR: unknown alter operation!<hr />';
								return false;
						}
					}
					$droptempsql = 'DROP TABLE '.$this->quote_id($tmpname);

					$createnewtableSQL = "CREATE TABLE ".$this->quote($table_new)." ".preg_replace("/^\s*CREATE\s+TEMPORARY\s+TABLE\s+'?".str_replace("'","''",preg_quote($tmpname,"/"))."'?\s+(.*)$/i", '$1', $createtesttableSQL, 1);

					$newcolumns = '';
					$oldcolumns = '';
					reset($newcols);
					while(list($key,$val) = each($newcols))	{
						$newcolumns .= ($newcolumns?', ':'').$this->quote_id($val);
						$oldcolumns .= ($oldcolumns?', ':'').$this->quote_id($key);
					}
					$copytonewsql = 'INSERT INTO '.$this->quote_id($table_new).'('.$newcolumns.') SELECT '.$oldcolumns.' FROM '.$this->quote_id($tmpname);
				}
			}
			$alter_transaction  = 'BEGIN; ';
			$alter_transaction .= $createtemptableSQL.'; ';  //create temp table
			$alter_transaction .= $copytotempsql.'; ';       //copy to table
			$alter_transaction .= $dropoldsql.'; ';          //drop old table
			$alter_transaction .= $createnewtableSQL.'; ';   //recreate original table
			$alter_transaction .= $copytonewsql.'; ';        //copy back to original table
			$alter_transaction .= $droptempsql.'; ';         //drop temp table

			$preg_index="/^\s*(CREATE\s+(?:UNIQUE\s+)?INDEX\s+(?:".$this->sqlite_surroundings_preg("+",false," '\"\[`")."\s*)*ON\s+)(".$this->sqlite_surroundings_preg($table).")(\s*\((?:".$this->sqlite_surroundings_preg("+",false," '\"\[`")."\s*)*\)\s*;)\s*$/i";
			for($i=0; $i<sizeof($recreateQueries); $i++) {
				// recreate triggers / indexes
				if($table == $table_new) {
					// we had no RENAME TO, so we can recreate indexes/triggers just like the original ones
				    $alter_transaction .= $recreateQueries[$i];
				} else {
					// we had a RENAME TO, so we need to exchange the table-name in the CREATE-SQL of triggers & indexes
					// first let's try if it's an index...
					$recreate_queryIndex = preg_replace($preg_index, '$1'.$this->quote_id(strtr($table_new, array('\\' => '\\\\', '$' => '\$'))).'$3 ', $recreateQueries[$i]);
					if( $recreate_queryIndex != $recreateQueries[$i] && $recreate_queryIndex != NULL ) {
						// the CREATE INDEX regex did match
						$alter_transaction .= $recreate_queryIndex;
					} else {
						// the CREATE INDEX regex did not match, so we try if it's a CREATE TRIGGER

					    $recreate_queryTrigger = $recreateQueries[$i];
						// TODO: IMPLEMENT

						$alter_transaction .= $recreate_queryTrigger;
					}
				}
			}
			$alter_transaction .= 'COMMIT;';
			return $this->multiQuery($alter_transaction);
		}
	}

	//multiple query execution
	public function multiQuery($query) {
		$error = 'Unknown error.';
		if( $this->type == 'PDO' ) {
			$success = $this->db->exec($query);
			if( !$success ) $error =  implode(' - ', $this->db->errorInfo());
		} else if( $this->type == 'SQLite3' ) {
			$success = $this->db->exec($query);
			if( !$success ) $error = $this->db->lastErrorMsg();
		} else {
			$success = $this->db->queryExec($query, $error);
		}
		if( !$success ) {
			return "Error in query: '".htmlencode($error)."'";
		} else {
			return true;
		}
	}

	//get number of rows in table
	public function numRows($table) {
		$result = $this->select('SELECT Count(*) FROM '.$this->quote_id($table), DB_FETCH_NUM);
		return $result[0];
	}

	// Desc: determines if a row exists in the table (usually the primary key)
	// Param: (table name) to search in
	// Param: (column name) to search in
	// Param: (entry) to match against
	// returns: (true/false)
	public function rowExists($table, $where) {
		$whereSQL = '';
		if( is_array($where) ) {
			foreach( $where as $key => &$value )
				$value = "($key='$value')";
			$whereSQL = implode(' AND ', $where);
		} else {
			$whereSQL = $where;
		}

		$result = $this->select('SELECT Count(*) FROM '.$this->quote_id($table)." WHERE $whereSQL", DB_FETCH_NUM);

		return ( $result[0] != 0 );
	}

	//correctly escape a string to be injected into an SQL query
	public function quote($value) {
		if( $this->type == 'PDO' ) {
			// PDO quote() escapes and adds quotes
			return $this->db->quote($value);
		} else if( $this->type == 'SQLite3' ) {
			return "'".$this->db->escapeString($value)."'";
		} else if( $this->type == 'SQLSRV' ) {
			return "'".str_replace( "'", "''", $value )."'";
		} else {
			return "'".sqlite_escape_string($value)."'";
		}
	}

 	//correctly escape an identifier (column / table / trigger / index name) to be injected into an SQL query
	public function quote_id($value) {
		// double-quotes need to be escaped by doubling them
		$value = str_replace('"','""',$value);
		return '"'.$value.'"';
	}


	//import sql
	public function import_sql($query) {
		return $this->multiQuery($query);
	}

	//import csv
	public function import_csv($filename, $table, $field_terminate, $field_enclosed, $field_escaped, $null, $fields_in_first_row) {
		// CSV import implemented by Christopher Kramer - http://www.christosoft.de
		$csv_handle = fopen($filename,'r');
		$csv_insert = "BEGIN;\n";
		$csv_number_of_rows = 0;
		// PHP requires enclosure defined, but has no problem if it was not used
		if($field_enclosed=="") $field_enclosed='"';
		// PHP requires escaper defined
		if($field_escaped=="") $field_escaped='\\';
		while(!feof($csv_handle)) {
			$csv_data = fgetcsv($csv_handle, 0, $field_terminate, $field_enclosed, $field_escaped);
			if($csv_data[0] != NULL || count($csv_data)>1) {
				$csv_number_of_rows++;
				if($fields_in_first_row && $csv_number_of_rows==1) continue;
				$csv_col_number = count($csv_data);
				$csv_insert .= "INSERT INTO ".$this->quote_id($table)." VALUES (";
				foreach($csv_data as $csv_col => $csv_cell) {
					if( $csv_cell == $null )
						$csv_insert .= "NULL";
					else {
						$csv_insert.= $this->quote($csv_cell);
					}
					if($csv_col == $csv_col_number-2 && $csv_data[$csv_col+1]=='') {
						// the CSV row ends with the separator (like old phpliteadmin exported)
						break;
					}
					if($csv_col < $csv_col_number-1) $csv_insert .= ",";
				}
				$csv_insert .= ");\n";

				if($csv_number_of_rows > 5000) {
					$csv_insert .= "COMMIT;\nBEGIN;\n";
					$csv_number_of_rows = 0;
				}
			}
		}
		$csv_insert .= "COMMIT;";
		fclose($csv_handle);
		return $this->multiQuery($csv_insert);

	}

	//export csv
	public function export_csv($tables, $field_terminate, $field_enclosed, $field_escaped, $null, $crlf, $fields_in_first_row) {
		$field_enclosed = stripslashes($field_enclosed);
		$query = "SELECT * FROM sqlite_master WHERE type='table' or type='view' ORDER BY type DESC";
		$result = $this->selectArray($query);
		for($i=0; $i<sizeof($result); $i++) {
			$valid = false;
			for($j=0; $j<sizeof($tables); $j++) {
				if($result[$i]['tbl_name']==$tables[$j])
					$valid = true;
			}
			if( $valid ) {
				$query = "PRAGMA table_info(".$this->quote_id($result[$i]['tbl_name']).")";
				$temp = $this->selectArray($query);
				$cols = array();
				for($z=0; $z<sizeof($temp); $z++)
					$cols[$z] = $temp[$z][1];
				if( $fields_in_first_row ) {
					for( $z=0; $z<sizeof($cols); $z++ ) {
						echo $field_enclosed.$cols[$z].$field_enclosed;
						// do not terminate the last column!
						if($z < sizeof($cols)-1)
							echo $field_terminate;
					}
					echo "\r\n";
				}
				$query = "SELECT * FROM ".$this->quote_id($result[$i]['tbl_name']);
				$arr = $this->selectArray($query, "assoc");
				for($z=0; $z<sizeof($arr); $z++)
				{
					for($y=0; $y<sizeof($cols); $y++)
					{
						$cell = $arr[$z][$cols[$y]];
						if($crlf)
						{
							$cell = str_replace("\n","", $cell);
							$cell = str_replace("\r","", $cell);
						}
						$cell = str_replace($field_terminate,$field_escaped.$field_terminate,$cell);
						$cell = str_replace($field_enclosed,$field_escaped.$field_enclosed,$cell);
						// do not enclose NULLs
						if($cell == NULL)
							echo $null;
						else
							echo $field_enclosed.$cell.$field_enclosed;
						// do not terminate the last column!
						if($y < sizeof($cols)-1)
							echo $field_terminate;
					}
					if($z<sizeof($arr)-1)
						echo "\r\n";
				}
				if($i<sizeof($result)-1)
					echo "\r\n";
			}
		}
	}

	//export sql
	public function export_sql($tables, $drop, $structure, $data, $transaction, $comments) {
		if( $comments ) {
			echo "----\r\n";
			echo "-- phpLiteAdmin database dump (http://phpliteadmin.googlecode.com)\r\n";
			echo "-- phpLiteAdmin version: ".VERSION."\r\n";
			echo "-- Exported on ".date('M jS, Y, h:i:sA')."\r\n";
			echo "-- Database file: ".$this->getPath()."\r\n";
			echo "----\r\n";
		}
		$query = "SELECT * FROM sqlite_master WHERE type='table' OR type='index' OR type='view' OR type='trigger' ORDER BY type='trigger', type='index', type='view', type='table'";
		$result = $this->selectArray($query);

		if( $transaction )
			echo "BEGIN TRANSACTION;\r\n";

		//iterate through each table
		for($i=0; $i<sizeof($result); $i++)	{
			$valid = false;
			for($j=0; $j<sizeof($tables); $j++)	{
				if($result[$i]['tbl_name']==$tables[$j])
					$valid = true;
			}
			if($valid) {
				if($drop) {
					if($comments) {
						echo "\r\n----\r\n";
						echo "-- Drop ".$result[$i]['type']." for ".$result[$i]['name']."\r\n";
						echo "----\r\n";
					}
					echo "DROP ".strtoupper($result[$i]['type'])." ".$this->quote_id($result[$i]['name']).";\r\n";
				}
				if($structure) {
					if($comments) {
						echo "\r\n----\r\n";
						if($result[$i]['type']=="table" || $result[$i]['type']=="view")
							echo "-- ".ucfirst($result[$i]['type'])." structure for ".$result[$i]['tbl_name']."\r\n";
						else // index or trigger
							echo "-- Structure for ".$result[$i]['type']." ".$result[$i]['name']." on table ".$result[$i]['tbl_name']."\r\n";
						echo "----\r\n";
					}
					echo $result[$i]['sql'].";\r\n";
				}
				if( $data && $result[$i]['type']=="table" ) {
					$query = "SELECT * FROM ".$this->quote_id($result[$i]['tbl_name']);
					$arr = $this->selectArray($query, "assoc");

					if($comments) {
						echo "\r\n----\r\n";
						echo "-- Data dump for ".$result[$i]['tbl_name'].", a total of ".sizeof($arr)." rows\r\n";
						echo "----\r\n";
					}
					$query = "PRAGMA table_info(".$this->quote_id($result[$i]['tbl_name']).")";
					$temp = $this->selectArray($query);
					$cols = array();
					$cols_quoted = array();
					$vals = array();
					for($z=0; $z<sizeof($temp); $z++) {
						$cols[$z] = $temp[$z][1];
						$cols_quoted[$z] = $this->quote_id($temp[$z][1]);
					}
					for($z=0; $z<sizeof($arr); $z++) {
						for($y=0; $y<sizeof($cols); $y++) {
							if(!isset($vals[$z]))
								$vals[$z] = array();
							if($arr[$z][$cols[$y]] === NULL)
								$vals[$z][$cols[$y]] = 'NULL';
							else
								$vals[$z][$cols[$y]] = $this->quote($arr[$z][$cols[$y]]);
						}
					}
					for($j=0; $j<sizeof($vals); $j++)
						echo "INSERT INTO ".$this->quote_id($result[$i]['tbl_name'])." (".implode(",", $cols_quoted).") VALUES (".implode(",", $vals[$j]).");\r\n";
				}
			}
		}
		if($transaction)
			echo "COMMIT;\r\n";
	}
}
?>
