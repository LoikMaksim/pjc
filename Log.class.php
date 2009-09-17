<?php
/*
	$Id$
*/
class Log {
	const NONE = 0;
	const ERROR = 1;
	const WARNING = 2;
	const NOTICE = 4;
	const DEBUG = 8;
	const ALL = 255;

	static protected $history = array();
	static protected $historyMaxSize = 1;
	static protected $verbosity = self::ALL;

	static function warning($str, $data = null) {
		if(self::$verbosity & self::WARNING)
			self::write('WARNING', $str, $data);
	}
	static function error($str, $data = null) {
		if(self::$verbosity & self::ERROR)
			self::write('ERROR', $str, $data);
	}
	static function notice($str, $data = null) {
		if(self::$verbosity & self::NOTICE)
			self::write('NOTICE', $str, $data);
	}
	static function debug($str, $data = null) {
		if(self::$verbosity & self::DEBUG)
			self::write('DEBUG', $str, $data);
	}

	static function write($type, $str, $data = null) {
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
			self::historyPushLine($l);

		fputs(STDERR, implode("\n", $lines)."\n");
	}

	static function historyPushLine($line) {
		if(sizeof(self::$history) >= self::$historyMaxSize)
			array_shift(self::$history);

		self::$history[] = $line;
	}

	static function getHistory() {
		return implode("\n", self::$history);
	}

	static function verbosity($verbosity = null) {
		$oldVerbosity = self::$verbosity;
		if($verbosity !== null)
			self::$verbosity = $verbosity;
		return $oldVerbosity;
	}
}
