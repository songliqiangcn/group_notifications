<?php
class AbstractModel
{
	public $logtostderr   = FALSE;//write the all message to STDERR or log file, True to STDERR, False to log file. 
	
	public $pid           = 0;
	public $logPath       = '/logs/group_push_notification';
	public $logFile       = '';	
	public $db            = NULL;
	public $runName       = '';
	
    public function __construct(mydb $db){
        $this->db = $db;
        
        if (!file_exists($this->logPath)){
        	if (!mkdir($this->logPath, 0700)) {
        		die('Failed to create folders...'.$this->logPath);
        	}
        }
        
        
    }
    
    /*
     * Try to do query and retry for 5 minutes by default if the DB lost connection
     */
    public function safe_query($sql = '', $args = array(), $try = 300){
    	$retry = FALSE;    	
    	do{
    		//do connection check firstly
    		$this->db->reconnect();
    		
    		if (FALSE === ($res = $this->db->query($sql, $args))){
    			error_log("====================================\nSAFE_QUERY ERROR: $sql \n====================================\n ");
    			error_log("----- ".$this->db->error_msg."[err:".$this->db->error_no."] --- \n");
    			
    			
    			
    			if (in_array((int) $this->db->error_no, array(2013, 2006))){//Error 2013, Lost connection during Query or Error 2006, MySQL server has gone away
    				$this->db->reconnect();
    				//error_log("====================================\nSAFE_QUERY ERROR: $sql \n====================================\n ");
    				sleep(1);
    				$retry = TRUE;
    				continue;    				
    			}    			
    			else{
    				if ($retry){
    					//error_log("====================================\nNotice: this result is after reconnect Query = $sql.\n====================================\n");
    				}
    				return $res;
    			}
    		}
    		else{
    			if ($retry){
    				//error_log("====================================\nNotice: this result is after reconnect Query = $sql.\n====================================\n");
    			}
    			return $res;
    		}
    	}while(--$try > 0);
    	
    	return $res;
    }
    
    public function my_echo($msg = '', $type =  'NORMAL'){
    	$type = strtoupper($type);
    
    	if (!in_array($type, array('NORMAL', 'WARNING', 'ERROR'))){
    		error_log("Log message type [$type] is invalid, must be one of 'NORMAL', 'WARNING', 'ERROR'");
    		exit(0);
    	}
    
    	$msg  = '['.date("Y-m-d H:i:s").'] ['.sprintf("%-8s", $type).'] ['.$this->pid.'] '.$msg."\n";
    
    	if (($this->logtostderr) && defined('STDERR')) {
    		fwrite(STDERR,  $msg);
    	}
    	else{
    		$logFile = $this->logPath.'/group_push_notification_'.date('Ymd').'.log';
    		$handle = fopen($logFile, "a+");
    		if ($handle){
    			if (flock($handle, LOCK_EX)) { // do an exclusive lock
    				fwrite($handle, $msg);
    				flock($handle, LOCK_UN); // release the lock
    			}
    			fclose($handle);
    		}
    	}
    }
}
