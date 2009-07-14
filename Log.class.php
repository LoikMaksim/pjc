<?php
/*
	$Id$
*/
class Log {
	static protected $history = array();
	static protected $historyMaxSize = 1;

	static function warning($str, $data = null) {
		self::write('WARNING', $str, $data);
	}
	static function error($str, $data = null) {
		self::write('ERROR', $str, $data);
	}
	static function notice($str, $data = null) {
		self::write('NOTICE', $str, $data);
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
}
