<?php

class Group_Tasks_Model extends AbstractModel{
	
	public function __construct($db = null){
		parent::__construct($db);
		$this->pid       = posix_getpid();
	}
	
	
	public function get_new_task(){
		$query = "SELECT * FROM group_push_notifications WHERE result_process = 'Ready' AND schedule_datetime < now()  ORDER BY schedule_datetime LIMIT 1";
		
		$data = array();
		if (FALSE === ($res = $this->safe_query($query, array()))){
			return $data;
		}
		else{
			$data = $res->first_row('array');
			$res->free_result();
		}
		
		if (!empty($data) && ($data['task_id'] > 0)){
			$query = "UPDATE group_push_notifications SET result_process = 'Processing' WHERE task_id = ?";
			if (FALSE === ($res = $this->safe_query($query, array( (int) $data['task_id'] )))){
				return $data;
			}			
		}
		return $data;
	}
	
	public function getTaskInfo($task_id = 0){
		$query = "SELECT * FROM group_push_notifications WHERE task_id = ?";
		
		$data = array();
		if (FALSE === ($res = $this->safe_query($query, array( (int) $task_id )))){
			return $data;
		}
		else{
			$data = $res->first_row('array');
			$res->free_result();
			return $data;
		}
	}
	
	public function setTaskFinished($task_id = 0, $users_count = 0){
		$query = "UPDATE group_push_notifications SET users_count = ?, result_process = 'Finished' , result_process_datetime = now() WHERE task_id = ?";
		if (FALSE === ($res = $this->safe_query($query, array( (int) $users_count, (int) $task_id)))){
			return FALSE;
		}
		else{
			return TRUE;
		}
	}
	
	public function updatTaskStatus($task_id = 0, $users_count = 0){
		$query = "UPDATE group_push_notifications SET users_count = ?, result_process_datetime = now() WHERE task_id = ?";		
		if (FALSE === ($res = $this->safe_query($query, array( (int) $users_count, (int) $task_id)))){
			return FALSE;
		}
		else{
			return TRUE;
		}
	}
	
	public function plusTaskSuccessCount($task_id = 0){
		$query = "UPDATE group_push_notifications SET users_success_count = users_success_count + 1 WHERE task_id = ?";
		if (FALSE === ($res = $this->safe_query($query, array( (int) $task_id)))){
			return FALSE;
		}
		else{
			return TRUE;
		}
	}
	
	public function plusTaskFailedCount($task_id = 0){
		$query = "UPDATE group_push_notifications SET users_failed_count = users_failed_count + 1 WHERE task_id = ?";
		if (FALSE === ($res = $this->safe_query($query, array( (int) $task_id)))){
			return FALSE;
		}
		else{
			return TRUE;
		}
	}
	
	public function prepare_user_list($task_id = 0, $notify_app_id = 0, $notify_platform = ''){
		$count = 0;
		
		//remove old data
		$t = $task_id - 10;
		if ($t > 0){
			$query = "DELETE FROM group_push_users WHERE task_id  <= ?";
			$this->safe_query($query, array((int) $t));
			$this->my_echo("Done to clean up old history before the task_id = $t");
		}
		
		//prepare new task user data
		$query = "SELECT COUNT(*) AS count FROM group_push_users WHERE task_id = ?";		
		if (FALSE === ($res = $this->safe_query($query, array((int) $task_id)))){
			$this->my_echo($this->db->error_msg, 'ERROR');
			return -1;
		}
		else{
			$data = $res->first_row('array');
			$res->free_result();			
		}
		
		$user_amount = $data['count'];
		
		if ($user_amount == 0){			
			if ( $notify_platform == 'All'){
				$query = "INSERT INTO fantasy_engine.group_push_users (task_id, playit_id, user_platform, app_id, push_hash, push_token, sent, sent_datetime) SELECT $task_id, playit_id, platform, app_id, push_hash, push_token, 'N', '0000-00-00 00:00:00' FROM api_notifications.push_tokens WHERE app_id = $notify_app_id";				
			}
			else{
				$platforms = explode(',', $notify_platform);
				$sql_platform = "('". implode("','", $platforms) . "')";
				$query = "INSERT INTO fantasy_engine.group_push_users (task_id, playit_id, user_platform, app_id, push_hash, push_token, sent, sent_datetime) SELECT $task_id, playit_id, platform, app_id, push_hash, push_token, 'N', '0000-00-00 00:00:00' FROM api_notifications.push_tokens WHERE app_id = $notify_app_id AND platform IN $sql_platform";
			}
			
			//$this->my_echo($query);					
			if (FALSE === ($res = $this->safe_query($query, array()))){
				$this->my_echo($this->db->error_msg, 'ERROR');
				return -1;
			}
			//start from sending message from 0.
			$count = 0;
			$this->my_echo("Finished to prepare the users list for task_id = $task_id");
		}
		else{			
			$query = "SELECT COUNT(*) AS count FROM group_push_users WHERE task_id = ?  AND sent = 'Y'";
			
			if (FALSE === ($res = $this->safe_query($query, array((int) $task_id)))){
				$this->my_echo($this->db->error_msg, 'ERROR');
				return -1;
			}
			else{
				$data = $res->first_row('array');
				$res->free_result();
			}			
			$count = $data['count'];
			$this->my_echo("Users list exists already for task_id = $task_id with total user amount = $user_amount and $count users finished to send message already.");
		}
		
		return $count;
	}
}