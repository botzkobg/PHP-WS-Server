<?php
require_once dirname(__FILE__) . '/btzSocket.class.php';

error_reporting(E_ALL);
set_time_limit(0);
ob_implicit_flush();

class SocketServer extends BtzSocket {
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
					
					$this->info($buffer);
				}
			}
		}
	}
}

$server = new SocketServer('127.0.0.1', '12346', true);
$server->initServer(true);

exit();