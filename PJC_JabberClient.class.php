<?php
/*
	$Id$
*/
require_once('PJC_XMPP.class.php');
require_once('PJC_Sender.class.php');
require_once('PJC_Conference.class.php');

/**
*	Класс, предоставляющий главный функционал для работы с XMPP.
*	В отличии от PJC_XMPP этот класс реализует расширенный функционал,
*	такой как отправка сообщений, управление статусами, конференции и т.д.
*/
class PJC_JabberClient extends PJC_XMPP {
	/**
	*	Очередь сообщений для отправки, сообщения из очереди отправляются раз
	*	в $messagesQueueInterval секунд.
	*	@see PJC_JabberClient::addMessage()
	*/
	protected $messagesQueue = array();
	/**
	*	Интервал отправки сообщений из очереди (в секундах).
	*/
	protected $messagesQueueInterval = 1;
	/**
	*	Дата последней отправки сообщения из очереди.
	*/
	protected $messagesQueueLastSendTime;

	/**
	*	Список конференций, в которых в данный момент состоит клиент.
	*/
	protected $conferences = array();

	/**
	*	Текущее статусное сообщение.
	*/
	protected $status = null;

	/**
	*	Информация по событиям вейтеров.
	*	@see PJC_JabberClient::wait()
	*/
	protected $waiterEvents = array();

	protected $presenceCapsNode = 'http://pjc.googlecode.com/caps';
	protected $clientSoftwareName = 'PJC';
	protected $clientSoftwareVersion = '0.02';

	/**
	*	Событие, вызываемое сразу после завершения инициализации XMPP-сессии и
	*	отправки presence.
	*/
	protected function initiated() {
		parent::initiated();

		$this->addHandler('message:has(body)', array($this, 'messageHandler'));
		$this->addHandler('presence[type=subscribe]', array($this, 'subscribeRequestHandler'));
		$this->addHandler('presence[type=unsubscribed]', array($this, 'unsubscribedHandler'));
		$this->addHandler('presence[type=unsubscribe]', array($this, 'unsubscribeHandler'));
		$this->addHandler('presence:has(x[xmlns=http://jabber.org/protocol/muc#user])', array($this, 'conferenceUserPresenceHandler'));
		$this->addHandler('iq[type=get]:has(query[xmlns=jabber:iq:version])', array($this, 'versionRequestHandler'));

		$this->cronAddPeriodic(60*60, array($this, 'hourly'));
		$this->cronAddPeriodic(60*60*24, array($this, 'daily'));

		$this->onSessionStarted();
	}

	/* ----------------------------------- messages --------------------------*/
	/**
	*	Служебный обработчик <message>-станзы
	*/
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

	/**
	*	Отослать сообщение.
	*	Альяс для message()
	*	@see PJC_JabberClient::message()
	*/
	public function sendMessage($to, $body, $type = 'chat', array $additionalElements = array()) {
		return $this->message($to, $body, $type, $additionalElements);
	}

	/**
	*	Отослать сообщение в конференцию.
	*	Просто хелпер, по существу - сокращённая до минимума форма sendMessage()
	*	@param string jid конференции
	*	@param string текст
	*/
	public function sendConfMessage($to, $body) {
		return $this->message($to, $body, 'groupchat');
	}

	/**
	*	Служебная функция для вырезания неюникодных символов из входной строки.
	*	Используется перед отсылкой сообщений. Если обнаружены невалидные
	*	символы, то они удаляются, а в начало строки добавляется сообщение
	*	'[MESSAGE WAS TRUNCATED. NON-UTF8 CHARACTERS DETECTED]'.
	*
	*	@param string
	*	@return string
	*/
	private function _messageTruncateInvalidCharset($body) {
		$nbody = iconv('utf-8', 'utf-8//IGNORE', $body);
		if($nbody !== $body) {
			$nbody = '[MESSAGE WAS TRUNCATED. NON-UTF8 CHARACTERS DETECTED]'.$nbody;
			$this->log->warning('Non-utf8 string, truncated', $body);
		}
		return $nbody;
	}

	/**
	*	Отослать сообщение.
	*	@param string jid получателя
	*	@param string текст
	*	@param string тип
	*	@param array дополнительные элементы внутри станзы сообщения (пока что принимается только массив PJC_XmlStreamElement)
	*/
	protected function message($to, $body, $type = 'chat', array $additionalElements = array()) {
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
			//! тут какая-то херня. Надо разобраться почему не делется эскейп
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
	/**
	*	Добавление сообщения в очередь на отправку.
	*	Используется если надо отослать сразу много сообщений,
	*	а сервер перекрывает. Сообщение добавляется в очередь, отправка же
	*	осуществляется раз в $this->messagesQueueLastSendTime секунд
	*	@param string jid получателя
	*	@param string текст
	*	@param string тип
	*/
	public function addMessage($to, $body, $type = 'chat') {
		// no signal-safe
		$this->messagesQueue[] = array('to'=>$to, 'body'=>$body, 'type'=>$type);

		$delay = $this->messagesQueueInterval - (time() - $this->messagesQueueLastSendTime);

		if($delay < 0)
			$delay = 0;

		$this->cronAddOnce($delay, array($this, 'pollMessageQueue'), array(), 'JabberClient::pollMessageQueue');
		$this->messagesQueueLastSendTime = time() + $delay;
	}

	public function addConfMessage($to, $body) {
		$this->addMessage($to, $body, 'groupchat');
	}

	/**
	*	Служебный обработчик для отправки сообщений из очереди.
	*/
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

	public function registerConference($conferenceAddress) {
		$conf = new PJC_Conference($this, $conferenceAddress);
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

	protected function unsubscribedHandler($xmpp, $element) {
		$fromUser = new PJC_Sender($this, $element->getParam('from'));
		return $this->onUnsubscribed($fromUser, $element);
	}

	protected function unsubscribeHandler($xmpp, $element) {
		$fromUser = new PJC_Sender($this, $element->getParam('from'));
		return $this->onUnsubscribe($fromUser, $element);
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
	/**
	*	Ставит одноразовый обработчик с таймаутом для первой станзы, подпадающей под селектор.
	*	При получении нужной станзы вызывается $callback, первым параметром
	*	при выове будет сам жаббер-клиент, вторым станза, остальные параметры
	*	будут браться из $callbackParameters.
	*	Если прошло $timeout секунд, а станзы, подпадающей под селектор не было обнаружено,
	*	то вызывается $timedOutCallback с параметрами из $timedOutCallbackParameters.
	*	После обработки первой станзы или таймаута хук на станзу удаляется.
	*	@param string селектор
	*	@param float таймаут
	*	@param callback коллбек, вызываемый при получении нужной станзы
	*	@param array параметры коллбека
	*	@param callback коллбек, вызываемый при таймауте ожидания
	*	@param array параметры коллбека
	*/
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

	/**
	*	Служебный обработчик вейтеров.
	*	Вызывается при срабатывании хука на станзу, которую запрашивал
	*	любой из вейтеров. Выбирается нужный вейтер, вызывается его обработчик
	*	и подчищается весь мусор
	*/
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

	/**
	*	Служебный обработчик, вызывается при таймауте вейтера, выывает обработчик
	*	таймаута вейтера и чистит мусор.
	*/
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
	/**
	*	Хендлер, отвечающий за ответы на запрос версии и информации о
	*	используемом jabber-клиенте
	*/
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

	/**
	*	Устанавливает статусное сообщения клиента.
	*	@param string статусное сообщение
	*/
	public function setUserStatus($statusString) {
		$this->status = $statusString;
		$this->presence();
	}

	/**
	*	Отослать presence-сообщение.
	*	@param string jid получателя, если не указано, но рассылается всему контакт-листу (это делает jabber-сервер)
	*/
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
				'hash'=>'sha-1',
				'node'=>$this->presenceCapsNode,
				'ver'=>$this->clientSoftwareName.' '.$this->clientSoftwareVersion
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
	/**
	*	Обработчик входящих сообщений.
	*	Вызывается на любое входящее сообщение (из конференции или личное).
	*	@param PJC_Sender отправитель
	*	@param string текст
	*	@param string заголовок сообщения
	*	@param PJC_XmlStreamElement станза сообщения
	*/
	protected function onMessage($fromUser, $body, $subject, $elt) {
		$this->log->debug('Unhandled message', $elt->dump());
	}

	/**
	*	Обработчик входящих личных сообщений.
	*	После завершения этого обработчика вызывается событие onMessage(),
	*	если этого не требуется, то можно вернуть false, тогда дальнейшая
	*	обработка производиться не будет
	*	@param PJC_Sender отправитель
	*	@param string текст
	*	@param string заголовок
	*	@param PJC_XmlStreamElement станза сообщения
	*
	*	@return null|false
	*/
	protected function onPrivateMessage($fromUser, $body, $subject, $elt) {}

	/**
	*	Обработчик входящих сообщений из конференций.
	*	После завершения этого обработчика вызывается событие onMessage(),
	*	если этого не требуется, то можно вернуть false, тогда дальнейшая
	*	обработка производиться не будет
	*	@param PJC_Conference конференция
	*	@param PJC_Sender отправитель
	*	@param string текст
	*	@param PJC_XmlStreamElement станза сообщения
	*
	*	@return null|false
	*/
	protected function onConferenceMessage($fromConference, $fromUser, $body, $elt) {}

	/**
	*	Обработчик, вызываемый после установки всех системных хендлеров.
	*/
	protected function onSessionStarted() {}

	/**
	*	Обработчик запроса авторизации.
	*	@param PJC_Sender отправитель
	*	@param PJC_XmlStreamElement станза запроса
	*/
	protected function onSubscribeRequest($fromUser, $elt) {
		$this->log->notice('Subscription Request', $elt->dump());
	}
	protected function onUnsubscribed($fromUser, $stanza) {
		$this->log->notice('`Unsubscribed` message received', $stanza->dump());
	}
	protected function onUnsubscribe($fromUser, $stanza) {
		$this->log->notice('`Unsubscribe` message received', $stanza->dump());
	}

	/*  ------------------ cron periodic ----------------------- */
	/**
	*	Обработчик периодического события.
	*	Вызывается раз в сутки аптайма (первый раз через 24 часа аптайма, второй через 48 и т.д.)
	*/
	protected function daily() {}

	/**
	*	Обработчик периодического события.
	*	Вызывается раз в час аптайма (первый раз через час аптайма, второй через два и т.д.)
	*/
	protected function hourly() {}
}
