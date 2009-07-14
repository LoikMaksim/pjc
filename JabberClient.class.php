<?php
/*
	$Id$
*/
require_once('XMPP.class.php');

class JabberClient extends XMPP {
	protected $messagesQueue = array();
	protected $messagesQueueInterval = 1;
	protected $messagesQueueLastSendTime;
// 	protected $messagesQueuePollRequested = false;
/*
	function __construct($host, $port, $username, $password, $res = 'pajc') {
		parent::__construct($host, $port, $username, $password, $res);
	}*/

	function initiated() {
		parent::initiated();

		$this->addHandler('message', array($this, 'messageHandler'));
		$this->addHandler('presence[type=subscribe]', array($this, 'subscribeRequestHandler'));

		$this->cronAddPeriodic(60*60, array($this, 'hourly'));
		$this->cronAddPeriodic(60*60*24, array($this, 'daily'));

		$this->onSessionStarted();
	}

	/* ----------------------------------- messages --------------------------*/
	protected function messageHandler($xmpp, $elt) {
		return $this->onMessage($elt->getParam('from'), $elt->child('body')->getText(), null, $elt);
	}

	function sendMessage($to, $body, $type = 'chat') {
		return $this->message($to, $body, $type);
	}

	function message($to, $body, $type = 'chat') {
		$nbody = iconv('utf-8', 'utf-8//IGNORE', $body);
		if($nbody !== $body) {
			$nbody = '[MESSAGE WAS TRUNCATED. NON-UTF8 CHARACTERS DETECTED]'.$nbody;
			Log::warning('Non-utf8 string, truncated', $body);
		}
		$body = htmlspecialchars($nbody);
		Log::notice('Sending message to '.$to.' ...');
		$this->out->write("<message from='{$this->realm}' to='$to' type='chat'><body>$body</body></message>");
		Log::notice('Sended');
	}

	/* ------------------------------- messages queue ------------------------*/
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

	/* -------------------------------- conference -------------------------- */
	function joinConference($conference, $nick = null) {
		if($nick === null)
			$nick = $this->username;

		$conference = htmlspecialchars($conference.'/'.$nick);
		$this->out->write(
			"<presence to='$conference' id='{$this->genId()}'><x xmlns='http://jabber.org/protocol/muc' /></presence>"
		);
	}

	/* -------------------------------- subscription ------------------------ */
	protected function subscribeRequestHandler($xmpp, $element) {
		return $this->onSubscribeRequest($element->getParam('from'));
	}

	public function acceptSubscription($jid) {
		$this->out->write('<presence to="'.$jid.'" type="subscribed"/>');
		Log::notice("Subscription request from `$jid` accepted");
	}

	public function requestSubscription($jid) {
		$this->out->write('<presence to="'.$jid.'" type="subscribe"/>');
		Log::notice("Subscription request to `$jid` sended");
	}

	public function resetSubscription($jid) {
		$this->out->write('<presence to="'.$jid.'" type="unsubscribe"/>');
		Log::notice("Reset subscription for `$jid`");
	}

	/* ---------------- predefined handlers -------------------- */
	protected function onMessage($from, $body, $subject, $elt) {
		Log::notice('Message', $elt->dump());
	}

	protected function onSessionStarted() {}
	protected function onSubscribeRequest() {}

	/*  ------------------ cron periodic ----------------------- */
	protected function daily() {}
	protected function hourly() {}
}
