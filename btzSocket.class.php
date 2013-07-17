<?php
require_once dirname(__FILE__) . '/btzConnection.class.php';

abstract class BtzSocket {
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
	 * @var array<BtzConnections>
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
	
	/**
	 * Create new object
	 * 
	 * @param string $address
	 * @param int $port
	 * @param boolean $debug
	 */
	public function __construct($address, $port, $debug = false) {
		$this->address = $address;
		$this->port = $port;
		$this->sockets = array();
		$this->connections = array();
		$this->on_debug = $debug;
	}
	
	/**
	 * Initzialize server
	 * 
	 * Prepare socket to listen
	 * 
	 * @param boolean $run
	 * @throws Exception
	 */
	public function initServer($run = false) {
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
			$this->runServer();
		}
	}
	
	/**
	 * Run server
	 * 
	 * Before you run the server you must call initServer method
	 * to prepare the socket.
	 * 
	 * This metod accept maximum number of connection to accept.
	 * Default value is 0 (limited by the OS).
	 * 
	 * @param int $max_clients
	 * @throws Exception
	 */
	public function runServer($max_clients = 0) {
		if (socket_listen($this->main_socket, $max_clients) === false) {
			throw new Exception("socket_listen() failed:" .  socket_strerror(socket_last_error($this->main_socket)));
		}
	
		$this->sockets[0] = $this->main_socket;
	
		$this->info('Server Started : ' . date('Y-m-d H:i:s'));
		$this->info('Master socket  : ' .  $this->main_socket);
		$this->info('Listening on   : ' . $this->address . ':' . $this->port);
	
		$this->process();
	}
	
	/**
	 * Process connections
	 * 
	 * This metod is abstract and is used to process 
	 * all incoming connections. This is the logic
	 * for all connections.
	 * 
	 * This metod is called by runServer method.
	 */
	abstract protected function process();
	
	/**
	 * Stop server
	 * 
	 * All actions to execute before stop the server.
	 */
	public function stopServer() {
	
	}
	
	/**
	 * Initzialize client
	 *
	 * Prepare socket to connect
	 *
	 * @param boolean $connect
	 * @return BtzSocket
	 * @throws Exception
	 */
	public function initClient($connect = false) {
		$this->main_socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if ($this->main_socket === false) {
			throw new Exception('socket_create() faild');
		}
		
		if ($connect) {
			$this->requestConnection();
		}
		
		return $this;
	}
	
	/**
	 * Request connection
	 *
	 * Before you request connection method initClient must be called
	 * to prepare the socket.
	 *
	 * @return BtzSocket
	 * @throws Exception
	 */
	public function requestConnection() {
		if (socket_connect($this->main_socket, $this->address, $this->port) === false) {
			throw new Exception("socket_connect() failed:" .  socket_strerror(socket_last_error($this->main_socket)));
		}

		return $this;
	}
	
	/**
	 * Send message to server
	 * 
	 * @param string $message
	 * @return BtzSocket
	 */
	public function sendToServer($message) {
		$this->send($this->main_socket, $message);
		
		return $this;
	}
	
	/**
	 * Accept connection
	 * 
	 * Accept new connections, add it to connection list
	 * and add socket to socket list.
	 * 
	 * @param socket resource $socket
	 * @return string
	 */
	protected function acceptConnection($socket) {
		$connection_id = uniqid();
		$connection = new BtzConnections($connection_id, $socket);
		$this->connections[$connection_id] = $connection;
		$this->sockets[$connection_id] = $socket;
		$this->info('New connection accepted: ' . $socket);
		
		return $connection_id;
	}
	
	/**
	 * Remove connection
	 * 
	 * Remove connection from list of connections.
	 * 
	 * This method is excuted by closeConnection().
	 * 
	 * @param socket resource $socket
	 */
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
	
	/**
	 * Remove socket
	 * 
	 * Remove socket from list of sockets.
	 * 
	 * @param socket resource $socket
	 */
	protected function removeSocket($socket) {
		$index = array_search($socket, $this->sockets);
		if ($index !== false) {
			unset($this->sockets[$index]);
		}
	}
	
	/**
	 * Close connection
	 * 
	 * Close connection with a client.
	 * 
	 * @param socket resource $socket
	 */
	public function closeConnection($socket) {
		$message = 'Connection closed: ' . $socket;
	
		$this->removeConnection($socket);
		$this->removeSocket($socket);
	
		socket_close($socket);
	
		$this->info($message);
	}
	
	/**
	 * Send data
	 * 
	 * @param socket resource $socket
	 * @param string $message
	 */
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
	
	/**
	 * Ouput info message
	 * 
	 * @param string $message
	 */
	protected function info($message) {
		$this->output('\033[0;32mINFO: ' . $message . '\033[0m');
	}

	/**
	 * Ouput warning message
	 *
	 * @param string $message
	 */
	protected function warning($message) {
		$this->output('\033[1;33mWARNING: ' . $message . '\033[0m');
	}

	/**
	 * Ouput erro message
	 *
	 * @param string $message
	 */
	protected function error($message) {
		$this->output('\033[0;31mERROR: ' . $message . '\033[0m');
	}

	/**
	 * Ouput debug message
	 *
	 * @param string $message
	 */
	protected function debug($message) {
		if ($this->on_debug) {
			$this->output('DEBUG: ' . $message);
		}
	}
	

	/**
	 * Ouput message
	 *
	 * @param string $message
	 */
	protected function output($message, $newline = true) {
		echo $message;
	
		if ($newline) {
			echo "\n";
		}
	}
}