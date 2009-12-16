<?php
/*
	$Id$
*/

require_once('PJC_XMLTokenStream.class.php');
require_once('PJC_XMLStreamException.class.php');
require_once('PJC_XMLStreamNode.class.php');
require_once(dirname(__FILE__).'/PJC_XMLStreamElement.class.php');

class PJC_XMLStream extends PJC_XMLTokenStream {
	public function nextNode() {
	}
	public function currentNode() {
	}

	public function readNode($expectedName = null) {
		$token = $this->readToken();
		if($token === null)
			return null;

		$node = new PJC_XMLStreamNode($token);
		if($node->isXmlDeclaration())
			$node = $this->readNode();

		if($expectedName !== null && $node->getName() !== $expectedName)
			throw new PJC_XMLStreamException("Unexpected node `{$node->getName()}`. `$expectedName` expected: ".$token);

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
			throw new PJC_XMLStreamException('Unexpected node `'.$node->getName().'`. Open tag expected');

		$elementName = $node->getName();

		$elt = new PJC_XMLStreamElement($node->getName());

		foreach($node->getParams() as $k=>$v)
			$elt->appendParameter($k, $v);

		if(!$node->isEmpty()) {
			while(true) {
				$node = $this->readNode();
				if(!$node)
					throw new PJC_XMLStreamException('Unexpected end of stream');

				if($node->isCloseTag()) {
					if($node->getName() === $elementName)
						break;
					else
						throw new PJC_XMLStreamException('Unexpected close tag `'.$node->getName().'`. `'.$elementName.'` expected');
				}

				if($node->isData()) {
					$elt->appendTextNode($node->getText());
				} elseif($node->isComment()) {
					$elt->appendCommentNode($node->getText());
				} elseif($node->isEmpty() || $node->isOpenTag()) {
					$this->unreadNode($node);

					$elt->appendChild($this->readElement());
				} else {
					throw new PJC_XMLStreamException('Strange XML token detected `'.$node->getXmlString().'`');
				}
			}
		}
		return $elt;
	}
}
