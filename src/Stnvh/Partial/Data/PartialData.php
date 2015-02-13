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
	 * @param $raw Raw file data for parsing
	 * @param $map The byte map to use
	 * @return void
	 */
	public function __construct($raw = false, $map = false) {
		$this->tempName = sys_get_temp_dir() . uniqid('CDFile~');
		if($raw) {
			$this->format($raw, $map);
		}
	}

	/**
	 * @param $name
	 * @param $args
	 * @return string|null
	 */
	public function __call($name, $args) {
		if($this->$name && isset($this->map[$name])) {
			return $this->$name;
		}
	}

	/**
	 * Get file contents from temp file
	 * @return string
	 */
	public function get() {
		if(!file_exists($this->tempName)) {
			user_error('Temporary filename not set, did you call get() directly on the file object?', E_USER_ERROR);
			exit;
		}

		switch($this->method) {
			case 8:
				$_method = 'gzinflate';
			case 12:
				if(!extension_loaded('bz2')){
					@dl((strtolower(substr(PHP_OS, 0, 3)) == 'win') ? 'php_bz2.dll' : 'bz2.so');
				}

				if(extension_loaded('bz2')) {
					$_method = 'bzdecompress';
				} else {
					user_error('Unable to decompress, failed to load bz2 extension', E_USER_ERROR);
					exit;
				}
			default:
				$_method = false;
		}

		if($_method) {
			return call_user_func_array($_method, array(file_get_contents($this->tempName) . $this->purge()));
		} else {
			return file_get_contents($this->tempName) . $this->purge();
		}
	}

	/**
	 * Removes the cached item from the disk
	 * @return void
	 */
	public function purge() {
		if(file_exists($this->tempName)) {
			@unlink($this->tempName);
		}
	}

	/**
	 * Determines if the file entry is a directory
	 * @return bool
	 */
	public function isDir() {
		return (substr($this->name, -1) == '/') ? true : false;
	}

	/**
	 * Map byte values from raw file data
	 * @param $raw Raw file data for parsing
	 * @param $map The byte map to use
	 * @return int
	 */
	public function format($raw, $map = false) {
		$map = $map ?: $this->map;
		if(!$map) {
			user_error('No byte map specified', E_USER_ERROR);
			exit;
		}

		$i = 0;
		foreach($map as $name => $pos) {
			if(is_string($pos[0])) {
				$key = $pos[0];
				$pos[0] = $this->$key;
			}

			$sect = substr($raw, (isset($pos[2]) ? $pos[2] : $i), $pos[0]);
			if($pos[1]) {
				$sect = unpack($pos[1], $sect)[1];
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
				'crc32' => $map['crc32'],
				'compressedSize' => $map['compressedSize'],
				'size' => $map['size']
			);
			$this->format($this->extra, $_map);
			$this->compressedSize = $this->size;
		}

		ob_clean(); # clean output
	}
}
