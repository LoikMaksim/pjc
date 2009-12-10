<?php
/*
	$Id$
*/
require_once('PJC_XMPP.class.php');
require_once('PJC_Sender.class.php');
require_once('PJC_Conference.class.php');

class PJC_JabberClient extends PJC_XMPP {
	protected $messagesQueue = array();
	protected $messagesQueueInterval = 1;
	protected $messagesQueueLastSendTime;

	protected $conferences = array();
	protected $status = null;

	protected $waiterEvents = array();

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
		return PJC_XMPP::parseJid($this->realm, 'short');
	}

	/* ----------------------------------- messages --------------------------*/
	protected function messageHandler($xmpp, $elt) {
		if($elt->hasParam('type') && $elt->param('type')==='error')
			return;

		$fromUser = new PJC_Sender($this, $elt->param('from'));
		$message = $elt->child('body')->getText();

		$continueHandling = true;

		if($elt->hasParam('type') && $elt->param('type') == 'groupchat') {
			$conferenceAddress = PJC_XMPP::parseJid($elt->param('from'), 'short');
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

	function sendMessage($to, $body, $type = 'chat', array $additionalElements = array()) {
		return $this->message($to, $body, $type, $additionalElements);
	}

	function sendConfMessage($to, $body) {
		return $this->message($to, $body, 'groupchat');
	}

	function _messageTruncateInvalidCharset($body) {
		$nbody = iconv('utf-8', 'utf-8//IGNORE', $body);
		if($nbody !== $body) {
			$nbody = '[MESSAGE WAS TRUNCATED. NON-UTF8 CHARACTERS DETECTED]'.$nbody;
			$this->log->warning('Non-utf8 string, truncated', $body);
		}
		return $nbody;
	}

	function message($to, $body, $type = 'chat', array $additionalElements = array()) {
		if(!is_array($body))
			$body = array('plain'=>$body);

		$stanza = array(
			'#name'=>'message',
			'from'=>$this->realm,
			'id'=>$this->genId(),
			'to'=>$to,
			'type'=>$type,
		);

		foreach($body as $type=>$message) {
			//! тут какая-то херня. Надо разобраться почему тут нет эскейпа
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

		$this->log->debug('Sending message to '.$to.' ...');
		$out = self::stanza($stanza);
		foreach($additionalElements as $e)
			$out->appendChild($e);

		$this->send((string)$out);
		$this->log->notice('Sended');
	}

	/* ------------------------------- messages queue ------------------------*/
	function addMessage($to, $body, $type = 'chat') {
		// no signal-safe
		$this->messagesQueue[] = array('to'=>$to, 'body'=>$body, 'type'=>$type);

		$delay = $this->messagesQueueInterval - (time() - $this->messagesQueueLastSendTime);

		if($delay < 0)
			$delay = 0;

		$this->cronAddOnce($delay, array($this, 'pollMessageQueue'), array(), 'JabberClient::pollMessageQueue');
		$this->messagesQueueLastSendTime = time() + $delay;
	}

	function addConfMessage($to, $body) {
		$this->addMessage($to, $body, 'groupchat');
	}

	protected function pollMessageQueue() {
		// no alarm-safe
		if(sizeof($this->messagesQueue)) {
			$message = array_shift($this->messagesQueue);
			$this->message($message['to'], $message['body'], $message['type']);
			$this->messagesQueueLastSendTime = time();
		}
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
		$conferenceAddress = PJC_XMPP::parseJid($elt->param('from'), 'short');
		if($this->joinedToConference($conferenceAddress))
			$conference = $this->conferences[$conferenceAddress]; //!
		else
			$conference = $this->registerConference($conferenceAddress);

		$user = new PJC_Sender($this, $elt->param('from'));
		$user->fromConference($conference);
		$conference->addParticipant($user);
	}

	/* -------------------------------- subscription ------------------------ */
	protected function subscribeRequestHandler($xmpp, $element) {
		$fromUser = new PJC_Sender($this, $element->getParam('from'));
		return $this->onSubscribeRequest($fromUser, $element);
	}

	public function acceptSubscription($jid) {
		$this->sendStanza(array('#name'=>'presence', 'type'=>'subscribed', 'from'=>$this->shortJid(), 'to'=>$jid));
		$this->log->notice("Subscription request from `$jid` accepted");
	}

	public function requestSubscription($jid) {
		$this->sendStanza(array('#name'=>'presence', 'type'=>'subscribe', 'from'=>$this->shortJid(), 'to'=>$jid));
		$this->log->notice("Subscription request to `$jid` sended");
	}

	public function resetSubscription($jid) {
		$this->sendStanza(array('#name'=>'presence', 'type'=>'unsubscribe', 'from'=>$this->shortJid(), 'to'=>$jid));
		$this->log->notice("Reset subscription for `$jid`");
	}

	public function removeSubscription($jid) {
		$this->sendStanza(array('#name'=>'presence', 'type'=>'unsubscribed', 'from'=>$this->shortJid(), 'to'=>$jid));
		$this->log->notice("Reset subscription for `$jid`");
	}

	/* ------------------------------------ waiter ------------------------- */

	public function wait($selector, $timeout, $callback, $callbackParameters = array(), $timedOutCallback = null, $timedOutCallbackParameters = array()) {
		if(isset($this->waiterEvents[$selector]))
			throw new Exception("Duplicate selector `$selector` in waiter");
		$cronGCIdent = 'waiter_'.$selector;

		$this->waiterEvents[$selector] = array(
				'callback' => $callback,
				'callbackParameters' => $callbackParameters,
				'timedOutCallback' => $timedOutCallback,
				'timedOutCallbackParameters' => $timedOutCallbackParameters,
				'cronGCIdent' => $cronGCIdent
		);

		$this->addHandler($selector, array($this, 'waiterStanzaHandler'), array($selector), true);
		$this->cronAddOnce($timeout, array($this, 'waiterGCHandler'), array($selector), $cronGCIdent);
	}

	protected function waiterStanzaHandler($xmpp, $element, $selector) {
		$this->removeHandler($selector);

		if(!isset($this->waiterEvents[$selector]))
			return;
		$inf = $this->waiterEvents[$selector];
		$this->cronRemoveRuleByIdent($inf['cronGCIdent']);
		unset($this->waiterEvents[$selector]);

		call_user_func_array($inf['callback'], array_merge(array($xmpp, $element), $inf['callbackParameters']));
		return false;
	}

	protected function waiterGCHandler($selector) {
		$this->removeHandler($selector);

		if(!isset($this->waiterEvents[$selector]))
			return;
		$inf = $this->waiterEvents[$selector];
		if($inf['timedOutCallback'])
			call_user_func_array($inf['timedOutCallback'], $inf['timedOutCallbackParameters']);

		unset($this->waiterEvents[$selector]);
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
				array('#name'=>'os', php_uname('s'))
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
		$this->log->debug('Unhandled message', $elt->dump());
	}

	protected function onPrivateMessage($fromUser, $body, $subject, $elt) {}
	protected function onConferenceMessage($fromConference, $fromUser, $body, $elt) {}

	protected function onSessionStarted() {}
	protected function onSubscribeRequest($fromUser, $elt) {
		$this->log->notice('Subscription Request', $elt->dump());
	}

	/*  ------------------ cron periodic ----------------------- */
	protected function daily() {}
	protected function hourly() {}
}