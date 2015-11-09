<?php
/*
 * Group Push Notification Class
*
* @copyright 2014 Play It Interactive Pty Ltd
* @license
* @version	v1.0.1
* @author	Johnson Song (johnson@playitinteractive.com)
*/


class Group_Notification extends AbstractModel{
	public $is_master     = TRUE;
    public $_children     = 0;
    public $_running      = FALSE;
    
    
    public $fifoFd        = NULL;
    public $fifo_path     = '';
	
	public $spawn_number  = 3; //when we have new task, how many child process we will fork to delivey the message.
	public $eventBase     = NULL;
	public $fifoEvent     = NULL;
	
	
	function __construct($args = array()){
		$this->pid       = posix_getpid();
		$this->_running  = TRUE;
		$this->runName   = basename($_SERVER['argv'][0]);
		$this->fifo_path = dirname(dirname(__FILE__))."/data/fifo_group_messages";
	}
	
	function __destruct() {
		if ($this->db){
			$this->db->close();
		}		
	}
	
	public function run(){
		$this->init_system_env();		
		
		//when service restart, clean up all old fifo files.
		$this->clean_fifo();
		$this->setproctitle($this->runName.' master process');		
		$this->db_connect();

		$try  = 1;
		while ($this->_running) {
			$Grouup_Tasks_Model = new Group_Tasks_Model($this->db);
			
			$this->my_echo("=========================================== [Master] Fetch new task now.              ===========================================");
			$task = $Grouup_Tasks_Model->get_new_task();
			
			if (!empty($task)){
				$task_id         = $task['task_id'];
				$notify_app_id   = $task['notify_app_id'];
				$notify_platform = $task['notify_platform'];
				
				$this->my_echo("Get new tas taskID=$task_id notify_app_id = $notify_app_id, notify_platform = $notify_platform");
				$start = $Grouup_Tasks_Model->prepare_user_list($task_id, $notify_app_id, $notify_platform);
				
				if ($start == -1){
					$this->my_echo("Failed to prepare user list for task task_id = $task_id with try [$try] times", 'WARNING');
					$try++;					
					
					if ($try <= 5){
						continue;//try again.
					}
					else{
						$Grouup_Tasks_Model->setTaskFinished($task_id, 0);//disable this task
						$this->my_echo("Failed to prepare user list for task task_id = $task_id and give up now.", 'ERROR');
						$try  = 1;
					}			
				}
				else{
					$this->my_echo("Start process new task with task_id = $task_id and sent user_count = $start");
					
					$task_info = $Grouup_Tasks_Model->getTaskInfo($task_id);
					if (!empty($task_info) && ($task_info['result_process'] == 'Processing')) {						
						$this->_execute($task_info, $start);
					}
					sleep(1);					
				}				
			}
			else{
				$this->my_echo("=========================================== [Master] No task found, continue to loop. ===========================================");
				$this->db->close();
								
				//no new task, still have child runnning?
				if ($this->_children > 0){
					sleep(5);//sleep for 5 minutes
					//break; //change the daemon service to run the service as cron job.
				}
				else{
					$this->my_echo("Finished to do the job for this time. Exit");
					$this->clean_fifo();
					break;
				}
			}			
		}
		return TRUE;
		
	}
	
	public function _execute($task_info = array(), $start = 0){
		$task_id = $task_info['task_id'];
		while(1){
			$pid = pcntl_fork();
	
			if ($pid == -1){
				//fail
				error_log("Failed to fork new process.");
			}
			else if ($pid == 0){
				//child
				$this->setproctitle($this->runName.' task['.$task_id.'] task process');
				$this->is_master = TRUE;
				$this->pid       = posix_getpid();
				$this->_children = 0;
	
				//close the mysql connection and open new one for itself
				$this->db->close();
				$this->db_connect();
	
				$this->my_echo("Success to fork a new child for task_id = $task_id");
	
				//start the work
				$this->task_run($task_info, $start);
				$this->db->close();
				exit(0);
			}
			else{
				//parent
				++$this->_children;
				return TRUE;
			}
		}		
	}
	
	private function task_run($task_info = array(), $start = 0){				
		$Group_Tasks_Model = new Group_Tasks_Model($this->db);		
		$task_id           = $task_info['task_id'];
		
		//fork the workers
		$this->spawn_workers($task_id, $this->spawn_number);
		$this->my_echo("task[$task_id] success to spawn ".$this->spawn_number." workers.");
		
		//send task;
		list($count, $status, $last_sent_num) = $this->task_send($task_info, $start);
		$this->my_echo("task[$task_id] get back from task_run with users=$count and status=$status and last_sent_num = $last_sent_num.");
		
		if ($status == 'finish'){
			$Group_Tasks_Model->setTaskFinished($task_id, $count);
			$this->my_echo("task[$task_id] update task with result finish");
		}
		else{
			$Group_Tasks_Model->setTaskFinished($task_id, 0);
			$this->my_echo("task[$task_id] update task with result stop", 'ERROR');
		}
		
		//send finish command to worker process to exit.
		while($this->_children > 0){
			$this->my_echo("task[$task_id] TASK PROCESS finished to send group message and wait for child to finish. ".$this->_children);
			$this->finish_task_worker($task_id);
			sleep(1);
		}
		
		$this->my_echo("task[$task_id] finished and exit.");
		
		
		//testing
		//send report to the admin user email		
		$row_task = $Group_Tasks_Model->getTaskInfo($task_id);
		
		if (!empty($row_task)){
		
			$report_email_message = <<<EOF
Group Messages(Notifications) Report

Notification Title:         {$row_task['notify_title']}
Notification Message:       {$row_task['notify_message']}
App_id:                     {$row_task['notify_app_id']}
Platform:                   {$row_task['notify_platform']}
Created Time:               {$row_task['created_datetime']}
ScheduleTime:               {$row_task['schedule_datetime']}

Result

Processed Time:             {$row_task['result_process_datetime']}
User Amount :               {$row_task['users_count']}

Task Dashboards
	http://fantasy.admin.playitgame.com/index.php/tools/group_notification
		
		
EOF;
		
			$cmd = 'echo "'.$report_email_message.'" | mutt -s \'Group Messages(notifications) Report\' johnson@playitinteractive.com,jason@playitinteractive.com';
			system($cmd);
		}
		
		
	}
		
	private function finish_task_worker($task_id = 0){
		$args['action'] = 'finish';
		$xml = $this->build_XML($task_id, $args);
		$this->my_echo("task[$task_id] send finish command.");
		$fifo_path = $this->fifo_path;
		if (file_exists($fifo_path)){
			$files = glob($fifo_path.'/fifo_buffer_'.$task_id.'_{*}.fifo', GLOB_BRACE);
			foreach ($files as $file){
				$this->send_xml($file, $xml);
			}
		}
	}
	
		
		
	private function task_send($task_info = array(), $start_n = 0){
		$task_id = $task_info['task_id'];
		$Group_Tasks_Model  = new Group_Tasks_Model($this->db);
		$Group_Users_Model  = new Group_Users_Model($this->db);
		
		$start = ($start_n > 0)? $start_n : 0;
		
		//testing
		$num   = 100;
		//$num = 1;
		
		$index = $start;
		$last_sent_num = 0;
		while(1) {
			//get task info
			$row_task = $Group_Tasks_Model->getTaskInfo($task_id);			
			if (!empty($row_task)) {
				$last_sent_num = $row_task['users_count'];				
				
				$myrow = $Group_Users_Model->getUnSendUsersList($task_id, $num);				
				//$start += $num;
				
				if (empty($myrow)) {
					$this->my_echo("No user found need to send message or all done.", 'WARNING');
					break;
				}
				
				foreach ($myrow as $row) {					
					if ($row['sent'] == 'Y'){//this user has been sent before.
						continue;
					}
					
					//try to send for 5 seconds
					$try = 5;
					do {
						$res = $this->sendPushNotification($task_info, $row);
						$try--;
						
						if($res){
							break;
						}
						else{
							sleep(1);
							$this->my_echo("Failed to send push request , try again later.");
						}
					}while($try > 0);

					$Group_Users_Model->setPushSent($row['pu_id']);
					$index++;
				}
				
				//update status
				if ($index > $last_sent_num){
					$Group_Tasks_Model->updatTaskStatus($task_id, $index);
					$this->my_echo("Update task task_id = $task_id to set user_count = $index");
				}
				//$this->my_echo("\n----------------------------------------------------------\n");
				sleep(1);//give other service some time. do not do too much job to DB.
				
				//testing
				//return array($index, 'finish', $last_sent_num);
			}
			else{
				$this->my_echo("Failed to get task info by task_id = $task_id", 'ERROR');
				return array($index, 'stop', $last_sent_num);
			}
		}
		
		
		return array($index, 'finish', $last_sent_num);
				
	}
	
	
	private function sendPushNotification($task_info = array(), $user_info = array()){
		$task_id           = isset($task_info['task_id'])?             $task_info['task_id']                 : 0;
		$notify_title      = isset($task_info['notify_title'])?        $task_info['notify_title']            : '';
		$notify_message    = isset($task_info['notify_message'])?      $task_info['notify_message']          : '';
		$notify_platform   = isset($task_info['notify_platform'])?     $task_info['notify_platform']         : '';
		$notify_app_id     = isset($task_info['notify_app_id'])?       $task_info['notify_app_id']           : 0;
		$notify_nav_tag    = isset($task_info['notify_nav_tag'])?      $task_info['notify_nav_tag']          : '';
		$notify_nav_args   = isset($task_info['notify_nav_args'])?     $task_info['notify_nav_args']         : '';
		
		$playit_id         = isset($user_info['playit_id'])?           $user_info['playit_id']               : 0;
		$user_platform     = isset($user_info['user_platform'])?       $user_info['user_platform']           : '';
		$push_token        = isset($user_info['push_token'])?          $user_info['push_token']              : '';
		$sent              = isset($user_info['sent'])?                $user_info['sent']                    : 'Y';
		
		$pu_id             = isset($user_info['pu_id'])?               $user_info['pu_id']                   : 0;
		
		if (($task_id > 0) && !empty($notify_message) && !empty($user_platform)){
			$fifo = $this->get_task_fifo_rand($task_id);
			
			if (!empty($fifo)){
				$XML = $this->build_XML($task_id, array('action' => 'send', 'task_id' => $task_id, 'notify_app_id' => $notify_app_id, 'notify_title' => $notify_title, 'notify_message' => $notify_message, 'pu_id' => $pu_id, 'playit_id' => $playit_id, 'user_platform' => $user_platform, 'push_token' => $push_token, 'notify_nav_tag' => $notify_nav_tag, 'notify_nav_args' => $notify_nav_args));
				//print "fifo $fifo \n$XML\n\n";
				$res = $this->send_xml($fifo, $XML);
				if ($res){
					$this->my_echo("Success to send request with pu_id = $pu_id, task_id = $task_id user_platform = $user_platform playit_id = $playit_id via fifo file $fifo");
				}
				else{
					$this->my_echo("Failed to send request with pu_id = $pu_id, task_id = $task_id user_platform = $user_platform playit_id = $playit_id via fifo file $fifo", 'WARNING');
				}
				return $res;
			}
			else{
				$this->my_echo("Failed to get a rand fifo file", 'WARNING');
			}		
		}
		else{
			$this->my_echo("Parameters checking error: task_id = $task_id notify_message = $notify_message user_platform = $user_platform ", 'WARNING');
		}
		return FALSE;
	}
	
	public function build_XML($task_id = 0, $args = array()){
		$notify_app_id  = isset($args['notify_app_id'])?      $args['notify_app_id']     : 0;
		$notify_title   = isset($args['notify_title'])?       $args['notify_title']      : '';
		$notify_message = isset($args['notify_message'])?     $args['notify_message']    : '';
		$playit_id      = isset($args['playit_id'])?          $args['playit_id']         : 0;
		$pu_id          = isset($args['pu_id'])?              $args['pu_id']             : 0;
		$user_platform  = isset($args['user_platform'])?      $args['user_platform']     : '';
		$push_token     = isset($args['push_token'])?         $args['push_token']        : '';
		$notify_nav_tag    = isset($args['notify_nav_tag'])?      $args['notify_nav_tag']          : '';
		$notify_nav_args   = isset($args['notify_nav_args'])?     urlencode($args['notify_nav_args'])         : '';
		
		
		$xmlstr = "
<?xml version=\"1.0\" encoding=\"UTF-8\"?>
<group_message>
    <action>".$args['action']."</action>
    <taskID>".$task_id."</taskID>
    <notify_app_id>".$notify_app_id."</notify_app_id>
    <notify_title>".$notify_title."</notify_title>
	<notify_message>".$notify_message."</notify_message>
	<pu_id>".$pu_id."</pu_id>
	<playit_id>".$playit_id."</playit_id>
	<user_platform>".$user_platform."</user_platform>
	<notify_nav_tag>".$notify_nav_tag."</notify_nav_tag>
	<notify_nav_args>".$notify_nav_args."</notify_nav_args>
	<push_token>".$push_token."</push_token>
</group_message>";
		//$xmlstr = str_replace(array("\n", "\r"), "", $xmlstr);
		//echo "-----------\n$xmlstr\n=-----\n";
		return $xmlstr;
		
	}
	
	private function send_xml($fifo, $xml){
		//$fifoFile = $this->fifo_path.'/'.$fifo;
		//$this->my_echo("write xml to file $fifoFile $xml \n----------------\n");
		
		$fifoFile = $fifo;
		if (($fd = fopen($fifoFile, 'w')) == FALSE){
			$this->my_echo("Failed to open fifo $fifo", 'ERROR');
			return FALSE;
		}
		else{
			$data = sprintf("%-2048s",$xml);
			fwrite($fd, $data, 2048);
			fclose($fd);
			//print "===== finish to write \n";
			//sleep(2);
			return TRUE;
		}
	}
	
	private function get_task_fifo_rand($task_id){
		$fifo_path = $this->fifo_path;
		$fifoArray = array();
	
		if (file_exists($fifo_path)){
			$try_time = 60; //try 1 minutes
			do{
				//$files = glob($fifo_path.'/fifo_buffer_'.$task_id.'_{*}.fifo', GLOB_BRACE);
				$files = $this->get_fifo_files($fifo_path, $task_id);
				if (!empty($files)){
					break;
				}
				$try_time--;
				sleep(1);
			}while($try_time > 0);
			 
			if (empty($files)){
				$this->my_echo('Error: '.__FILE__.' at '.__LINE__." Failed to get the fifo file list for 1 minutes ", 'ERROR');
				return '';
			}
			 
			foreach ($files as $file){
				$r_file = explode('/', $file);
				$count  = count($r_file) -1;
				$fifo   = $r_file[$count];
				if ($fifo) {
					$fifoArray[] = $fifo;
				}
			}
			
			if ($fifoArray){
				$count = count($fifoArray) - 1;
				srand((double)microtime()*1000000);
				$index = rand(0, $count);
				if (isset($fifoArray[$index])){
					return $fifo_path.'/'.$fifoArray[$index];
				}
			}
		}
		else{
			$this->my_echo("Error: Not found the fifo files path ".$fifo_path, 'ERROR');
			return '';
		}
		
	}
	
	private function get_fifo_files($path = '', $task_id = 0){
		$fifo_file_array = array();
		if (file_exists($path)){
			if ($handle = opendir($path)) {
				while (false !== ($file = readdir($handle))) {
					if (preg_match("/fifo_buffer_${task_id}_(\d+)\.fifo/", $file)){
						$fifo_file_array[] = $path.'/'.$file;
					}
				}
				closedir($handle);
			}
		}
		return $fifo_file_array;
	}
	
	
		
	private function spawn_workers($task_id, $n = 5) {
		$n = (int) $n;
		
		for ($i = 0; $i < $n; ++$i) {
			$pid = pcntl_fork();
		
			if ($pid === -1) {
				throw new Exception('Could not fork');
			}
			elseif ($pid == 0) {//child
				$this->setproctitle($this->runName.' task['.$task_id.'] worker process');
				$this->is_master = FALSE;
				$this->pid       = posix_getpid();
				$this->_children = 0;
		
				//close the mysql connection and open new one for itself
				$this->db->close();
		
				$this->work_run($task_id);
				//$this->shutdown();
				exit(0);
			}
			else{
				++$this->_children;
			}
		}
		return true;
	}
		
	private function work_run($task_id = 0){
		$this->eventBase = event_base_new();
		$fifo_file       = $this->openFifos($task_id);
		event_base_loop($this->eventBase);
		$this->my_echo("Listening ont the FIFO file $fifo_file");
	}

	private	function openFifos($task_id = 0){
		$fifoFile = $this->fifo_path."/fifo_buffer_".$task_id.'_'.$this->pid.".fifo";
		if (($this->fifoFd = $this->readFifo($fifoFile)) == FALSE){
			error_log("Failed to open fifo file ".$fifoFile);
			exit(0);
		}
		
		$this->add_fifo_event($this->fifoFd, $fifoFile, $task_id);
		return $fifoFile;
	}
	
	public function readFifo($fifo){
		$create = 0;
		if (file_exists($fifo)){
			if (filetype($fifo) != 'fifo'){//delete it and create new one.
				unlink($fifo);
				$create = 1;
			}
		}
		else{
			$create = 1;
		}
		
		if ($create == 1){
			umask(0);
			$mode = 0666;
			if (!posix_mkfifo($fifo, $mode)) {
				error_log(__METHOD__ . 'Failed to create fifo file '.$fifo);
				return FALSE;
			}
		}
		
		
		if (($fd = fopen($fifo, 'r+')) == FALSE) {
			error_log(__METHOD__ . "Open pipe {$fifo} for read error.");
			error_log();
			return FALSE;
		}
		stream_set_blocking($fd, false);
		return $fd;
	}
	
	private function add_fifo_event($fd, $fifo, $task_id){
		$ev = event_new();
		if (
			!event_set(
					$ev,
					$fd,
					EV_READ | EV_PERSIST,
					array($this,'event_fifo_handle'),
					array($fifo, $task_id)
			)
		) {
			throw new Exception('Cannot event_set for fifo handle');
		}
		
		event_base_set($ev, $this->eventBase);
		event_add($ev);
		
		$this->fifoEvent = array($ev, $fifo, $task_id);
		//$this->my_log("=============$fd $ev, $fifo");
	}
	
	private function event_fifo_handle($stream, $flag, $args){
		$xml_data 	= fread($stream, 2048);
		$xml_data 	= trim($xml_data);
		$xml_array 	= $this->xml_to_array($xml_data);
		
		if (isset($xml_array['action']) && ($xml_array['action'] == 'send')){
			//send group messsage
			$Group_Send_Message = new Group_Send_Message();
			$res = $Group_Send_Message->sendMessage($xml_array);
			
			//$this->db_connect();
		
			
			$delivery_report = 'UNKNOW';
			if (isset($res->error_code) && isset($res->error_message)){
				if (($res->error_code == '0') && empty($res->error_message)){
					$delivery_report = 'SUCCESS';
				}
				else{
					$delivery_report = $res->error_code;
				}
			}
			
			
			$Group_Tasks_Model  = new Group_Tasks_Model($this->db);
			$Group_Users_Model  = new Group_Users_Model($this->db);
			
			if ($delivery_report === 'SUCCESS'){
				$Group_Tasks_Model->plusTaskSuccessCount($xml_array['taskID']);
				$Group_Users_Model->setUserDeliveryReport($xml_array['taskID'], $xml_array['pu_id'], 'SUCCESS', '');
			}
			else if ($delivery_report === 'UNKNOW'){
				$Group_Tasks_Model->plusTaskFailedCount($xml_array['taskID']);
				$Group_Users_Model->setUserDeliveryReport($xml_array['taskID'], $xml_array['pu_id'], 'UNKNOW', '');
			}
			else{
				$Group_Tasks_Model->plusTaskFailedCount($xml_array['taskID']);
				$Group_Users_Model->setUserDeliveryReport($xml_array['taskID'], $xml_array['pu_id'], 'FAILED', $delivery_report);
			}
			
			
			//$this->my_echo("Send message to taskID = ".$xml_array['taskID']." playit_id = ".$xml_array['playit_id']." user_platform = ".$xml_array['user_platform']." push_token = ".$xml_array['push_token']. " Delivery Report = $delivery_report");
		}
		else if (isset($xml_array['action']) && ($xml_array['action'] == 'finish')){
			$this->finish_worker();
		}
		
	}
	
	
	private function finish_worker(){
		if ($this->fifoFd){fclose($this->fifoFd);}
	
		$event = $this->fifoEvent;
		if ($event[0]){event_del($event[0]);}
		if (file_exists($event[1])){unlink($event[1]);}
		//if ($this->eventBase){event_free($this->eventBase);}
	
		$file_info = pathinfo($event[1]);
		$this->my_echo("Child WORKER PROCESS FINISHED AND EXIT AFTER REMOVE THE FIFO FILE = ".$file_info['filename']);
		exit(0);
	}
	
	
	private function xml_to_array($xml_buf){
		$vals = $index = $array = array();
		$parser = xml_parser_create('UTF-8');
		xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
		xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
		$valid = xml_parse_into_struct($parser, $xml_buf, $vals, $index);
		xml_parser_free($parser);
		
		$children = array();
		$i = 0;
		while(++$i < count($vals)){
			if (isset($vals[$i]['type'])){
				switch ($vals[$i]['type']){
					case 'open':
						break;
					case 'complete':
						if (isset($vals[$i]['tag'])){
							$tag_name = $vals[$i]['tag'];
							$children[$tag_name] = (isset($vals[$i]['value'])) ? $vals[$i]['value'] : '';
						}
						break;
					case 'close':
						//return $children;
						break;
	
				}
			}
		}
		
		return $children;
	}
	
	private function init_system_env(){
		set_time_limit(0);
	
		$signals = array(
				SIGCHLD => "SIGCHLD",
				SIGCLD  => "SIGCLD",
				SIGINT  => "SIGINT",
				SIGHUP  => "SIGHUP",
				SIGQUIT => "SIGQUIT",
		);
	
		if (version_compare(phpversion(), "5.3.0", "lt")) {
			// tick use required as of PHP 4.3.0
			declare(ticks = 1);
		}
	
		foreach ($signals as $signal => $name) {
			if (!pcntl_signal($signal, array($this, "handler"))) {
				die("Install signal handler for {$name} failed");
			}
		}
	
		
	}
	
	public function handler($signo) {
	
		$status = null;
		switch(intval($signo)) {
			case SIGCLD:
			case SIGCHLD:
				//$this->my_echo("[Master] catch the singnal with SIGCHLD");
				// declare = 1, that means one signal may be correspond multi-process die
				while( ($pid = pcntl_wait($status, WNOHANG|WUNTRACED)) > 0 ) {
					if (FALSE === pcntl_wifexited($status)) {
						//$this->my_echo("[Master] sub proccess {$pid} exited unormally with code {$status} ". __METHOD__);
					} else {
						//$this->my_echo("[Master] sub proccess {$pid} exited normally ". __METHOD__);
					}
					$this->_children--;
					$this->_children = ($this->_children < 0)? 0 : $this->_children;
				}
				break;
			case SIGINT:
			case SIGQUIT:
			case SIGHUP:
				$this->my_echo("PID[".$this->pid."] catch the singnal with SIGHUP");
				$this->_cleanup();
				exit(0);
				break;
			default:
				break;
		}
	}
	
	private function _cleanup() {
		$status = null;
		if (!$this->is_master) {
			return;
		}
	
		$this->_running = FALSE;
	
		while ($this->_children > 0) {
			//$this->my_echo("[Master] Children process has ".$this->_children);
	
			$pid = pcntl_wait($status, WNOHANG | WUNTRACED);
			if ($pid > 0) {
				if (FALSE === pcntl_wifexited($status)) {
					//$this->my_echo("[Master] sub proccess {$pid} exited unormally with code {$status} ". __METHOD__);
				} else {
					//$this->my_echo("[Master] sub proccess {$pid} exited normally ". __METHOD__);
				}
				$this->_children--;
			} else {
				continue;
			}
		}
	}
	
		
	private function clean_fifo(){
		$data_path = dirname(dirname(__FILE__))."/data";
		if (!file_exists($data_path)){
			if (!mkdir($data_path, 0700)) {
				die('Failed to create folders...'.$data_path);
			}
		}
		
		
		$fifo_path = $this->fifo_path;
		if (file_exists($fifo_path)){
			$files = glob($fifo_path.'/{*}.fifo', GLOB_BRACE);
			foreach ($files as $file){
				if (file_exists($file)){
					//error_log("unlink file $file");
					unlink($file);
				}
			}
		}
		else{
			if (!mkdir($fifo_path, 0700)) {
				die('Failed to create folders...'.$fifo_path);
			}
		}
	}
		
	
	
	private function setproctitle($title) {
		if (function_exists('setproctitle')) {
			return setproctitle($title);
		}
		return FALSE;
	}
	
	
	private function db_connect(){
		$this->db = new mydb (array('hostname' => DB_HOST, 'username' => DB_USER, 'password' => DB_PASSWORD, 'database' => DB_NAME, 'db_debug' => FALSE));
	}
	
	
}
		