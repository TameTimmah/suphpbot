<?php
if (PHP_SAPI !== 'cli') { die('This script can\'t be run from a web browser.'); }
set_time_limit(0);
$config = 'ircconfig.ini';
if (count(getopt('c:'))>0) {
	$config = getopt('c:');
	var_dump($config);
	$config = $config['c'];
}
ini_set('error_reporting',E_ALL-E_NOTICE);
load_settings();
// thanks, tutorialnut.com, for the control codes!
define('C_BOLD', chr(2));
define('C_COLOR', chr(3));
define('C_ITALIC', chr(29));
define('C_REVERSE', chr(22));
define('C_UNDERLINE', chr(31)); 
// hooks in the house! woo woo
$hooks = array('data_in'=>array(),
	'data_out'=>array());
// preload some modules
$commands = array();
foreach ($premods as $premod) {
	include_once('./modules/' . trim($premod) . '.php');
	$commands = array_merge($commands,$function_map[trim($premod)]);
	if (isset($hook_map[trim($premod)])) {
		foreach ($hook_map[trim($premod)] as $hook_id => $hook_function) {
			$hooks[$hook_id][] = $hook_function;
		}
	}
}
$lastsent = array(); // Array of timestamps for last command from nicks - helps prevent flooding
while (1) {
	$socket = fsockopen($settings['server'], $settings['port'], $errno, $errstr, 20);
	if (!$socket) {
		echo 'Unable to connect! Retrying in 5...' . "\n";
		sleep(5);
	} else {
		stream_set_timeout($socket, 0, 1);
		stream_set_blocking($socket, 0); 
		if ($settings['pass']!=='') {
			send('PASS ' . $settings['pass']);
		}
		send('USER ' . $settings['ident'] . ' 8 * :' . $settings['realname']);
		send('NICK ' . $settings['nick']);
		while (!feof($socket)) {
			$admin = FALSE;
			$in_convo = FALSE;
			$buffer = fgets($socket);
			$buffer = str_replace(array("\n","\r"),'',$buffer);
			$buffwords = explode(' ',$buffer);
			$nick = explode('!',$buffwords[0]);
			$nick = substr($nick[0],1);
			$channel = $buffwords[2];
			if (substr($channel,0,1)!=='#') {
				$channel = $nick;
				$in_convo = TRUE;
			}
			$hostname = end(explode('@',$buffwords[0]));
			$bw = $buffwords;
			$bw[0]=NULL; $bw[1]=NULL; $bw[2]=NULL; $bw[3]=NULL;
			$arguments = trim(implode(' ',$bw));
			$args = explode(' ',$arguments);
			if (strlen($buffer)>0) {
				call_hook('data_in');
				echo '[IN]' . "\t" . $buffer . "\r\n";
			}
			if ($buffwords[1]=='002') {
				// The server just sent us something. We're in.
				if ($settings['nickserv_pass']!=='') {
					// Let's identify before we join any channels.
					send_msg($settings['nickserv_nick'],'IDENTIFY ' . $settings['nickserv_pass']);
				}
				$channels = explode(',',$settings['channels']);
				foreach ($channels as $channel) {
					send('JOIN ' . trim($channel));
				}
			} elseif ($buffwords[1]=='433') {
				// Nick collision!
				send('NICK ' . $settings['nick'] . '_' . rand(100,999));
			} elseif ($buffwords[0]=='PING') {
				send('PONG ' . str_replace(array("\n","\r"),'',end(explode(' ',$buffer,2))));
			} elseif ($buffwords[1]=='PRIVMSG'&&(substr($buffwords[3],1,strlen($settings['commandchar']))==$settings['commandchar']||$in_convo)) {
				if ($in_convo) {
					$command = trim(substr($buffwords[3],1));
				} else {
					$command = trim(substr($buffwords[3],2));
				}
				if ($lastsent[$hostname]<(time()-$settings['floodtimer'])) {
					$lastsent[$hostname]=time();
					if (in_array($nick,$ignore)) {
						// do nothing - we're ignoring them :p
					} else {
						if (function_exists($commands[$command])) {
							call_user_func($commands[$command]);
						} else {
							send_msg($buffwords[2],'' . $command . ' is not a valid command. Maybe you need to load a plugin?');
						}
					}
				}
			}
		}
	}
}
// much thanks to gtoxic of avestribot for helping me realize my stupid mistake here
function send($raw) {
	global $socket;
	call_hook('data_out');
	fwrite($socket,"{$raw}\n\r");
	echo '[OUT]' . "\t" . $raw . "\n";
}
function send_msg($target,$message) {
	global $nick,$settings;
	// Let's chunk up the message so it all gets sent.
	$message = str_split($message,intval($settings['maxlen']));
	if (count($message)>intval($settings['maxsend'])) {
		// We want to use maxsend-1 in this situation because we'll be appending an error telling the user what
		// exactly happened to the rest of their output.
		$message = array_slice($message,0,intval($settings['maxsend']-1));
		$message[] = 'The output for your command was too long to send fully.';
	}
	foreach ($message as $msg) {
		send ('PRIVMSG ' . $target . ' :' . C_BOLD . $nick .  ': ' . C_BOLD . xtrim($msg));
	}
}
// borrowed from gtoxic of avestribot, who borrowed it from somebody else...
function write_php_ini($array, $file) {
	$res = array();
	foreach($array as $key => $val) {
		if(is_array($val)) {
			$res[] = "[$key]";
			foreach($val as $skey => $sval) $res[] = "$skey = ".(is_numeric($sval) ? $sval : '"'.$sval.'"');
		} else {
			$res[] = "$key = ".(is_numeric($val) ? $val : '"'.$val.'"');
		}
	}
	$res = implode("\r\n", $res);
	$res = '; IRC bot config file
	; For more info, check the README' . "\r\n" . $res;
}
// supertrim function by jose cruz
// josecruz at josecruz dot com dot br
function xtrim($str) {
    $str = trim($str);
    for($i=0;$i < strlen($str);$i++) {
        if(substr($str, $i, 1) != " ") {
            $ret_str .= trim(substr($str, $i, 1));
        } else  {
            while(substr($str,$i,1) == " ") {
                $i++;
            }
            $ret_str.= " ";
            $i--; // ***
        }
    }
    return $ret_str;
} 
function load_settings() {
	global $settings,$premods,$admins,$ignore,$config;
	$settings = parse_ini_file($config);
	$premods = explode(',',$settings['module_preload']);
	$admins = explode(',',$settings['admins']);
	$ignore = explode(',',$settings['ignore']);
}
function call_hook($hook) {
	global $hooks;
	$hook = $hooks[$hook];
	foreach ($hook as $hookah) {
		call_user_func($hookah);
	}
}
function shell_send($message) {
	echo "[PHP]\t" . $message . "\n";
}
?> 
