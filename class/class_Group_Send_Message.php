<?php

require_once 'playit/api.libraries/Google_Cloud_Message_API.php';
require_once 'playit/api.libraries/IOS_Push_Notification_API.php';
require_once 'playit/api.libraries/Windows_Push_Notification_API.php';
require_once 'playit/api.libraries/binu.Notification_API.php';



class Group_Send_Message{
	public function __construct(){
	}
	
	
	public function sendMessage($args = array()){
		$res                = new stdClass();
		$res->error_code    = 0;
		$res->error_message = '';
		$res->data          = '';
		
		$taskID             = $args['taskID'];
		$notify_title       = $args['notify_title'];
		$notify_message     = $args['notify_message'];
		$playit_id          = $args['playit_id'];
		$user_platform      = $args['user_platform'];
		$notify_nav_tag     = $args['notify_nav_tag'];
		$notify_nav_args    = urldecode($args['notify_nav_args']);
		$push_token         = $args['push_token'];
		$notify_app_id      = $args['notify_app_id'];
		
		$platform_t         = strtolower($user_platform);
		
		//page navigation setting if set
		$nav_args_array = array();
		if (!empty($notify_nav_tag)){
			if (!empty($notify_nav_args)){
				parse_str($notify_nav_args, $nav_args_array);
			}
		}
		else{
			$notify_nav_args = array();//if tag not set, the args should be blank as well.
		}
		
		
		//biNu platform notification setting for apps
		$Notify_Config = array(
				'6971' => array(
						'APP_ID'         => 6971,
						'SPORT_ID'       => 1,
						'DisplayText'    => 'Cricket Manager',
						'Icon'           => 'http://fantasy.cricket.playitgame.com/assets/img/logos/bullet.png',
						'CallBack_URL'   => 'http://fantasy.cricket.playitgame.com/'
				),
				'6891' => array(
						'APP_ID'         => 6891,
						'SPORT_ID'       => 2,
						'DisplayText'    => 'Football Manager',
						'Icon'           => 'http://fantasy.football.playitgame.com/assets/img/logos/bullet.png',
						'CallBack_URL'   => 'http://fantasy.football.playitgame.com/'
				),
				'5000' => array(
						'APP_ID'         => 5000,
						'SPORT_ID'       => 1,
						'DisplayText'    => 'ICC World Cup 2015 - Dream Team',
						'Icon'           => 'http://icc.cricket.playitgame.com/assets/img/logos/bullet.png',
						'CallBack_URL'   => 'http://icc.cricket.playitgame.com/'
				),
				'5001' => array(
						'APP_ID'         => 5001,
						'SPORT_ID'       => 0,
						'DisplayText'    => 'Super Six - Supr6',
						'Icon'           => 'http://icc.cricket.playitgame.com/assets/img/logos/bullet.png',//to do
						'CallBack_URL'   => 'http://icc.cricket.playitgame.com/' //to do
				),
				'6976' => array(
						'APP_ID'         => 6976,
						'SPORT_ID'       => 2,
						'DisplayText'    => 'PLAY IT FOOTBALL',
						'Icon'           => 'http://football.playitgame.com/assets/img/logos/bullet.png',
						'CallBack_URL'   => 'http://football.playitgame.com/'
				),
				'6970'  => array(
						'APP_ID'         => 6970,
						'SPORT_ID'       => 1,
						'DisplayText'    => 'PLAY IT CRICKET',
						'Icon'           => 'http://cricket.playitgame.com/assets/img/logos/bullet.png',
						'CallBack_URL'   => 'http://cricket.playitgame.com/'
				)
					
		);

	
		
		/*
		//testing
		error_log("---------------------------------------------------------------------\n");
		print_r($args);
		return TRUE;
		
		
		$platform_t = 'binu';
		$push_token = '189977';
		$notify_app_id = 5000;
		
		$platform_t = 'android';
		$push_token = 'APA91bGrgrZB8PA2rpU_mzdDwXvJHDaYkGCo_F2XLBdOqTSVt4HD4701JanoUyLnNk3OO6mGGO0tSg_zZ3QqdPRvSgAyR3ceIhqXBZLwQfTy56r-MJWufBc3yuveVguZUbbBRd2hm-CjrIgTtdmqJ20ypEX7T13Qw3n6SLGFUFsu_GnuebFtXfQ';
		$notify_app_id = 5000;
		
		$platform_t = 'ios';
		$push_token = '7e7f2e77fe51f8c1887fd3e68abc3dc4ef41cac7f8695b10695ecde2298e3cea';
		$notify_app_id = 5000;
		*/
		
		if ($platform_t == 'android'){
			if (!empty($push_token) && !empty($notify_message)){
				$gcm_obj = new Google_Cloud_Message_API($notify_app_id);
				$res_t = $gcm_obj->sendNotification($push_token,  $notify_message, $notify_title, $notify_nav_tag, $nav_args_array);
				$res = json_decode($res_t);
			}
			else{
				$res->error_code    = -1;
				$res->error_message = 'Error: parameter push_token and message are required.';
				error_log(__METHOD__.' Message: parameter push_token and message are required, skip to send notification.');
			}
		}
		else if ($platform_t == 'ios'){
			if (!empty($push_token) && !empty($notify_message)){
				$ios_nt_obj = new IOS_Push_Notification_API($notify_app_id, 'production');
				$res_t = $ios_nt_obj->sendNotification($push_token, $notify_message, $notify_title, $notify_nav_tag, $nav_args_array);
				$res = json_decode($res_t);
			}
			else{
				$res->error_code    = -1;
				$res->error_message = 'Error: parameter push_token and message are required.';
				error_log(__METHOD__.' Message: parameter push_token and message are required, skip to send notification.');
			}
		}
		else if ( $platform_t == 'win32nt'){
			//testing
			//$push_token = 'http://s.notify.live.net/u/1/sin/HmQAAACCPVgXc-hd4fqvms_FglYthcW2z298rfnAGBDuQZzsjakzGAJ4pKCU2dPmSLaSlHbSp5_GmQXPh0AZ-2tn-G3S/d2luZG93c3Bob25lZGVmYXVsdA/YAyNz8nC5BGTqR9Z7VKEXg/WM7DxHFUWs6ga9a0bxyDzpqnors';
			if (!empty($push_token) && !empty($notify_message)){
				$wint_obj = new Windows_Push_Notification_API();
				$res_t = $wint_obj->send_Toast_Notification($push_token, $notify_title, $notify_message);
				if (empty($res_t)){
					$res->error_code    = -1;
					$res->error_message = 'Send request failed.';
				}
				else{
					
				}
			}
			else{
				$res->error_code    = -1;
				$res->error_message = 'Error: parameter push_token and message are required.';
				error_log(__METHOD__.' Message: parameter push_token and message are required, skip to send notification.');
			}
		}
		else if ($platform_t == 'binu'){
			$app_config = isset($Notify_Config[$notify_app_id])? $Notify_Config[$notify_app_id] : array();
			if (!empty($app_config)){
				//$notify_obj = new binu_Notification_API($app_config['APP_ID'], $app_config['DisplayText'], $app_config['Icon'], $app_config['CallBack_URL']);
				$notify_obj = new binu_Notification_API($app_config['APP_ID'], $notify_title, $app_config['Icon'], $app_config['CallBack_URL']);
				$res_t = $notify_obj->create_notification($push_token, array('tone', 'vibrate'), TRUE);
				$res = json_decode($res_t);
			}
			else{
				$res->error_code    = -1;
				$res->error_message = 'Error: Failed to get the notification config with app_id = '.$notify_app_id.' then skip to send notification.';
				error_log(__METHOD__. ' '.$this->error_message);
			}
		}
		
		return $res;
	}
	
	
}