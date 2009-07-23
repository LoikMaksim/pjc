<?php
/*
	$Id$
*/

require_once('StreamException.class.php');
declare(ticks = 1);
class Stream {
	private $streamFd;
	private $streamIsFdOwner = false;
	private $streamBuffer = '';
	private $streamBufferPos = 0;
	private $streamBufferMaxSize = 65536;
	private $streamEof = false;
// 	protected $streamLastErrno = false;
// 	protected $streamLastErrstr = false;

	function __construct($source) {
		if(is_resource($source)) {
			$this->streamFd = $source;
		} elseif(is_string($source)) {
			$this->streamFd = fopen($source, 'r');
			if(!$this->streamFd)
				throw new Exception('Can\'t open file '.$source);
			$this->streamIsFdOwner = true;
		}
// 		stream_set_blocking($this->streamFd, 0);
	}

	function __destruct() {
		if($this->streamIsFdOwner)
			fclose($streamFd);
	}

	function fuckingStreamErrorHandler($errno, $errstr) {
// 		echo $errstr, "\n";
		if(preg_match('/^stream_select\(\).* \[(\d+)\]: (.*?) \(.*/', $errstr, $m))
			throw new StreamException('stream_select(): '.$m[2], (int)$m[1]);
		elseif(preg_match('/^(.*?)\(\).*errno\=(\d+) (.*?)$/', $errstr, $m))
			throw new StreamException($m[1].'(): '.$m[3], (int)$m[2]);
		else
			throw new StreamException($errstr);

		return true;
	}

	protected function streamErrorHandlingStart() {
		set_error_handler(array($this, 'fuckingStreamErrorHandler'), E_WARNING|E_NOTICE);
	}

	protected function streamErrorHandlingEnd() {
		restore_error_handler();
	}

	final private function readChunkInBuffer() {
		/* for valid signal handling */
		$toRead = $this->streamBufferMaxSize - strlen($this->streamBuffer);
		if($toRead > 0) {
			$this->streamErrorHandlingStart();
			$readed = '';
			while(true) {
				$readFds = array($this->streamFd);
				$writeFds = null;
				$exceptFds = null;

				try {
					$s = stream_select($readFds, $writeFds, $exceptFds, 600, 0);

					if($s) {
						$readed = fread($this->streamFd, $toRead);

						if($readed === false || $readed === 0) {
							if(!feof($this->streamFd))
								throw new StreamException('read() error');
							$this->streamEof = true;
							break;
						} elseif(!strlen($readed)) {
							if(feof($this->streamFd)) {
								$this->streamEof = true;
								break;
							} else { // wtf?!
								continue;
							}
						}
					} elseif($s === 0) {
						continue;
					}

					$this->streamBuffer .= $readed;
					break;
				} catch(StreamException $e) {
					if($e->getCode() === 4) // EINTR
						continue;
					else
						throw $e;
				}
			}
			$this->streamErrorHandlingEnd();
			return strlen($readed) ? strlen($readed) : null;
		}
		return 0;
	}

	final public function unread($data) {
		$this->streamBuffer = $data.substr($this->streamBuffer, $this->streamBufferPos);
		$this->streamBufferPos = 0;
	}

	final public function readByte() {
		if($this->streamBufferPos >= strlen($this->streamBuffer)) {
			$this->streamBuffer = '';
			if(!$this->readChunkInBuffer())
				return null;
			$this->streamBufferPos = 0;
		}
		return $this->streamBuffer{$this->streamBufferPos++};
	}

	/*private */function getCurrentStreamBuffer() {
		return substr($this->streamBuffer, $this->streamBufferPos);
	}

	/* Extended features */
	public function read($numOfBytes) {
		$str = '';
		for($i=0; $i<$numOfBytes; $i++) {
			if(($chr = $this->readByte()) === null)
				break;
			$str .= $chr;
		}
		return strlen($str) ? $str : null;
	}

	public function readUntil($end, $keepEndInStream = true) {
		$str = '';
		$endReached = false;
		while(($chr = $this->readByte()) !== null) {
			if(strpos($str.$chr, $end) !== false) {
				$endReached = true;
				break;
			}
			$str .= $chr;
		}

		if(strlen($str)) {
			if($keepEndInStream)
				$this->unread($end);
			$endLen = strlen($end);
			$str = substr($str, 0, strlen($str) - ($endLen - 1));
		}

		if(!strlen($str) && !$endReached)
			return null;

		return $str;
	}

	public function readUntilBoundary($end) {
		$readed = $this->readUntil($end);
		if($this->compare($end))
			return $readed;
		$this->unread($readed);
		return null;
	}

	public function readOver($end) {
		return $this->readUntil($end, false);
	}

	public function readOverBoundary($end) {
		$readed = $this->readUntil($end);
		if($this->compareSkipOnEqual($end))
			return $readed;

		$this->unread($readed);
		return null;
	}

	public function readOverBoundaryInclusive($end) {
		if(($readed = $this->readOverBoundary($end)) === null)
			return null;
		return $readed.$end;
	}

	public function compare($needle) {
		$equal = true;
		$buf = '';
		for($i=0; $i<strlen($needle); $i++) {
			if(($chr = $this->readByte()) === null || $chr !== $needle{$i}) {
				$buf .= $chr;
				$equal = false;
				break;
			}
			$buf .= $chr;
		}
// 		echo "\"$needle\" == \"$buf\"?\n";
// 		if(!$equal)
		$this->unread($buf);

		return $equal;
	}

	public function compareSkipOnEqual($needle) {
		$equal = $this->compare($needle);
		if($equal)
			$this->read(strlen($needle));
		return $equal;
	}

	public function readAll() {
		$str = '';
		$endReached = false;
		while(($chr = $this->readByte()) !== null)
			$str .= $chr;

		return strlen($str) ? $str : null;
	}

	public function write($string) {
		$this->streamErrorHandlingStart();
		try {
			$count = 0;
			for($written = 0; $written<strlen($string); $written+=$count) {
				$count = fwrite($this->streamFd, substr($string, $written));
				if(!$count)// strange case
					throw new StreamException('Strange behaviour of fwrite(): returned '.var_export($count));
			}
		} catch(StreamException $e) {
			$this->streamErrorHandlingEnd();
			throw $e;
		}
		$this->streamErrorHandlingEnd();
	}
}
