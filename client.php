<?php
require_once dirname(__FILE__) . '/btzSocket.class.php';

error_reporting(E_ALL);
set_time_limit(0);
ob_implicit_flush();

class ClientSocket extends BtzSocket {
	/**
	 * (non-PHPdoc)
	 * @see BtzSocket::process()
	 */
	protected function process() {
	}
}

$client = new ClientSocket('127.0.0.1', '12346', true);
$client->initClient(true);
$client->sendToServer('test Message');

exit();