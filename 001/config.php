<?php

class Config {
	
	static $db;
	
	static $configKeys = array('home-page');
	static $config;

	static function load() {
		$query = 'SELECT * FROM ' . TABLE_CONFIG;
		
		$_configData = Config::$db->selectArray($query);		
		
		Config::$config = array_combine(array_column($_configData, 'key'), array_column($_configData, 'value'));
		
		foreach( Config::$configKeys as $key ) {
			if( !array_key_exists($key, Config::$config) )
				Config::$config[$key] = '';
		}
	}		

	static function save() {		
		foreach( Config::$configKeys as $key ) {
			if( array_key_exists($key, Config::$config) ) {
				Config::$db->replaceInto(TABLE_CONFIG, array('key' => $key, 'value' => Config::$config[$key]));
			}
		}
	}
	
	
	static function update($key, $value) {
		if( array_key_exists($key, Config::$config) ) {
			Config::$config[$key] = $value;
		}
	}
	
	static function batchUpdate($params) {
		foreach( Config::$configKeys as $key ) {
			if( array_key_exists($key, $params) ) {
				Config::$config[$key] = $params[$key];
			}
		}	
	}	
}

?>