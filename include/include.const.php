<?php

date_default_timezone_set('UTC');

foreach (array(dirname(dirname(__FILE__))) as $d) {
	if (@chdir($d)){
		break;
	}
}
ini_set('include_path', get_include_path()
. ':' . dirname(dirname(dirname(__FILE__))).'/lib'
		. ':' . dirname(dirname(__FILE__)).'/class'
				. ':' . dirname(dirname(__FILE__)).'/lib'
);

spl_autoload_register(function($classname) {
	$path = 'class_'.$classname.'.php';
	//error_log("Try to load class $path \n");
	if (@include($path)) {
		return;
	}

});



	require_once 'playit/engine.fantasy.php';
	require_once 'playit/pig.cache.php';
	require_once 'playit/api.libraries/Notification_API.php';
	require_once 'playit/api.libraries/Leaderboard_API.php';
	

?>
