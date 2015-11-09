<?php

class Group_Users_Model extends AbstractModel{
	public function __construct($db = null){
		parent::__construct($db);
		$this->pid       = posix_getpid();
	}

	
	public function getUnSendUsersList($task_id = 0, $limit = 300){
		$query = "SELECT * FROM group_push_users WHERE task_id = ? AND sent = 'N' ORDER BY pu_id LIMIT $limit";		
		$this->my_echo($query);
		
		$data = array();
		if (FALSE === ($res = $this->safe_query($query, array( (int) $task_id )))){
			return $data;
		}
		else{
			$data = $res->result('array');
			$res->free_result();
			return $data;
		}
	}
	
	public function setPushSent($pu_id = 0){
		$query = "UPDATE group_push_users SET sent = 'Y', sent_datetime = now() WHERE pu_id = ?";
				
		if (FALSE === ($res = $this->safe_query($query, array( (int) $pu_id)))){
			return FALSE;
		}
		return TRUE;
	}
	
	public function setUserDeliveryReport($task_id= 0, $pu_id = 0, $status = '', $status_code = ''){
		$query = "UPDATE group_push_users SET sent = 'Y', sent_datetime = now(), status = ?, status_code = ? WHERE pu_id = ? AND task_id = ?";
		
		if (FALSE === ($res = $this->safe_query($query, array( (string) $status, (string) $status_code,  (int) $pu_id, (int) $task_id )))){
			return FALSE;
		}
		return TRUE;
	}
	
}
