<?php
/*
	$Id$
*/
require_once('XMPP.class.php');
require_once('User.class.php');
require_once('Conference.class.php');

class JabberClient extends XMPP {
	protected $messagesQueue = array();
	protected $messagesQueueInterval = 1;
	protected $messagesQueueLastSendTime;

	protected $conferences = array();
// 	protected $messagesQueuePollRequested = false;
/*
	function __construct($host, $port, $username, $password, $res = 'pajc') {
		parent::__construct($host, $port, $username, $password, $res);
	}*/

	function initiated() {
		parent::initiated();

		$this->addHandler('message:has(body)', array($this, 'messageHandler'));
		$this->addHandler('presence[type=subscribe]', array($this, 'subscribeRequestHandler'));
		$this->addHandler('presence:has(x[xmlns=http://jabber.org/protocol/muc#user])', array($this, 'conferenceUserPresenceHandler'));

		$this->cronAddPeriodic(60*60, array($this, 'hourly'));
		$this->cronAddPeriodic(60*60*24, array($this, 'daily'));

		$this->onSessionStarted();
	}

	/* ----------------------------------- messages --------------------------*/
	protected function messageHandler($xmpp, $elt) {
		$fromUser = new User($this, $elt->param('from'));
		$message = $elt->child('body')->getText();

		$continueHandling = true;

		if($elt->param('type') == 'groupchat') {
			$conferenceAddress = XMPP::parseJid($elt->param('from'), 'short');
			if(!$this->joinedToConference($conferenceAddress))
				throw new Exception("Message from unjoined conference `$conferenceAddress`");
			$continueHandling = $this->onConferenceMessage($this->conferences[$conferenceAddress], $fromUser, $message, $elt); //!
		} else {
			$continueHandling = $this->onPrivateMessage($fromUser, $message, null, $elt);
		}

		if($continueHandling !== false)
			$continueHandling = $this->onMessage($fromUser, $message, null, $elt);

		return $continueHandling;
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
		$this->out->write("<message from='{$this->realm}' to='$to' type='$type'><body>$body</body></message>");
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

	protected function pollMessageQueue() {
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
	public function joinConference($conferenceAddress, $nick = null) {
		if($nick === null)
			$nick = $this->username;

		$conference = htmlspecialchars($conferenceAddress.'/'.$nick);
		$this->out->write(
			"<presence to='$conference' id='{$this->genId()}'><x xmlns='http://jabber.org/protocol/muc' /></presence>"
		);
	}

	public function leaveConference($conferenceAddress) {
		if(!$this->joinedToConference($conferenceAddress))
			return;

		$conf = $this->conferences[$conferenceAddress];
		$conf->clear();
		unset($this->conferences[$conferenceAddress]);
	}

	protected function registerConference($conferenceAddress) {
		$conf = new Conference($this, $conferenceAddress);
		$this->conferences[$conferenceAddress] = $conf;
		return $conf;
	}

	public function joinedToConference($conferenceAddress) {
		return isset($this->conferences[$conferenceAddress]);
	}

	protected function conferenceUserPresenceHandler($xmpp, $elt) {
		$conferenceAddress = XMPP::parseJid($elt->param('from'), 'short');
		if($this->joinedToConference($conferenceAddress))
			$conference = $this->conferences[$conferenceAddress]; //!
		else
			$conference = $this->registerConference($conferenceAddress);

		$user = new User($this, $elt->param('from'));
		$user->fromConference($conference);
		$conference->addParticipant($user);
	}

	/* -------------------------------- subscription ------------------------ */
	protected function subscribeRequestHandler($xmpp, $element) {
		$fromUser = new User($this, $element->getParam('from'), $element);
		return $this->onSubscribeRequest($fromUser, $element);
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
	protected function onMessage($fromUser, $body, $subject, $elt) {
		Log::notice('Message', $elt->dump());
	}

	protected function onPrivateMessage($fromUser, $body, $subject, $elt) {}
	protected function onConferenceMessage($fromConference, $fromUser, $body, $elt) {}

	protected function onSessionStarted() {}
	protected function onSubscribeRequest($fromUser, $elt) {
		Log::notice('Subscription Request', $elt->dump());
	}

	/*  ------------------ cron periodic ----------------------- */
	protected function daily() {}
	protected function hourly() {}
}
