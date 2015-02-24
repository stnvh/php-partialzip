<?php

namespace Stnvh\Partial;

use Stnvh\Partial\Data;

use \RuntimeException as RuntimeException;
use \InvalidArgumentException as InvalidArgumentException;

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
			CURLOPT_NOBODY => true,
			CURLOPT_HEADER => true,
			CURLOPT_RETURNTRANSFER => true
		));

		if($request['http_code'] > 400) {
			throw new RuntimeException(sprintf('Initial request failed, got HTTP status code: %d', $request['http_code']));
		}

		if(!$request['headers']['Accept-Ranges']) {
			throw new RuntimeException('Server does not support HTTP range requests');
		}

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

		# Get end of central directory and search for byte sequence:
		# 50 4B 05 06 = end of central directory
		if($_EOCD = strstr($this->info->centralDirectoryEnd, "\x50\x4b\x05\x06")) {
			$this->info->centralDirectoryDesc = new Data\EOCD($_EOCD);
		} else {
			throw new RuntimeException('End of central directory not found');
		}

		if($cdEnd = $this->info->centralDirectoryDesc) {
			$start = $cdEnd->CDOffset;
			$end = $cdEnd->CDSize - 1;

			if($start - $_first < 0) {
				# Fetch central directory from web
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
		}

		# split each file entry by byte string & remove empty
		$_entries = explode("\x50\x4b\x01\x02", $this->info->centralDirectory);
		array_shift($_entries);

		$this->info->centralDirectory = array();

		foreach($_entries as $i => $raw) {
			$entry = new Data\CDFile($raw);

			if($entry->isDir()) {
				continue;
			}

			$this->info->centralDirectory[$entry->name] = $raw;
			unset($_entries[$i]); # free mem as we loop
		}
	}

	/**
	 * Lists files in the central directory
	 * @return array
	 */
	public function index() {
		return array_keys($this->info->centralDirectory);
	}

	/**
	 * Searches for a file in the central directory
	 * @param $fileName The filename to search for (case sensitive)
	 * @return CDFile|false
	 */
	public function find($fileName = false) {
		$fileName = $fileName ?: $this->info->file;

		if(!$fileName) {
			throw new InvalidArgumentException('No filename specified to search');
		}

		if (isset($this->info->centralDirectory[$fileName])) {
			$this->info->file = $fileName;
			return new Data\CDFile($this->info->centralDirectory[$fileName]);
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
		if(!$file) {
			throw new InvalidArgumentException('No CDFile object specified');
		}

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

		if($conf[CURLOPT_HEADER] && preg_match_all('/(.*): (.*)\r?\n/', $out, $match)) {
			$_headers = substr($out, 0, $info['header_size']);
			$headers = array();
			foreach($match[1] as $i => $header) {
				$headers[$header] = $match[2][$i];
			}
			$info['headers'] = $headers;
			$out = substr($out, $info['header_size']);
		}

		$info['response'] = $out;

		curl_close($ch);

		return $info;
	}
}
