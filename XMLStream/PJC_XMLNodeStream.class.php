<?php
require_once('PJC_Stream.class.php');
require_once('PJC_XMLNodeStreamException.class.php');

class PJC_XMLNodeStream extends PJC_Stream {
	private $xmlParser = null;
	private $parsedNodes = array();

	public function __construct($input) {
		parent::__construct($input);
		$this->resetParser();
	}

	final public function resetParser() {
		$this->xmlParser = xml_parser_create('UTF-8');
		xml_set_object($this->xmlParser, $this);
		xml_set_element_handler($this->xmlParser, '___xmlParser_startElementHandler', '___xmlParser_endElementHandler');
		xml_set_character_data_handler($this->xmlParser, '___xmlParser_characterDataHandler');
		xml_parser_set_option($this->xmlParser, XML_OPTION_CASE_FOLDING, 0);
	}

	final public function readNode($expectedNode = null) {
		while(!sizeof($this->parsedNodes)) {
			$chunk = $this->readChunk(1024);
			if(is_null($chunk))
				return null;

			xml_parse($this->xmlParser, $chunk);
		}
		$node = array_shift($this->parsedNodes);

		if($expectedNode !== null && $node->getName() !== $expectedNode)
			throw new PJC_XMLNodeStreamException("Unexpected node `{$node->getName()}`. `$expectedNode` expected");

		return $node;
	}

	final public function unreadNode(PJC_XMLStreamNode $node) {
		array_unshift($this->parsedNodes, $node);
	}

	public function ___xmlParser_startElementHandler($xmlParser, $name, array $attributes) {
		$node = new PJC_XMLStreamNode;
		$node->setType(PJC_XMLStreamNode::TYPE_OPEN);
		$node->setName($name);
		$node->setAttributes($attributes);
		$this->parsedNodes[] = $node;
	}

	public function ___xmlParser_endElementHandler($xmlParser, $name) {
		$node = new PJC_XMLStreamNode;
		$node->setType(PJC_XMLStreamNode::TYPE_CLOSE);
		$node->setName($name);
		$this->parsedNodes[] = $node;
	}

	public function ___xmlParser_characterDataHandler($xmlParser, $text) {
		if(!strlen(trim($text)))
			return;
		$node = new PJC_XMLStreamNode;
		$node->setType(PJC_XMLStreamNode::TYPE_TEXT);
		$node->setText($text);
		$this->parsedNodes[] = $node;
	}
}
