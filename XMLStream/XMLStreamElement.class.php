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
			throw new XMLStreamElementException('Multiple entries with same name');
		return (bool)sizeof($ents);
	}

	public function child($childElementName) {
		$ents = $this->childs($childElementName);
		if(sizeof($ents) > 1)
			throw new XMLStreamElementException('Multiple entries with same name');
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

	static function escapeText($str) {
		return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
	}

	static function escapeParam($str) {
		return self::escapeText($str);
	}

	static function quoteParam($str) {
		return "'".self::escapeParam($str)."'";
	}

	public function __toString() {
		$xml = "<{$this->name}";

		$psa = array();
		foreach($this->params as $name=>$value) {
			if($value === true)
				$psa[] = $name;
			else
				$psa[] = "$name=".self::quoteParam($value);
		}
		if(sizeof($psa))
			$xml .= ' '.implode(' ', $psa);
		unset($psa);

		$content = self::escapeText($this->getText());
		if(sizeof($this->childs)) {
			foreach($this->childs as $child)
				$content .= (string)$child;
		}

		if(strlen($content))
			$xml .= '>'.$content.'</'.$this->name.'>';
		else
			$xml .= ' />';

		return $xml;
	}
}
