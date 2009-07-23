<?php
/*
	$Id$
*/

require_once('XMLTokenStream.class.php');
require_once('XMLStreamException.class.php');
require_once('XMLStreamNode.class.php');
require_once(dirname(__FILE__).'/XMLStreamElement.class.php');

class XMLStream extends XMLTokenStream {
	public function nextNode() {
	}
	public function currentNode() {
	}

	public function readNode($expectedName = null) {
		$token = $this->readToken();
		if($token === null)
			return null;

		$node = new XMLStreamNode($token);
		if($node->isXmlDeclaration())
			$node = $this->readNode();

		if($expectedName !== null && $node->getName() !== $expectedName)
			throw new XMLStreamException("Unexpected node `{$node->getName()}`. `$expectedName` expected: ".$token);

		return $node;
	}

	public function unreadNode($node) {
		$this->unreadToken($node->getXmlString());
	}

	public function readElement($expectedElementName = null) {
		while(true) {
			$node = $this->readNode($expectedElementName);
			if(!$node)
				return null;
			if(!$node->isXmlDeclaration())
				break;
		}

		if(!($node->isOpenTag() || $node->isEmpty()))
			throw new XMLStreamException('Unexpected node `'.$node->getName().'`. Open tag expected');

		$elementName = $node->getName();

		$elt = new XMLStreamElementMY($node->getName());

		foreach($node->getParams() as $k=>$v)
			$elt->appendParameter($k, $v);

		if(!$node->isEmpty()) {
			while(true) {
				$node = $this->readNode();
				if(!$node)
					throw new XMLStreamException('Unexpected end of stream');

				if($node->isCloseTag()) {
					if($node->getName() === $elementName)
						break;
					else
						throw new XMLStreamException('Unexpected close tag `'.$node->getName().'`. `'.$elementName.'` expected');
				}

				if($node->isData()) {
					$elt->appendTextNode($node->getText());
				} elseif($node->isComment()) {
					$elt->appendCommentNode($node->getText());
				} elseif($node->isEmpty() || $node->isOpenTag()) {
					$this->unreadNode($node);

					$elt->appendChild($this->readElement());
				} else {
					throw new XMLStreamException('Strange XML token detected `'.$node->getXmlString().'`');
				}
			}
		}
		return $elt;
	}
}
/*
error_reporting(E_ALL);
$xml = new XMLStream(STDIN);
while(($token = $xml->readElement())) {
// 	var_dump($token);
}*/
