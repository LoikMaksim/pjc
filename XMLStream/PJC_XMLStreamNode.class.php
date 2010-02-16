<?php
/*
	$Id$
*/

class PJC_XMLStreamNode {
	static $numInstances = 0;

	const TYPE_XML_DECLARATION = 'xml declaration';
	const TYPE_OPEN = 'open';
	const TYPE_EMPTY = 'empty';
	const TYPE_CLOSE = 'close';
	const TYPE_TEXT = 'text';
	const TYPE_CDATA = 'cdata';
	const TYPE_COMMENT = 'comment';

	protected $type = null;
	protected $params = array();
	protected $name = null;
	protected $text = null;

	function __construct($importString = null) {
		if($importString !== null) {
			$this->xmlString = $importString;
			$this->parse($importString);
		}
		self::$numInstances++;
	}

	public function __destruct() {
		self::$numInstances--;
	}

	public function setType($type) {
		$this->type = $type;
	}

	public function setName($name) {
		$this->name = $name;
	}

	public function setAttributes($attributes) {
		$this->params = $attributes;
	}

	public function setText($text) {
		$this->text = $text;
	}

	function getXmlString() {
		return $this->xmlString;
	}

	function isXmlDeclaration() {
		return $this->type === self::TYPE_XML_DECLARATION;
	}

	function isOpenTag() {
		return $this->type === self::TYPE_OPEN;
	}

	function isEmpty() {
		return $this->type === self::TYPE_EMPTY;
	}

	function isCloseTag() {
		return $this->type === self::TYPE_CLOSE;
	}

	function isText() {
		return $this->type === self::TYPE_TEXT;
	}

	function isCdata() {
		return $this->type === self::TYPE_CDATA;
	}

	function isComment() {
		return $this->type === self::TYPE_COMMENT;
	}

	// --

	function getText() {
		return $this->text;
	}

	function getName() {
		return $this->name;
	}

	function getParams() {
		return $this->params;
	}

	// --

	function isData() {
		return $this->isText() || $this->isCdata();
	}
}
