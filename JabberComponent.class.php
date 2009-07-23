<?php
require_once('JabberClient.class.php');
require_once('XMPP.class.php');

class JabberComponent extends JabberClient {
	function initiate() {
		$this->connect();
		$this->out->write('<?xml version="1.0" encoding="UTF-8"?>');
		$this->out->write("<stream:stream xmlns='jabber:component:accept' xmlns:stream='http://etherx.jabber.org/streams' to='jubo.nologin.ru'>");
		$n = $this->in->readNode();
		$params = $n->getParams();
		$this->out->write('<handshake>'.sha1($params['id'].'prevedmir').'</handshake>');
		$this->in->readElement();

		$this->initiated();
		Log::notice('Session initiated');
		$this->realm = 'jubo@jubo.nologin.ru/res';
		$this->presence();
	}

	function initiated() {
		parent::initiated();

		$this->addHandler('iq[type=get]:has(query[xmlns=jabber:iq:last])', array($this, 'iqLastHandler'));
		$this->addHandler('iq[type=get]:has(vCard[xmlns=vcard-temp])', array($this, 'vcardRequestHandler'));
		$this->addHandler('presence[type=probe]', array($this, 'presenceProbeHandler'));
		$this->addHandler('iq[type=get]:has(query[xmlns=http://jabber.org/protocol/disco#info])', array($this, 'discoHandler'));

	}

	function iqLastHandler($xmpp, $elt) {
		$to = $elt->param('to');
		if(XMPP::parseJid($this->realm, 'short') !== XMPP::parseJid($to, 'short'))
			return;

		$id = htmlspecialchars($elt->param('id'));
		$from = htmlspecialchars($elt->param('from'));

		$this->out->write(
				"<iq from='{$this->realm}' id='$id' to='$from' type='result'>".
					"<query xmlns='jabber:iq:last' seconds='0'/>".
				"</iq>"
		);
	}

	function vcardRequestHandler($xmpp, $elt) {
		$to = $elt->param('to');
		if(XMPP::parseJid($this->realm, 'short') !== XMPP::parseJid($to, 'short'))
			return;

		$id = htmlspecialchars($elt->param('id'));
		$from = htmlspecialchars($elt->param('from'));

		$myShortJid = XMPP::parseJid($this->realm, 'short');

		$this->out->write($q=
			"<iq id='$id' to='$from' from='$myShortJid' type='result'>".
				"<vCard xmlns='vcard-temp'>".
					"<FN>JuBo</FN>".
					"<NICKNAME>JuBo</NICKNAME>".
					"<URL>http://jubo.nologin.ru/</URL>".
					"<BDAY>2009-07-19</BDAY>".
					"<ROLE>Juick Bot</ROLE>".
					"<JABBERID>jubo@nologin.ru</JABBERID>".
				"</vCard>".
			"</iq>"
		);
// 		echo $q;
	}

	protected function presenceProbeHandler($xmpp, $elt) {
		$to = $elt->param('to');
		if($this->shortJid() !== XMPP::parseJid($to, 'short'))
			return;

// 		$id = htmlspecialchars($elt->param('id'));
		$from = htmlspecialchars($elt->param('from'));
		$this->out->write(
			"<presence  from='{$this->realm}' to='$from'>".
				"<poke xmlns='http://jabber.org/protocol/poke'/>".
			"</presence>"
		);
	}

	protected function discoHandler($xmpp, $elt) {
		$to = $elt->param('to');
		$id = htmlspecialchars($elt->param('id'));
		$from = htmlspecialchars($elt->param('from'));


	}

	protected function onSubscribeRequest($fromUser, $elt) {
		$fromUser->acceptSubscription();
	}
}
