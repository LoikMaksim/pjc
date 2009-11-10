<?php
require_once(dirname(__FILE__).'/../PJC_JabberClient.class.php');

class MyJabberBot extends PJC_JabberClient {
	/*
		Base event.
		Runs when message received
	*/
	protected function onMessage($fromUser, $body) {
		// $from: User
		// $body: 'message text'

		$fromUser->sendMessage('Received message: '.$body);
	}
}

/* Daemon example */
$bot = new MyJabberBot('jabber.org', 5222, 'username', 'password', 'mybot-resource');
$bot->initiate(); // connecting, XMPP initialization
$bot->runEventBased();
