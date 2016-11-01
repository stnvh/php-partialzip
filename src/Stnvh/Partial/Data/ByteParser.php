<?php

namespace Stnvh\Partial\Data;

use \RuntimeException as RuntimeException;
use \InvalidArgumentException as InvalidArgumentException;
use \BadMethodCallException as BadMethodCallException;

/**
 * Class ByteParser
 * @package Stnvh\Partial\Data
 */
class ByteParser {
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
		$this->tempName = tempnam(sys_get_temp_dir(), 'CDFile~');
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
			throw new BadMethodCallException('Called before being fetched with Zip->get(). Don\'t call this directly!');
		}

		switch($this->method) {
			case 8:
				$_method = 'gzinflate';
				break;
			case 12:
				if(!extension_loaded('bz2')){
					@dl((strtolower(substr(PHP_OS, 0, 3)) == 'win') ? 'php_bz2.dll' : 'bz2.so');
				}

				if(extension_loaded('bz2')) {
					$_method = 'bzdecompress';
					break;
				} else {
					throw new RuntimeException('Unable to decompress, failed to load bz2 extension');
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
	private function purge() {
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
			throw new InvalidArgumentException('No byte map specified');
		}

		$i = 0;
		foreach($map as $name => $pos) {
			if(is_string($pos[0])) {
				$key = $pos[0];
				$pos[0] = $this->$key;
			}

			$sect = substr($raw, (isset($pos[2]) ? $pos[2] : $i), $pos[0]);
			if($pos[1]) {
				$sect = unpack($pos[1], $sect);
				$sect = isset($sect[1]) ? $sect[1] : null;
			}
			$this->$name = $sect;

			$i += $pos[0];
		}

		if(!isset($this->lenHeader)) {
			$this->lenHeader = $i;
		}

		# If size not populated, fetch from 'extra field'
		if(isset($this->method) && $this->method == 0x0008 && $this->extra && !$this->size) {
			$_map = array(
				'crc32' => $map['crc32'],
				'compressedSize' => $map['compressedSize'],
				'size' => $map['size']
			);
			$this->format($this->extra, $_map);
			$this->compressedSize = $this->size;
		}
	}
}
