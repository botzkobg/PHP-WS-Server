<?php

class BtzConnections{
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