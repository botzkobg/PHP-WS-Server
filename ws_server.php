<?php
define('RFC_KEY', '258EAFA5-E914-47DA-95CA-C5AB0DC85B11');

error_reporting(E_ALL);
set_time_limit(0);
ob_implicit_flush();

$sockets = array();
$users = array();
$debug = true;

$master  = WebSocket("localhost",12345);
array_push($sockets, $master);

while(true) {
	$changed = $sockets;
	$write = $except = $tv_sec = NULL;
	
	if (false === socket_select($changed, $write, $except, $tv_sec)) {
		echo "socket_select() failed, reason: " . socket_strerror(socket_last_error()) . "\n";
		continue;
	}
	
	foreach($changed as $socket) {
		if($socket == $master) {
			$client = socket_accept($master);
			if ($client === false) {
				echo "socket_accept() failed, reason: " . socket_strerror(socket_last_error()) . "\n";
				continue;
			} else {
				connect($client);
			}
		} else {
			$bytes = @socket_recv($socket, $buffer, 2048, 0);
			if ($bytes === false) {
				echo "socket_recv() failed; reason: " . socket_strerror(socket_last_error($socket)) . "\n";
				disconnect($socket);
				continue;
			}
			if ($bytes == 0) {
				disconnect($socket);
				continue;
			} 
			
			$user = getuserbysocket($socket);
			if(!$user->handshake){
				dohandshake($user, $buffer); 
			} else {
				process($user,$buffer);
			}
		}
	}
}

function dohandshake(&$user, $buffer) {
	echo "Start handshakeing ...\n'" . $buffer . "'\n";
	$handshake = array();
	preg_match('/Sec-WebSocket-Key: (.*)/', $buffer, $web_socket_key);
	$handshake['Sec-WebSocket-Key'] = $web_socket_key[1];
	preg_match('/Upgrade: (.*)/', $buffer, $web_socket_upgrade);
	$handshake['Upgrade'] = $web_socket_upgrade[1];
	preg_match('/Origin: (.*)/', $buffer, $web_socket_origin);
	$handshake['Origin'] = $web_socket_origin[1];
	preg_match('/Sec-WebSocket-Version: (.*)/', $buffer, $web_socket_version);
	$handshake['Sec-WebSocket-Version'] = $web_socket_version[1];
	preg_match('/Host: (.*)/', $buffer, $web_socket_host);
	$handshake['Host'] = $web_socket_host[1];
	
	print_r($handshake);
	
	if ($handshake['Sec-WebSocket-Version'] != 13) {
		echo "WARNING: Sec-WebSocket-Version is {$handshake['Sec-WebSocket-Version']} expected 13!";
	}
	echo "INFO: RFC KEY: " . RFC_KEY . "\n";
	
	$handshake['SH1'] = sha1(trim($handshake['Sec-WebSocket-Key']) . RFC_KEY, true);
	
	$response = "HTTP/1.1 101 Switching Protocols\r\n" .
				"Upgrade: websocket\r\n" .
				"Connection: Upgrade\r\n" .
				"Sec-WebSocket-Accept: " . base64_encode($handshake['SH1']) . "\r\n\r\n" ;
	
	send($user->socket, $response);
	$user->handshake = $handshake;
}

function send($client,$msg){
	say("> '".$msg . "'");
// 	$msg = wrap($msg);
	socket_write($client, $msg, strlen($msg));
}

function process($user,$msg){
	$message = array('FIN' => '', 'RSV1' => '', 'RSV2' => '', 'RSV3' => '', 'OPTCODE' => '', 'MASK' => '', 'PAYLOAD' => '');
	$current_position = 0;
	
	say("< '". $msg . "'");
	//Decode FIN RSV1 RSV2 RSV3 and OPTCODE
	$data = decbin(ord($msg[$current_position++]));
	$data = str_pad($data, 8, "0", STR_PAD_LEFT);
	echo "First: {$data}\n";
	$message['FIN'] = substr($data, 0, 1);
	$message['RSV1'] = substr($data, 1, 1);
	$message['RSV2'] = substr($data, 2, 1);
	$message['RSV3'] = substr($data, 3, 1);
	$message['OPTCODE'] = substr($data, -4);
	//Decode MASK and PAYLOAD
	$data = decbin(ord($msg[$current_position++]));
	$data = str_pad($data, 8, "0", STR_PAD_LEFT);
	$message['MASK'] = substr($data, 0, 1);
	$message['PAYLOAD'] = substr($data, -7);
	
	//Check FIN
	if ($message['FIN'] != 1) {
		//TODO: Return error
	}
	switch ($message['OPTCODE']) {
		case 0x0: echo "WARNING: OPTCODE 0 is not implemented!\n"; break;
		case 0x1: echo "INFO: Data is in text format.\n"; break;
		case 0x2: echo "WARNING: OPTCODE 0 is not implemented!\n"; break;
		case 0x3:
		case 0x4:
		case 0x5:
		case 0x6:
		case 0x7: echo "WARNING: OPTCODE {$message['OPTCODE']} is reserverd!\n"; break;
		case 0x8: echo "INFO: Connection close request.\n"; break;
		case 0x9: echo "INFO: Ping request.\n"; break;
		case 0xA: echo "INFO: Pong response.\n"; break;
		case 0xB: 
		case 0xC: 
		case 0xD: 
		case 0xF: echo "WARNING: OPTCODE {$message['OPTCODE']} is reserverd!\n"; break;
	}
	
	echo "INFO: Data length:  {$message['PAYLOAD']}b\n";
	$message['PAYLOAD'] = bindec($message['PAYLOAD']);
	
	if ($message['PAYLOAD'] <= 125) {
		echo "INFO: Data length: {$message['PAYLOAD']}\n";
		if ($message['MASK'] == 1) {
			echo "INFO: Mask key detected.\n";
			$mask_key = array();
			//Get MASKING KEY
			for($i = 0; $i < 4; $i++) {
				$mask_key[$i] = ord($msg[$current_position++]);
				echo "INFO: Mask key {$i} is {$mask_key[$i]}\n";
			}
			$data = '';
			for ($i = 0; $i < $message['PAYLOAD']; $i++) {
				$mask_key_index = ($i) % 4;
				$payload_data = ord($msg[$current_position++]);
				$data .= chr($payload_data ^ $mask_key[$mask_key_index]);
			}
			
			echo "INFO: Data received: {$data}\n";
		} else {
			$data = '';
			for ($i = 0; $i < $message['PAYLOAD']; $i++) {
				$data .= chr(ord($msg[$current_position++]));
			}
				
			echo "INFO: Data received: {$data}\n";
		}
	}
}

function getuserbysocket($socket){
	global $users;
	$found=null;
	foreach($users as $user){
		if($user->socket==$socket){ $found=$user; break; }
	}
	return $found;
}

function connect($socket){
	global $sockets,$users;
	$user = new User();
	$user->id = uniqid();
	$user->socket = $socket;
	array_push($users,$user);
	array_push($sockets,$socket);
	console($socket . " CONNECTED!");
}

function disconnect($socket){
	global $sockets,$users;
	$found=null;
	$n=count($users);
	for($i=0;$i<$n;$i++){
		if($users[$i]->socket==$socket){ $found=$i; break; }
	}
	if(!is_null($found)){ array_splice($users,$found,1); }
	$index = array_search($socket,$sockets);
	socket_close($socket);
	console($socket." DISCONNECTED!");
	if($index>=0){ array_splice($sockets,$index,1); }
}


function WebSocket($address,$port){
	$master=socket_create(AF_INET, SOCK_STREAM, SOL_TCP)     or die("socket_create() failed");
	socket_set_option($master, SOL_SOCKET, SO_REUSEADDR, 1)  or die("socket_option() failed");
	socket_bind($master, $address, $port)                    or die("socket_bind() failed");
	socket_listen($master, 20)                                or die("socket_listen() failed");
	socket_set_nonblock($master);
	echo "Server Started : ".date('Y-m-d H:i:s')."\n";
	echo "Master socket  : ".$master."\n";
	echo "Listening on   : ".$address." port ".$port."\n\n";
	return $master;
}

function console($msg=""){ global $debug; if($debug){ echo $msg."\n"; } }
function     say($msg=""){ echo $msg."\n"; }
function  unwrap($msg=""){ return substr($msg,1,strlen($msg)-2); }

class User{
	var $id;
	var $socket;
	var $handshake;
}