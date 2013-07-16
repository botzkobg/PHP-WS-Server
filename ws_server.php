<?php
error_reporting(E_ALL);
set_time_limit(0);
ob_implicit_flush();


class WebSocketServer {
	/**
	 *
	 * @var string
	 */
	const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
	
	/**
	 * 
	 * @var socket resource
	 */
	protected $main_socket;
	
	/**
	 * 
	 * @var array<socket resource>
	 */
	protected $sockets = array();
	
	/**
	 * 
	 * @var array<WSConnections>
	 */
	protected $connections = array();
	
	/**
	 * 
	 * @var string<IP>
	 */
	protected $address = '127.0.0.1';
	
	/**
	 * 
	 * @var int
	 */
	protected $port = 8080;
	
	/**
	 *
	 * @var boolean
	 */
	public $on_debug = false;
	
	public function __construct($address, $port, $debug = false) {
		$this->address = $address;
		$this->port = $port;
		$this->sockets = array();
		$this->connections = array();
		$this->on_debug = $debug;
	}	
	
	public function init($run = false) {
		$this->main_socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if ($this->main_socket === false) {
			throw new Exception('socket_create() faild');
		}
		
		
		if (socket_set_option($this->main_socket, SOL_SOCKET, SO_REUSEADDR, 1) === false) {
			throw new Exception("socket_option() failed");
		}
		
		if (socket_bind($this->main_socket, $this->address, $this->port) === false) {
			throw new Exception("socket_bind() failed");
		}
		if (socket_set_nonblock($this->main_socket) === false) {
			throw new Exception("socket_set_nonblock() failed");
		}
		
		if ($run) {
			$this->run();
		}
	}
	
	public function run() {
		if (socket_listen($this->main_socket, 20) === false) {
			throw new Exception("socket_listen() failed:" .  socket_strerror(socket_last_error($this->main_socket)));
		}
		
		$this->sockets[0] = $this->main_socket;
		
		$this->info('Server Started : ' . date('Y-m-d H:i:s'));
		$this->info('Master socket  : ' .  $this->main_socket);
		$this->info('Listening on   : ' . $this->address . ':' . $this->port);
		
		$this->process();
	}
	
	protected function process() {
		while(true) {
			$changed = $this->sockets;
			$write = $except = $tv_sec = NULL;
		
			if (false === socket_select($changed, $write, $except, $tv_sec)) {
				$this->error('socket_select() failed: ' . socket_strerror(socket_last_error()));
				sleep(1);
				continue;
			}
		
			foreach($changed as $connection_id=>$socket) {
				if($socket == $this->main_socket) {
					$connection = @socket_accept($this->main_socket);
					if ($connection === false) {
						$this->error('socket_accept() failed: ' . socket_strerror(socket_last_error()));
						continue;
					} else {
						$this->acceptConnection($connection);
					}
				} else {
					$bytes = @socket_recv($socket, $buffer, 2048, 0);
					if ($bytes === false) {
						$this->error('socket_recv() failed: ' . socket_strerror(socket_last_error($socket)));
						$this->closeConnection($socket);
						continue;
					}
					if ($bytes == 0) {
						$this->closeConnection($socket);
						continue;
					}
					
					if($this->connections[$connection_id]->handshake){
						$data = $this->receive($connection_id, $buffer);
						$this->send($this->connections[$connection_id]->socket, $this->encode($data));
					} else {
						$this->handShake($connection_id, $buffer);
					}
				}
			}
		}
	}
	
	public function stop() {
		
	}
	
	protected function acceptConnection($socket) {
		$connection_id = uniqid();
		$connection = new WSConnections($connection_id, $socket);
		$this->connections[$connection_id] = $connection;
		$this->sockets[$connection_id] = $socket;
		$this->info('New connection accepted: ' . $socket);
	}
	
	protected function removeConnection($socket) {
		$index = false;
		foreach($this->connections as $key => $connection) {
			if ($connection->socket == $socket) {
				$index = $key;
				break;
			}
		}
		
		if ($index !== false) {
			unset($this->connections[$index]);
		}
	}
	
	protected function removeSocket($socket) {
		$index = array_search($socket, $this->sockets);
		if ($index !== false) {
			unset($this->sockets[$index]);
		}
	}
	
	protected function closeConnection($socket) {
		$message = 'Connection closed: ' . $socket;
		
		$this->removeConnection($socket);
		$this->removeSocket($socket);
		
		socket_close($socket);
		
		$this->info($message);
	}
	
	public function handShake($connection_id, $buffer) {
		$this->info('Start handshake ...');
		$this->info('GUID: ' . $this::GUID);
		
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
		
		if ($handshake['Sec-WebSocket-Version'] != 13) {
			$this->warning("Sec-WebSocket-Version is {$handshake['Sec-WebSocket-Version']} expected 13!");
		}
		
		$handshake['SH1'] = sha1(trim($handshake['Sec-WebSocket-Key']) . $this::GUID, true);
		
		$response = "HTTP/1.1 101 Switching Protocols\r\n" .
					"Upgrade: websocket\r\n" .
					"Connection: Upgrade\r\n" .
					"Sec-WebSocket-Accept: " . base64_encode($handshake['SH1']) . "\r\n\r\n";
		
		$this->send($this->connections[$connection_id]->socket, $response);
		$this->connections[$connection_id]->handshake = true;
	}
	
	protected function receive($connection_id, $buffer) {
		$message = array('FIN' => '', 'RSV1' => '', 'RSV2' => '', 'RSV3' => '', 'OPTCODE' => '', 'MASK' => '', 'PAYLOAD' => '');
		$current_position = 0;
		
		$this->debug('< ' . $buffer);
		
		//Decode FIN RSV1 RSV2 RSV3 and OPTCODE
		$data = decbin(ord($buffer[$current_position++]));
		$data = str_pad($data, 8, "0", STR_PAD_LEFT);
		$message['FIN'] = substr($data, 0, 1);
		$message['RSV1'] = substr($data, 1, 1);
		$message['RSV2'] = substr($data, 2, 1);
		$message['RSV3'] = substr($data, 3, 1);
		$message['OPTCODE'] = substr($data, -4);
		//Decode MASK and PAYLOAD
		$data = decbin(ord($buffer[$current_position++]));
		$data = str_pad($data, 8, "0", STR_PAD_LEFT);
		$message['MASK'] = substr($data, 0, 1);
		$message['PAYLOAD'] = substr($data, -7);
		
		//Check FIN
		if ($message['FIN'] != 1) {
			//TODO: Return error
			$this->info('FIN bit not set. Wait for more frames.');
			$this->error('Multiple frames are not supported.');
			
			return false;
		}
		
		switch ($message['OPTCODE']) {
			case 0x0: $this->warning('OPTCODE 0 is not implemented!'); break;
			case 0x1: $this->debug('Data is in text format.'); break;
			case 0x2: $this->debug('Data is in binary format.'); break;
			case 0x3:
			case 0x4:
			case 0x5:
			case 0x6:
			case 0x7: $this->warning("OPTCODE {$message['OPTCODE']} is reserverd!"); break;
			case 0x8: $this->debug('Connection close request.'); break;
			case 0x9: $this->debug('Ping request.'); break;
			case 0xA: $this->debug('Pong response.'); break;
			case 0xB:
			case 0xC:
			case 0xD:
			case 0xF: $this->warning("OPTCODE {$message['OPTCODE']} is reserverd!"); break;
			default: $this->error("OPTCODE {$message['OPTCODE']} is reserverd!");
		}
		
		$this->debug("Data length:  {$message['PAYLOAD']}b");
		$message['PAYLOAD'] = bindec($message['PAYLOAD']);
		
		//Get message length
		if ($message['PAYLOAD'] <= 125) {
			$this->debug("Data length:  {$message['PAYLOAD']}");
		} else {
			$payload_bytes = false;
			switch($message['PAYLOAD']) {
				case 126: $payload_bytes = 2; break;
				case 127: $payload_bytes = 8; break;
			}
			
			if ($payload_bytes === false) {
				$this->error('Mismach in payload: ' . $message['PAYLOAD']);
				
				return false;
			}
			
			$data = '';
			for($payload_byte = 0; $payload_byte < $payload_bytes; $payload_byte++) {
				$tmp_data = decbin(ord($buffer[$current_position++]));
				$tmp_data = str_pad($tmp_data, 8, "0", STR_PAD_LEFT);
				
				$data .= $tmp_data;
			}
			
			if ($data{0} != 0) {
				$this->error('most significant bit MUST be 0!');
				
				return false;
			}
			
			$message['PAYLOAD'] = bindec($data);
			
			$this->debug("Data length:  {$message['PAYLOAD']}");
		}
		
		//Get message
		if ($message['MASK'] == 1) {
			$this->debug('Mask key detected.');
			$mask_key = array();
			//Get MASKING KEY
			for($i = 0; $i < 4; $i++) {
				$mask_key[$i] = ord($buffer[$current_position++]);
			}
			$data = '';
			for ($i = 0; $i < $message['PAYLOAD']; $i++) {
				$mask_key_index = ($i) % 4;
				$payload_data = ord($buffer[$current_position++]);
				$data .= chr($payload_data ^ $mask_key[$mask_key_index]);
			}
		
			$this->debug("Data received: {$data}");
		} else {
			$data = '';
			for ($i = 0; $i < $message['PAYLOAD']; $i++) {
				$data .= chr(ord($buffer[$current_position++]));
			}
				
			$this->debug("Data received: {$data}");
		}
			
		return $data;
	}
	
	protected function send($socket, $message) {
		$this->debug('>' . $message);
		$length = strlen($message);
		$count = 0;
		while ($length > 0) {
			$this->debug('Sending protion: ' . $count++);
			$sent = socket_write($socket, $message, $length);
			if ($sent === false) {
				$this->error('socket_write() failed: ' . socket_strerror(socket_last_error($socket)));
			}
			
			$length -= $sent;
			$message = substr($message, $sent);
		}
	}
	
	protected function encode($message) {
		$this->debug('Message to encript: ' . $message);
		
		$extended_payload = '';
		$fin_rsv_opcode = '10000001';
		
		$length = strlen($message);
		
		//Calculate Extended payload
		if ($length > 125) {
			$extended_payload = decbin($length);
			if ($extended_payload <= 16) {
				$length = 126;
				$extended_payload = str_pad($extended_payload, 16, "0", STR_PAD_LEFT);
			} else {
				$length = 127;
				$extended_payload = str_pad($extended_payload, 64, "0", STR_PAD_LEFT);
			}
			
			$extended_payload = str_split($extended_payload, 8);
			foreach($extended_payload as $key=>$value) {
				$extended_payload[$key] = chr(bindec($extended_payload[$key]));
			}
			$extended_payload = implode('', $extended_payload);
		}
		
		//Calculate Payload
		$payload = decbin($length);
		$payload = str_pad($payload, 7, "0", STR_PAD_LEFT);
		
		//Add Mask to payload
		$payload = '0' . $payload;
		$encoded_message = chr(bindec($fin_rsv_opcode)) . chr(bindec($payload)) . $extended_payload . $message;
		
		if ($this->on_debug) {
			$this->receive(0, $encoded_message);
		} 
		
		return $encoded_message;
	}
	
	protected function info($message) {
		$this->output('\033[0;32mINFO: ' . $message . '\033[0m');
	}
	
	protected function warning($message) {
		$this->output('\033[1;33mWARNING: ' . $message . '\033[0m');
	}
	
	protected function error($message) {
		$this->output('\033[0;31mERROR: ' . $message . '\033[0m');
	}
	
	protected function debug($message) {
		if ($this->on_debug) {
			$this->output('DEBUG: ' . $message);
		}
	}
	
	protected function output($message, $newline = true) {
		echo $message;
		
		if ($newline) {
			echo "\n";
		}
	}
	
	
}

class WSConnections{
	/**
	 * 
	 * @var string
	 */
	protected $id;
	
	/**
	 * 
	 * @var socket resource
	 */
	public $socket;
	/**
	 * 
	 * @var boolean
	 */
	public $handshake = false;
	
	public function __construct($id, $socket) {
		$this->id = $id;
		$this->socket = $socket;
		$this->handshake = false;
	}
}

$server = new WebSocketServer('127.0.0.1', '12345', true);
$server->init(true);

exit();



define('RFC_KEY', '258EAFA5-E914-47DA-95CA-C5AB0DC85B11');



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
	
	foreach($changed as $key=>$socket) {
		print_r($key);
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
		//TODO: retrun error
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
	$users[$user->id] = $user;
	$sockets[$user->id] = $socket;
// 	array_push($users,$user);
// 	array_push($sockets,$socket);
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