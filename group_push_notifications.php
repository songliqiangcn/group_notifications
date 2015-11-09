<?php
if (extension_loaded('newrelic'))
{ newrelic_set_appname('DAEMON: FE Points'); }


/**
 * Batch to send push notifications
 * 
 * @author Johnson Song <johnson@playitinteractive.com>
 * @copyright Play It Gaming Inc.
 * @version v1.0.1
 *
 * If you have any questions or comments, please email: johnson@playitinteractive.com
 */

error_reporting(E_ALL);
set_time_limit(0);

include_once(dirname(__FILE__).'/include/include.const.php');

$script_pid_file = '/tmp/.group_push_notifications_process_id';
//run now?
if (file_exists($script_pid_file) && is_readable($script_pid_file)){
	$pid = file_get_contents($script_pid_file);
	$pid = (int) trim($pid);
	if (isPidRunning($pid)){
		error_log('['.date("Y-m-d H:i:s").'] The daemon script group_push_notifications.php is still running now. Try next time.');
		exit(0);
	}
}

$pid = posix_getpid();
if (file_put_contents($script_pid_file, $pid) === FALSE){
	error_log('['.date("Y-m-d H:i:s").'] The daemon script group_push_notifications.php has problem to write process ID into file '.$script_pid_file);
	exit(0);
}


$work = new Group_Notification();
$work->run();

//remove the lock file, change the daemon service to run the service as cron job.
if (file_exists($script_pid_file) && is_readable($script_pid_file)){
	unlink($script_pid_file);
}

//-----------------------------------------------------------------------------
function isPidRunning($pid) {
	$lines_out = array();
	exec('ps '.(int)$pid, $lines_out);
	if(count($lines_out) >= 2) {
		// Process is running
		return true;
	}
	return false;
}


