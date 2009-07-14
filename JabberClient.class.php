<?php
/*
	$Id: JabberClient.class.php 9 2009-07-13 16:55:06Z arepo $
*/
require_once('XMPP.class.php');

class JabberClient extends XMPP {
	protected $messagesQueue = array();
	protected $messagesQueueInterval = 1;
	protected $messagesQueueLastSendTime;
// 	protected $messagesQueuePollRequested = false;

	function __construct($host, $port, $username, $password, $res = 'pajc') {
		parent::__construct($host, $port, $username, $password, $res);

		$this->addHandler('message', array($this, 'messageHandler'));

		$this->cronAddPeriodic(60*60, array($this, 'hourly'));
		$this->cronAddPeriodic(60*60*24, array($this, 'daily'));
	}

	protected function messageHandler($xmpp, $elt) {
		return $this->onMessage($elt->getParam('from'), $elt->child('body')->getText(), null, $elt);
	}

	function addMessage($to, $body, $type = 'chat') {
		// no signal-safe
		$this->messagesQueue[] = array('to'=>$to, 'body'=>$body, 'type'=>$type);

		$delay = $this->messagesQueueInterval - (time() - $this->messagesQueueLastSendTime);

		if($delay < 0)
			$delay = 0;

		$this->cronAddOnce($delay, array($this, 'pollMessageQueue'), 'JabberClient::pollMessageQueue');
		$this->messagesQueueLastSendTime = time() + $delay;
	}

	function pollMessageQueue() {
		// no alarm-safe
		Log::notice('pollMessageQueue() ...');

		if(sizeof($this->messagesQueue)) {
			$message = array_shift($this->messagesQueue);
			$this->message($message['to'], $message['body'], $message['type']);
			$this->messagesQueueLastSendTime = time();
		}

		Log::notice('pollMessageQueue() ended');
	}

	/* ---------------- predefined handlers -------------------- */
	protected function onMessage($from, $body, $subject, $elt) {}

	/*  ------------------ cron periodic ----------------------- */
	protected function daily() {}
	protected function hourly() {}
}
