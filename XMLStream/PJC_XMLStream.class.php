<?php
/*
	$Id$
*/

require_once('PJC_Stream.class.php');
require_once('PJC_XMLStreamException.class.php');
require_once('PJC_XMLStreamNode.class.php');
require_once('PJC_XMLNodeStream.class.php');
require_once('PJC_XMLStreamElement.class.php');

class PJC_XMLStream extends PJC_XMLNodeStream {
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
