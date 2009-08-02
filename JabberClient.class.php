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
	protected $status = null;
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
		$this->addHandler('iq[type=get]:has(query[xmlns=jabber:iq:version])', array($this, 'versionRequestHandler'));

		$this->cronAddPeriodic(60*60, array($this, 'hourly'));
		$this->cronAddPeriodic(60*60*24, array($this, 'daily'));

		$this->onSessionStarted();
	}

	function shortJid() {
		return XMPP::parseJid($this->realm, 'short');
	}

	/* ----------------------------------- messages --------------------------*/
	protected function messageHandler($xmpp, $elt) {
		if($elt->hasParam('type') && $elt->param('type')==='error')
			return;

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

	function sendConfMessage($to, $body) {
		return $this->message($to, $body, 'groupchat');
	}

	function _messageTruncateInvalidCharset($body) {
		$nbody = iconv('utf-8', 'utf-8//IGNORE', $body);
		if($nbody !== $body) {
			$nbody = '[MESSAGE WAS TRUNCATED. NON-UTF8 CHARACTERS DETECTED]'.$nbody;
			Log::warning('Non-utf8 string, truncated', $body);
		}
		return $nbody;
	}

	function message($to, $body, $type = 'chat') {
		if(!is_array($body))
			$body = array('plain'=>$body);

		$stanza = array(
			'#name'=>'message',
			'from'=>$this->realm,
			'to'=>$to,
			'type'=>$type,
		);

		foreach($body as $type=>$message) {
			$message = $this->_messageTruncateInvalidCharset($message);
			if($type === 'xhtml') {
				$stanza[] = array(
					'#name'=>'html',
					'xmlns'=>'http://jabber.org/protocol/xhtml-im',
					array(
						'#name'=>'body',
						'xmlns'=>'http://www.w3.org/1999/xhtml',
						'#plainXML'=>$message
					)
				);
			} else {
				$stanza[] = array('#name'=>'body', $message);
			}
		}

		Log::notice('Sending message to '.$to.' ...');

		$this->sendStanza($stanza);
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

	function addConfMessage($to, $body) {
		$this->addMessage($to, $body, 'groupchat');
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

		$this->sendStanza(array(
			'#name'=>'presence',
			'to'=>"$conferenceAddress/$nick",
			array(
				'#name'=>'x',
				'xmlns'=>'http://jabber.org/protocol/muc'
			)
		));
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
		$this->sendStanza(array('#name'=>'presence', 'type'=>'subscribed', 'from'=>$this->shortJid(), 'to'=>$jid));
		Log::notice("Subscription request from `$jid` accepted");
	}

	public function requestSubscription($jid) {
		$this->sendStanza(array('#name'=>'presence', 'type'=>'subscribe', 'from'=>$this->shortJid(), 'to'=>$jid));
		Log::notice("Subscription request to `$jid` sended");
	}

	public function resetSubscription($jid) {
		$this->sendStanza(array('#name'=>'presence', 'type'=>'unsubscribe', 'from'=>$this->shortJid(), 'to'=>$jid));
		Log::notice("Reset subscription for `$jid`");
	}

	public function removeSubscription($jid) {
		$this->sendStanza(array('#name'=>'presence', 'type'=>'unsubscribed', 'from'=>$this->shortJid(), 'to'=>$jid));
		Log::notice("Reset subscription for `$jid`");
	}

	/* --------------------- client info --------------------------- */
	protected function versionRequestHandler($xmpp, $element) {
		if(!$element->hasParam('id') || !$element->hasParam('from'))
			return;
		$id = $element->param('id');
		$jid = $element->param('from');

		$this->sendStanza(array(
			'#name'=>'iq',
			'type'=>'result',
			'to'=>$jid,
			'id'=>$id,
			array(
				'#name'=>'query',
				'xmlns'=>'jabber:iq:version',
				array('#name'=>'name', 'PJC'),
				array('#name'=>'version', '0.0.2'),
				array('#name'=>'os', 'FreeBSD')
			)
		));
	}

	public function setUserStatus($statusString) {
		$this->status = $statusString;
		$this->presence();
	}

	public function presence($to = null) {
		$stanza = array(
			'#name'=>'presence',
			array(
				'#name'=>'x',
				'xmlns'=>'vcard-temp:x:update',
				array('#name'=>'photo')
			),
			array(
				'#name'=>'c',
				'xmlns'=>'http://jabber.org/protocol/caps',
				'hash'=>'sha-1'
			),
			array('#name'=>'priority', $this->priority)
		);

		if($to !== null)
			$stanza['to'] = $to;

		if($this->status !== null)
			$stanza[] = array('#name'=>'status', $this->status);

		$this->sendStanza($stanza);
	}

	// XEP-080
	public function setGeolocation($countryName, $locality, $latitude, $longitude, $accuracy = 5) {
		$this->sendStanza(array(
			'#name'=>'iq',
			'type'=>'set',
			'to'=>'jubo@nologin.ru',
			array(
				'#name'=>'pubsub',
				'xmlns'=>'http://jabber.org/protocol/pubsub',
				array(
					'#name'=>'publish',
					'node'=>'http://jabber.org/protocol/geoloc',
					array(
						'#name'=>'item',
						array(
							'#name'=>'geoloc',
							'xmlns'=>'http://jabber.org/protocol/geoloc',
							'xml:lang'=>'en',
							array('#name'=>'country', $countryName),
							array('#name'=>'locality', $locality),
							array('#name'=>'lat', $latitude),
							array('#name'=>'lon', $longitude),
							array('#name'=>'accuracy', $accuracy)
						)
					)
				)
			)
		));
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
