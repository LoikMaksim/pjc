<?php
/*
	$Id$
*/
class PJC_Log {
	const NONE = 0;
	const ERROR = 1;
	const WARNING = 2;
	const NOTICE = 4;
	const DEBUG = 8;
	const ALL = 255;

	protected $history = array();
	protected $historyMaxSize = 1;
	protected $verbosity = self::ALL;

	function warning($str, $data = null) {
		if($this->verbosity & self::WARNING)
			$this->write('WARNING', $str, $data);
	}
	function error($str, $data = null) {
		if($this->verbosity & self::ERROR)
			$this->write('ERROR', $str, $data);
	}
	function notice($str, $data = null) {
		if($this->verbosity & self::NOTICE)
			$this->write('NOTICE', $str, $data);
	}
	function debug($str, $data = null) {
		if($this->verbosity & self::DEBUG)
			$this->write('DEBUG', $str, $data);
	}

	function write($type, $str, $data = null) {
		$microtime = microtime(true);
		$microtime -= (int)$microtime;
		$ms = (int)($microtime*1000);

		$prefix = sprintf('%s.%03u [%s]', date('d.m.Y H:i:s'), $ms, $type);
		$lines = array($prefix.' '.$str);

		if($data !== null) {
			$lines[0] .= ':';
			$dataLines = explode("\n", trim($data));
			foreach($dataLines as &$l)
				$l = "$prefix ...\t$l";
			$lines = array_merge($lines, $dataLines);
			unset($l);
		}

		foreach($lines as $l)
			$this->historyPushLine($l);

		fputs(STDERR, implode("\n", $lines)."\n");
	}

	function historyPushLine($line) {
		if(sizeof($this->history) >= $this->historyMaxSize)
			array_shift($this->history);

		$this->history[] = $line;
	}

	function getHistory() {
		return implode("\n", $this->history);
	}

	function verbosity($verbosity = null) {
		$oldVerbosity = $this->verbosity;
		if($verbosity !== null)
			$this->verbosity = $verbosity;
		return $oldVerbosity;
	}
}
