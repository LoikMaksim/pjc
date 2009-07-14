<?php
/*
	$Id: XMLStreamElement.class.php 9 2009-07-13 16:55:06Z arepo $
*/

require_once('XMLStreamElementException.class.php');

class XMLStreamElementMY {
	protected $name;

	protected $childs = array();
	protected $textNodes = array();
	protected $params = array();

	public function __construct($elementName) {
		$this->name = $elementName;
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
			throw new XMLStreamElementException('No child with requested name');
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
}
