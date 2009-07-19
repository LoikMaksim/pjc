<?php
/*
	$Id$
*/

class XMLStreamNode {
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

	protected $xmlString = null;

	function __construct($importString) {
		$this->xmlString = $importString;
		$this->parse($importString);
	}

	function getXmlString() {
		return $this->xmlString;
	}

	function parse($importString) {
		$this->params = array();
		$this->text = null;

		if(strpos($importString, '<![CDATA[') === 0) {
			$this->name = '#cdata';
			$this->text = trim(substr($importString, strlen('<![CDATA['), strlen($importString) - (strlen('<![CDATA[') + strlen(']]>'))));
			$this->type = self::TYPE_CDATA;

		} elseif(strpos($importString, '<!--') === 0) {
			$this->name = '#comment';
			$this->text = trim(substr($importString, strlen('<!--'), strlen($importString) - (strlen('<!--') + strlen('-->'))));
			$this->type = self::TYPE_COMMENT;

		} elseif(strpos($importString, '<?xml') === 0) {
			$this->name = 'xml';
			$params = substr($importString, strlen('<?xml'), strlen($importString) - (strlen('<?xml') + strlen('?>')));
			$this->params = $this->parseParameters($params);
			$this->type = self::TYPE_XML_DECLARATION;
		} elseif(preg_match('/^<(\/|)([^\s\/]+)(\s(.*?)|)(\/|)>$/s', $importString, $m)) {
			$this->name = $m[2];
			if(strlen($m[4]))
				$this->params = $this->parseParameters($m[3]);

			if($m[1] === '/')
				$this->type = self::TYPE_CLOSE;
			elseif($m[5] === '/')
				$this->type = self::TYPE_EMPTY;
			else
				$this->type = self::TYPE_OPEN;
		} elseif($importString{0} === '<') {
			throw new Exception("Parse error near `$importString`");
		} else {
			$this->name = '#text';
			$this->text = $importString;
			$this->type = self::TYPE_TEXT;

		}

		if(strlen($this->text))
			$this->text = htmlspecialchars_decode($this->text);
	}

	static function parseParameters($parametersString) {
		$parametersString = trim($parametersString);

		$params = array();
		$isParamName = true;
		$isSpace = false;
		$paramName = '';
		$paramValue = '';
		$isParamValue = true;
		$paramValueQuoteType = null;

		for($i=0; $i<strlen($parametersString); $i++) {
			$chr = $parametersString{$i};

			if($isSpace) {
				if(ctype_space($chr))
					continue;
				$isParamName = true;
				$isSpace = false;
			}

			if($isParamName) {
				if($chr === '=' || ctype_space($chr)) {
					if(!strlen($paramName))
						throw new Exception("Empty parameter name ($parametersString)");

					$params[$paramName] = true;
					if($chr === '=') {
						$isParamName = false;
						$isParamValue = true;
					} else {
						$isParamName = false;
						$paramName = '';
						$isSpace = true;
					}
				} elseif($chr === '\'' || $chr === '"') {
					throw new Exception("Can't parse parameter name `$paramName` ($parametersString)");
				} else {
					$paramName .= $chr;
				}
				continue;
			} elseif($isParamValue) {
				if($paramValueQuoteType === null) {
					if($chr == "'" || $chr == '"')
						$paramValueQuoteType = $chr;
					else
						throw new Exception("Unquoted parameter ($parametersString)");

				} elseif($chr === $paramValueQuoteType) {
					$isParamValue = false;
					$isSpace = true;

					$params[$paramName] = $paramValue;

					$paramName = '';
					$paramValue = '';
					$paramValueQuoteType = null;
				} else {
					$paramValue .= $chr;
				}
			}
		}

		if($isParamName || $isParamValue) {
			throw new Exception("Unexpected end of parameters string ($parametersString)");
		}

		return $params;
	}

	/*static */protected function parseParametersOld($parametersString) {
		$params = array();
		$origParametersString = $parametersString;
		$parametersString = trim($parametersString);
		if(strlen($parametersString)) {
			$pa = preg_split('/\s+/', $parametersString);
			foreach($pa as $paramString) {
				$parameter = null;
				$value = null;
				if(preg_match('/^([^=]+)=(.+)$/', $paramString, $m)) {
					$parameter = $m[1];
					$valString = $m[2];
					if($valString{0} === '\'' || $valString{0} === '"') {
						$lastChr = $valString{strlen($valString)-1};
						if($lastChr !== $valString{0})
							throw new Exception("Can't parse parameter value `$valString` ($origParametersString)");
						$value = substr($valString, 1, strlen($valString)-2);
					} else {
						$value = $valString;
					}
				} else {
					throw new Exception("Can't parse parameter string `$paramString`");
				}
				$params[$parameter] = $value;
			}
		}
		return $params;
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
