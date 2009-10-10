<?php
/*
	$Id$
*/

require_once('XMLStreamElementException.class.php');

class XMLStreamElementMY {
	static $numInstances = 0;
	protected $name;

	protected $childs = array();
	protected $textNodes = array();
	protected $params = array();
	protected $additionalPlainXML = '';

	public function __construct($elementName) {
		self::$numInstances++;
		$this->name = $elementName;
	}

	public function __destruct() {
		self::$numInstances--;
	}

	public function appendChild($child) {
		$this->childs[] = $child;
	}

	public function appendTextNode($text) {
		$this->textNodes[] = $text;
	}

	public function appendCommentNode($text) {
		$this->textNodes[] = $text;
	}

	public function appendParameter($name, $value) {
		$this->params[$name] = $value;
	}

	public function appendPlainXML($xml) {
		$dom = new DOMDocument('1.0', 'UTF-8');
		$f = $dom->createDocumentFragment();
		if(!@$f->appendXML($xml))
			throw new XMLStreamElementException('Invalid XML fragment: '.$xml);

		$this->additionalPlainXML .= $dom->saveXML($f);
	}

	//-
	public function getName() {
		return $this->name;
	}

	public function getText() {
		return implode('', $this->textNodes);
	}

	//-
	function childs($childElementName = null) {
		if($childElementName === null)
			return $this->childs;

		$ents = array();
		foreach($this->childs as $e) {
			if($e->getName() === $childElementName) {
				$ents[] = $e;
			}
		}
		return $ents;
	}

	public function hasChild($childElementName) {
		$ents = $this->childs($childElementName);
		if(sizeof($ents) > 1)
			throw new XMLStreamElementException("Multiple entries with same name `$childElementName`: {$this->dump()}");
		return (bool)sizeof($ents);
	}

	public function child($childElementName) {
		$ents = $this->childs($childElementName);
		if(sizeof($ents) > 1)
			throw new XMLStreamElementException("Multiple entries with same name `$childElementName`: {$this->dump()}");
		elseif(!sizeof($ents))
			throw new XMLStreamElementException("No child with requested name `$childElementName`: ".$this->dump());
		return $ents[0];
	}

	public function firstChild() {
		if(sizeof($this->childs))
			return $this->childs[0];
		else
			throw new XMLStreamElementException('Element has no childs');
	}

	public function hasParam($paramName) {
		return array_key_exists($paramName, $this->params);
	}

	public function param($paramName) {
		if(!$this->hasParam($paramName))
			throw new XMLStreamElementException('Element does not have requested param `'.$paramName.'`');
		return $this->params[$paramName];
	}

	public function getParam($paramName) { // deprecated
		return $this->param($paramName);
	}

	//-
	function dump($depth = 0) {
		$out = '';
		$tab = "   ";

		$pref = str_repeat($tab, $depth);

		$out .= "$pref<{$this->getName()}> {\n";
		$npref = $pref.$tab;

		if(strlen($this->getText()))
			$out .= "{$npref}text: {$this->getText()}\n";

		if(sizeof($this->params)) {
			$out .= "{$npref}params:\n";
			foreach($this->params as $pn => $pv)
				$out .= "{$npref}{$tab}[$pn] = '$pv'\n";
		}

		if(sizeof($this->childs)) {
			$out .= "{$npref}childs:\n";
			foreach($this->childs as $e) {
				$out .= $e->dump($depth+2);
			}
		}
		$out .= "$pref}\n";

		return $out;
	}

	public function prettyXML() {
		$dom = $this->toDomDocument();
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput = true;
		return $dom->saveXML($dom->firstChild);
	}

	public function __toString() {
		$dom = $this->toDomDocument();
		$xml = $dom->saveXML($dom->firstChild);
		if(strlen($this->additionalPlainXML))
			$xml .= $this->additionalPlainXML;
		return $xml;
	}

	public function toDomElement(DOMDocument $dom) {
		$elt = $dom->createElement($this->name);
		foreach($this->params as $n=>$v)
			$elt->setAttribute($n, $v);

		foreach($this->childs as $child)
			$elt->appendChild($child->toDomElement($dom));

		foreach($this->textNodes as $text)
			$elt->appendChild($dom->createTextNode($text));

		return $elt;
	}

	public function toDomDocument() {
		$dom = new DOMDocument('1.0', 'UTF-8');
		$dom->appendChild($this->toDomElement($dom));
		return $dom;
	}
}
