<?php
/*
	$Id$
*/

require_once('XMLTokenStreamException.class.php');
require_once('Stream.class.php');
class XMLTokenStream extends Stream {
	protected $extraTokenStack = array();
	public function readToken() {
		if(sizeof($this->extraTokenStack))
			return array_pop($this->extraTokenStack);

		$token = null;

		if($this->compare('<![CDATA[')) { // CDATA
			$token = $this->readOverBoundaryInclusive(']]>');
		} elseif($this->compare('<')) { // open/close/empty tag
			$token = $this->readOverBoundaryInclusive('>');
		} else { // text
			$token = $this->readUntilBoundary('<');
			if($token !== null) {
				$token = trim($token);
				if(!strlen($token))
					$token = $this->readToken();
			}

			if($token === null)
				return null;
		}

		if($token === null)
			throw new XMLTokenStreamException('Unexpected end of stream near "'.substr($this->readAll(), -100).'"');

		return $token;
	}

	public function unreadToken($token) {
		array_push($this->extraTokenStack, $token);
	}
}
