<?php
require_once('PJC_JabberClient.class.php');
require_once('PJC_Conference.class.php');

class PJC_Sender {
	static $numInstances = 0;

	protected $xmpp;
	protected $jid;
	protected $conference;

	function __construct(PJC_JabberClient $xmpp, $jid) {
		$this->xmpp = $xmpp;
		$this->jid = $jid;
		self::$numInstances++;
	}

	public function __destruct() {
		self::$numInstances--;
	}


	public function shortJid() {
		return PJC_XMPP::parseJid($this->jid, 'short');
	}

	public function jid() {
		return $this->jid;
	}

	public function resource() {
		return PJC_XMPP::parseJid($this->jid, 'resource');
	}

	/* ------------------------- messages ------------------------------- */

	public function sendMessage($body) {
		$this->xmpp->sendMessage($this->jid, $body);
	}

	public function addMessage($body) {
		$this->xmpp->addMessage($this->jid, $body);
	}

	/* ------------------------- subscription --------------------------- */

	public function acceptSubscription() {
		$this->xmpp->acceptSubscription($this->shortJid());
	}

	public function requestSubscription() {
		$this->xmpp->requestSubscription($this->shortJid());
	}

	public function resetSubscription() {
		$this->xmpp->resetSubscription($this->shortJid());
	}

	/* ---------------------------- conference -------------------------- */
	public function isFromConference() {
		return $this->conference() !== null;
	}

	public function conference() {
		return $this->conference;
	}

	/* -------------------------- system -------------------------------- */

	public function clear() {
		$this->xmpp = null;
		$this->conference = null;
	}

	public function fromConference($conference) {
		$this->conference = $conference;
	}
}
