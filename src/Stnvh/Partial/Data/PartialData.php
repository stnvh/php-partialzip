<?php

namespace Stnvh\Partial\Data;

/**
 * Class PartialData
 * @package Stnvh\Partial\Data
 */
class PartialData {
	public $tempName;
	public $map = array(/*
		'varName' => array([no of bytes to copy], [unpack() format])
	*/);

	/**
	 * Get header size in byte count
	 * @return int
	 */
	public static function size() {
		$size = 0;
		foreach($this->map as $map) {
			if(is_string($map[0])) {
				continue;
			}
			$size += $map[0];
		}
		return $size;
	}

	/**
	 * @return void
	 */
	public function __construct() {
		$this->tempName = tempnam(sys_get_temp_dir(), 'CDFile');
	}

	/**
	 * Get file contents from temp file
	 * @return string
	 */
	public function get() {
		return file_get_contents($this->tempName);
	}

	/**
	 * Map byte values from raw compressed data
	 * @return int
	 */
	public function format($raw, $map = false) {
		if(!$map) {
			$map = $this->map;
		}

		$i = 0;
		foreach($map as $name => $pos) {
			if(is_string($pos[0])) {
				$key = $pos[0];
				$pos[0] = $this->$key;
			}

			$sect = substr($raw, (isset($pos[2]) ? $pos[2] : $i), $pos[0]);
			if($pos[1]) {
				@$sect = unpack($pos[1], $sect)[1];
			}
			$this->$name = $sect;

			$i += $pos[0];
		}

		if(!$this->lenHeader) {
			$this->lenHeader = $i;
		}

		# If size not populated, fetch from 'extra field'
		if($this->method == 0x0008 && $this->extra && !$this->size) {
			$_map = array(
				'crc32' => $this->map['crc32'],
				'compressedSize' => $this->map['compressedSize'],
				'size' => $this->map['size']
			);
			$this->format($this->extra, $_map);
			$this->compressedSize = $this->size;
		}

		ob_clean(); # clean output
	}
}
