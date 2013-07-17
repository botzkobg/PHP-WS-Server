<?php
require_once dirname(__FILE__) . '/btzWebSocket.class.php';

error_reporting(E_ALL);
set_time_limit(0);
ob_implicit_flush();

class WebSocketServer extends BtzWebSocket {
	/**
	 * (non-PHPdoc)
	 * @see BtzSocket::process()
	 */
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
						$connection_id = $this->acceptConnection($connection);
						
						if (socket_getpeername($connection, $IP) === false) {
							$this->error('socket_getpeername() failed: ' . socket_strerror(socket_last_error()));
							continue;
						}
						
						$this->debug('Client IP: ' . $IP);
						
						if ($IP == '192.168.1.100') {
							$this->connections[$connection_id]->internal = true;
							$this->connections[$connection_id]->handshake = true;
						} else {
							$this->connections[$connection_id]->internal = false;
						}
					}
				} else {
					$this->debug('Receiving data ...');
					$bytes = @socket_recv($socket, $buffer, 2048, 0);
					$this->debug('Received ' . $bytes . ' bytes');
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
						if ($this->connections[$connection_id]->internal) {
							$data = $buffer;
						} else {
							$data = $this->decode($connection_id, $buffer);
						}
						
						$this->debug('< ' . $data);
						
						if ($data == 'update') {
// 							require 'request_t.php';
							$gnerated_html = 'Put your logic here to generate updates';
							foreach($this->connections as $connection) {
								if ($connection->internal == true) {
									continue;
								}
								$this->send($connection->socket, $gnerated_html);
							}
						}
					} else {
						$this->handShake($connection_id, $buffer);
					}
				}
			}
		}
	}
}

$server = new WebSocketServer('192.168.1.100', '12345', true);
$server->initServer(true);

exit();