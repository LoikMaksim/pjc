<?php
require_once('JabberClient.class.php');
require_once('Conference.class.php');

class User {
	protected $xmpp;
	protected $jid;
	protected $conference;

	function __construct(JabberClient $xmpp, $jid) {
		$this->xmpp = $xmpp;
		$this->jid = $jid;
	}

	public function shortJid() {
		return XMPP::parseJid($this->jid, 'short');
	}

	public function jid() {
		return $this->jid;
	}

	/* ------------------------- messages ------------------------------- */

	public function sendMessage($body) {
		$this->xmpp->sendMessage($this->jid, $body);
	}

	public function addMessage($body) {
		$this->xmpp->addMessage($this->jid, $body);
	}

	/* ------------------------- subscription --------------------------- */

	public function acceptSubscribtion() {
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
