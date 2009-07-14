<?php
require_once(dirname(__FILE__).'/../JabberClient.class.php');

class MyJabberBot extends JabberClient {
	/*
		Base event.
		Runs when message received
	*/
	protected function onMessage($from, $body) {
		// $from: 'user@jabber.org/resource'
		// $body: 'message text'

		$this->sendMessage($from, 'Received message: '.$body);
	}
}

/* Daemon example */
$bot = new MyJabberBot('jabber.org', 5222, 'username', 'password', 'mybot-resource');
$bot->initialize(); // connecting, XMPP initialization
$bot->runEventBased();
