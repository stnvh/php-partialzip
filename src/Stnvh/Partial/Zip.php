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
	 * cURL EOCD callback
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

		# Get end of central directory
		$start = 0;
		if($this->info->length > (0xffff + 0x1f)) {
			$start = $this->info->length - 0xffff - 0x1f;
		}
		$request = $this->httpRequest(array(
			CURLOPT_URL => $this->info->url,
			CURLOPT_HTTPGET => true,
			CURLOPT_HEADER => false,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_RANGE => sprintf('%d-%d', $start, $this->info->length - 1),
			CURLOPT_WRITEFUNCTION => array($this, 'receiveCentralDirectoryEnd'),
		));

		# Reverse central directory and search for byte sequences:
		# 06 05 4B 50 = end of central directory (reversed)
		# 02 01 4B 50 = file entry (reversed)
		$bytes = array();
		$centralDirectory = strrev($this->info->centralDirectoryEnd);
		$_fileCount = 0;
		for($i = 0; $i < strlen($centralDirectory); $i++) {
 			$bytes[] = sprintf('%02X', ord($centralDirectory[$i]));
 			# Find end of central directory
			if(substr(implode('', $bytes), -8) == '06054B50') {
				$_centralDirectory = strrev(substr($centralDirectory, 0, $i + 1));

				$cdEnd = new Data\EOCD();
				$cdEnd->format($_centralDirectory);

				$this->info->centralDirectoryDesc = $cdEnd;
			}
			# Find last local file header entry
			if(substr(implode('', $bytes), -8) == '02014B50') {
				$_fileCount++;
				# if total entries found
				if($_fileCount == $this->info->centralDirectoryDesc->CDEntries) {
					$this->info->centralDirectory = strrev(substr($centralDirectory, 0, $i + 1));
					break;
				}
			}
		}
		$bytes = array_reverse($bytes);

		# split each file entry by byte string & remove empty
		$entries = explode("\x50\x4b\x01\x02", $this->info->centralDirectory);
		array_shift($entries);

		$this->info->centralDirectory = array();

		foreach($entries as $raw) {
			$entry = new Data\CDFile();

			$entry->format($raw);

			if(substr($entry->name, -1) == '/')  {
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
	 * Searches for file in the central directory
	 * @param $fileName The filename to search for (case sensitive)
	 * @return CDFile|false
	 */
	public function find($fileName = false) {
		if($fileName) {
			$this->info->file = $fileName;
		}
		if($candidate = $this->info->centralDirectory[$this->info->file]) {
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

		$local = new Data\LocalFile();
		$local->format($this->info->localHeader);

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
			header(sprintf('Content-Disposition: attachment; filename="%s"', $file->name));
			header(sprintf('Content-Length: %d', $file->size));
			header('Pragma: public');
			header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
			echo gzinflate($file->get());
			return true;
		}

		return gzinflate($file->get());
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
