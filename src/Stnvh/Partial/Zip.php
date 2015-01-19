<?php

namespace Stnvh\Partial;

use Stnvh\Partial\Data;

/**
 * Class Zip
 * @package Stnvh\Partial
 */
class Zip {
	protected $info;

	/**
	 * cURL EOCD callback method
	 * @param $ch
	 * @param $data
	 * @return int
	 */
	private function receiveCentralDirectoryEnd($ch, $data) {
		$this->info->centralDirectoryEnd .= $data;
		return strlen($data);
	}

	/**
	 * cURL central directory callback method
	 * @param $ch
	 * @param $data
	 * @return int
	 */
	private function receiveCentralDirectory($ch, $data) {
		$this->info->centralDirectory .= $data;
		return strlen($data);
	}

	/**
	 * cURL local file header callback method
	 * @param $ch
	 * @param $data
	 * @return int
	 */
	private function receiveLocalHeader($ch, $data) {
		$this->info->localHeader .= $data;
		return strlen($data);
	}

	/**
	 * cURL file data callback method
	 * @param $ch
	 * @param $data
	 * @return int
	 */
	private function receiveData($ch, $data) {
		$f = fopen($this->tempName, 'a');
		fputs($f, $data);
		fclose($f);
		return strlen($data);
	}

	/**
	 * @param $url URL of ZIP file
	 * @param $file File name within the ZIP
	 * @param $output Output to browser when set to true
	 * @return void
	 */
	public function __construct($url, $file = false) {
		set_time_limit(15);
		ob_start();

		$this->info = new Data\ZipInfo();
		$this->info->url = $url;
		$this->info->file = $file;
		$this->init();
	}

	/**
	 * Builds the central directory to get file offsets within zip
	 * @return void
	 */
	public function init() {
		# Get file size
		$request = $this->httpRequest(array(
			CURLOPT_URL => $this->info->url,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_NOBODY => true
		));
		$this->info->length = intval($request['download_content_length']);

		# Fetch end of central directory
		$start = 0;
		if($this->info->length > (0xffff + 0x1f)) {
			$start = $this->info->length - 0xffff - 0x1f;
		}
		$_first = $start;
		$request = $this->httpRequest(array(
			CURLOPT_URL => $this->info->url,
			CURLOPT_HTTPGET => true,
			CURLOPT_HEADER => false,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_RANGE => sprintf('%d-%d', $start, $this->info->length - 1),
			CURLOPT_WRITEFUNCTION => array($this, 'receiveCentralDirectoryEnd'),
		));

		# Reverse end of central directory and search for byte sequence:
		# 06 05 4B 50 = end of central directory (reversed)
		$_bytes = array();
		$_cdEnd = strrev($this->info->centralDirectoryEnd);
		for($i = 0; $i < strlen($_cdEnd); $i++) {
 			$_bytes[] = sprintf('%02X', ord($_cdEnd[$i]));

 			# Find end of central directory
			if(substr(implode('', $_bytes), -8) == '06054B50') {
				$this->info->centralDirectoryDesc = new Data\EOCD(strrev(substr($_cdEnd, 0, $i + 1)));
				break;
			}
		}

		if($cdEnd = $this->info->centralDirectoryDesc) {
			$start = $cdEnd->CDOffset;
			$end = $cdEnd->CDSize - 1;

			if($start - $_first < 0) {
				# Fetch central directory
				$end += $start;
				$request = $this->httpRequest(array(
					CURLOPT_URL => $this->info->url,
					CURLOPT_HTTPGET => true,
					CURLOPT_HEADER => false,
					CURLOPT_FOLLOWLOCATION => true,
					CURLOPT_RANGE => sprintf('%d-%d', $start, $end),
					CURLOPT_WRITEFUNCTION => array($this, 'receiveCentralDirectory'),
				));
			} else {
				# We already have the byte range for the CD
				$this->info->centralDirectory = substr($this->info->centralDirectoryEnd, $start - $_first, $end);
			}
		} else {
			user_error('End of central directory not found', E_USER_ERROR);
			die;
		}

		# split each file entry by byte string & remove empty
		$entries = explode("\x50\x4b\x01\x02", $this->info->centralDirectory);
		array_shift($entries);

		$this->info->centralDirectory = array();

		foreach($entries as $raw) {
			$entry = new Data\CDFile($raw);

			if($entry->isDir()) {
				continue;
			}
 
			$this->info->centralDirectory[$entry->name] = $entry;
		}
	}

	/**
	 * Lists files in the central directory
	 * @return array
	 */
	public function index() {
		$files = array();
		foreach($this->info->centralDirectory as $file) {
			$files[] = $file->name;
		}
		return $files;
	}

	/**
	 * Searches for a file in the central directory
	 * @param $fileName The filename to search for (case sensitive)
	 * @return CDFile|false
	 */
	public function find($fileName = false) {
		if($candidate = $this->info->centralDirectory[$fileName]) {
			$this->info->file = $fileName;
			return $candidate;
		}
		return false;
	}

	/**
	 * Returns the specified file from within the zip
	 * @param $file The CDFile object to download
	 * @param $output Output the file to the browser instead of returning
	 * @return true|string
	 */
	public function get(Data\CDFile $file, $output = false) {
		$this->tempName = $file->tempName;

		# Get local file header
		$start = $file->offset;
		$end = $start + $file->compressedSize - 1;
		$request = $this->httpRequest(array(
			CURLOPT_URL => $this->info->url,
			CURLOPT_HTTPGET => true,
			CURLOPT_HEADER => false,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_RANGE => sprintf('%d-%d', $start, $end),
			CURLOPT_WRITEFUNCTION => array($this, 'receiveLocalHeader')
		));

		$local = new Data\LocalFile($this->info->localHeader);

		# Get compressed file data
		$start = $file->offset + $local->lenHeader;
		$end = $start + $file->compressedSize - 1;
		$request = $this->httpRequest(array(
			CURLOPT_URL => $this->info->url,
			CURLOPT_HTTPGET => true,
			CURLOPT_HEADER => false,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_RANGE => sprintf('%d-%d', $start, $end),
			CURLOPT_WRITEFUNCTION => array($this, 'receiveData')
		));

		if($output) {
			header(sprintf('Content-Disposition: attachment; filename="%s"', $file->filename));
			header(sprintf('Content-Length: %d', $file->size));
			header('Pragma: public');
			header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
			echo $file->get();
			return true;
		}

		return $file->get();
	}

	/**
	 * cURL wrapper
	 * @param $conf cURL config array
	 * @return array
	 */
	protected function httpRequest($conf) {
		$ch = curl_init();

		curl_setopt_array($ch, $conf);

		$out = curl_exec($ch);

		$info = curl_getinfo($ch);
		$info['response'] = $out;

		curl_close($ch);

		return $info;
	}
}
