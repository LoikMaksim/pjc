<?php
require_once('PJC_Sender.class.php');
require_once('PJC_JabberClient.class.php');

class PJC_Conference {
	protected $users;
	protected $address;

	function __construct(PJC_JabberClient $xmpp, $address) {
		$this->xmpp = $xmpp;
		$this->address = $address;
	}

	public function address() {
		return $address;
	}

	public function leave() {
		$this->xmpp->leaveConference($this->address);
	}

	/* ------------------------- messages ------------------------------- */

	public function sendMessage($body) {
		$this->xmpp->sendMessage($this->address, $body, 'groupchat');
	}

	public function addMessage($body) {
		$this->xmpp->addMessage($this->address, $body, 'groupchat');
	}

	/* --------------------------- system ------------------------------- */
	public function clear() {
		$this->xmpp = null;
	}

	public function addParticipant($user) {
		$this->users[$user->jid()] = $user;
	}

	public function delParticipant($user) {
		unset($this->users[$user->jid()]);
	}
}
