<?php
require_once dirname(__FILE__) . '/btzSocket.class.php';

abstract class BtzWebSocket extends BtzSocket {
	/**
	 *
	 * @var string
	 */
	const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';
	
	/**
	 * Send data
	 * 
	 * Before send the message it must be encoded by The WebSocket Protocol RFC: 6455
	 *
	 * @param socket resource $socket
	 * @param string $message
	 */
	protected function send($socket, $message) {
		parent::send($socket, $this->encode($message));
	}
	
	/**
	 * Handshake process
	 * 
	 * Send headers to the client. Headers are not encoded.
	 * 
	 * Buffer is received data from the client(Request headers).
	 * 
	 * @param string $connection_id
	 * @param string $buffer
	 */
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
	
		parent::send($this->connections[$connection_id]->socket, $response);
		$this->connections[$connection_id]->handshake = true;
	}
	


	/**
	 * Encode message to send
	 *
	 * Encode message by The WebSocket Protocol RFC: 6455
	 *
	 * @param unknown $message
	 * @return string
	 */
	protected function encode($message) {
		$this->debug('Message to encript: ' . $message);
	
		$extended_payload = '';
		$fin_rsv_opcode = '10000001';
	
		$length = strlen($message);
		$this->debug('Message length: ' . $length);
	
		//Calculate Extended payload
		if ($length > 125) {
			$extended_payload = decbin($length);
			if (strlen($extended_payload) <= 16) {
				$length = 126;
				$this->debug('Set message length to ' . $length);
				$extended_payload = str_pad($extended_payload, 16, "0", STR_PAD_LEFT);
			} else {
				$length = 127;
				$this->debug('Set message length to ' . $length);
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
			$this->decode(0, $encoded_message);
		}
	
		return $encoded_message;
	}
	
	/**
	 * Decode message/frame
	 *
	 * Decode received frame by The WebSocket Protocol RFC: 6455
	 *
	 * @param string $connection_id
	 * @param string $buffer
	 * @return boolean|string
	 */
	protected function decode($connection_id, $buffer) {
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
}