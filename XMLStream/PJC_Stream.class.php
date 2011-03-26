<?php
require_once('PJC_StreamException.class.php');

declare(ticks = 1);

class PJC_Stream {
	private $streamFd;
	private $streamIsFdOwner = false;
	private $streamEof = false;

	private $selectTimeout = 600;
	private $onSelectCallback = null;

	public $bytesRead = 0;
	public $bytesWritten = 0;

	function __construct($source) {
		if(is_resource($source)) {
			$this->streamFd = $source;
		} elseif(is_string($source)) {
			$this->streamFd = fopen($source, 'r');
			if(!$this->streamFd)
				throw new Exception('Can\'t open file '.$source);
			$this->streamIsFdOwner = true;
		}
	}

	function __destruct() {
		if($this->streamIsFdOwner)
			fclose($this->streamFd);
	}

	function fuckingStreamErrorHandler($errno, $errstr) {
		if(preg_match('/^stream_select\(\).* \[(\d+)\]: (.*?) \(.*/', $errstr, $m))
			throw new PJC_StreamException('stream_select(): '.$m[2], (int)$m[1]);
		elseif(preg_match('/^(.*?)\(\).*errno\=(\d+) (.*?)$/', $errstr, $m))
			throw new PJC_StreamException($m[1].'(): '.$m[3], (int)$m[2]);
		else
			throw new PJC_StreamException($errstr/*, $errno*/);

		return true;
	}

	protected function streamErrorHandlingStart() {
		set_error_handler(array($this, 'fuckingStreamErrorHandler'), E_WARNING|E_NOTICE);
	}

	protected function streamErrorHandlingEnd() {
		restore_error_handler();
	}

	final public function readChunk($maxSize) {
		$toRead = (int)$maxSize;
		if($toRead <= 0)
			throw new PJC_StreamException("\$maxSize must be more than 0, $maxSize given");

		$this->streamErrorHandlingStart();
		$readed = '';
		try {
			while(!$this->streamEof) {
				$this->runOnSelect();
				$timeoutSec = (int)$this->selectTimeout;
				$timeoutUSec = ($this->selectTimeout - $timeoutSec) * 1000000;

				$readFds = array($this->streamFd);
				$writeFds = null;
				$exceptFds = null;

				try {
					$s = stream_select($readFds, $writeFds, $exceptFds, $timeoutSec, $timeoutUSec);

					if($s) {
						$readed = fread($this->streamFd, $toRead);

						if($readed === false || $readed === 0) {
							if(!feof($this->streamFd))
								throw new PJC_StreamException('read() error');
							$this->streamEof = true;
							break;
						} elseif(!strlen($readed)) {
							if(feof($this->streamFd)) {
								$this->streamEof = true;
								break;
							} else { // wtf?!
								throw new PJC_StreamException("Strange situation. Socket is not a closed, but fread() return ''");
							}
						}
					} elseif($s === 0) {
						continue;
					}

					$this->bytesRead += strlen($readed);
					break;
				} catch(PJC_StreamException $e) {
					if($e->getCode() === 4) // EINTR
						continue;
					else
						throw $e;
				}
			}
		} catch(Exception $e) {
			$this->streamErrorHandlingEnd();
			throw $e;
		}

		$this->streamErrorHandlingEnd();
		return $readed;
	}

	protected function runOnSelect() {
		if($this->onSelectCallback)
			call_user_func($this->onSelectCallback);
	}

	public function registerOnSelectCallback($cb) {
		$this->onSelectCallback = $cb;
	}

	public function setSelectTimeout($timeout) {
		$this->selectTimeout = $timeout;
	}

	public function close() {
		if(!$this->streamFd)
			return;

		fclose($this->streamFd);
		$this->streamFd = null;
	}

	public function write($string) {
		$this->streamErrorHandlingStart();
		while(true) {
			try {
				$count = 0;
				for($written = 0; $written<strlen($string); $written+=$count) {
					$count = fwrite($this->streamFd, substr($string, $written));
					if(!$count) { // strange case
						throw new PJC_StreamException(
							'Strange behaviour of fwrite():'.
								' returned '.var_export($count, true).','.
								' feof() is '.var_export(feof($this->streamFd), true)
						);
					}
					$this->bytesWritten += $count;
				}
				break;
			} catch(PJC_StreamException $e) {
				if($e->getCode() == 35) { // EAGAIN
					usleep(1000000*0.1);
					continue;
				}
				$this->streamErrorHandlingEnd();
				throw $e;
			}
		}
		$this->streamErrorHandlingEnd();
	}
}
