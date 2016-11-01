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
	protected $initFile;

	/**
	 * @param $url URL of ZIP file
	 * @param $file File name within the ZIP
	 * @param $output Output to browser when set to true
	 * @return void
	 */
	public function __construct($url, $file = false) {
		$this->info = new Data\ZipInfo();
		$this->info->url = $url;
		if($file) $this->initFile = $file;
		$this->init();
	}

	/**
	 * Builds the central directory to get file offsets within zip
	 * @return void
	 */
	public function init() {
		if($this->info->centralDirectory) return true;

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
		$cdEndRangeStart = 0;
		if($this->info->length > (0xffff + 0x1f)) {
			$cdEndRangeStart = $this->info->length - 0xffff - 0x1f;
		}

		$cdEnd = $this->getRange(
			$cdEndRangeStart,
			$this->info->length - 1
		);

		# Get end of central directory and search for byte sequence:
		# 50 4B 05 06 = end of central directory
		if($eocd = strstr($cdEnd, "\x50\x4b\x05\x06")) {
			$cdDesc = new Data\EOCD($eocd);
		} else {
			throw new RuntimeException('End of central directory not found');
		}

		if($cdDesc) {
			$cdRangeStart = $cdDesc->CDOffset;
			$cdRangeEnd = $cdDesc->CDSize - 1;

			if($cdRangeStart - $cdEndRangeStart < 0) {
				# Fetch central directory from web
				$cdRangeEnd += $cdRangeStart;

				$centralDirectoryRaw = $this->getRange(
					$cdRangeStart,
					$cdRangeEnd
				);
			} else {
				# We already have the byte range for the CD
				$centralDirectoryRaw = substr($cdEnd, $cdRangeStart - $cdEndRangeStart, $cdRangeEnd);
			}
		}

		# split each file entry by byte string & remove empty
		$cdEntries = explode("\x50\x4b\x01\x02", $centralDirectoryRaw);

		array_shift($cdEntries);

		foreach($cdEntries as $raw) {
			$entry = new Data\CDFile($raw);

			if($entry->isDir()) {
				continue;
			}

			$this->info->centralDirectory[$entry->name] = $entry;
		}

		unset($cdEntries);

		return true;
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
		$fileName = $fileName ?: $this->initFile;

		if(!$fileName) {
			throw new InvalidArgumentException('No filename specified to search');
		}

		if(isset($this->info->centralDirectory[$fileName])) {
			return new Data\CDFile($this->info->centralDirectory[$fileName]);
		}

		return false;
	}

	/**
	 * Returns the specified file from within the zip
	 * @param $file The CDFile object to download
	 * @return true|string
	 */
	public function get(Data\CDFile $file) {
		if(!$file) {
			throw new InvalidArgumentException('No CDFile object specified');
		}

		$localFileHeaderRaw = $this->getRange(
			$file->offset,
			$file->offset + $file->compressedSize - 1
		);

		if(!$localFileHeaderRaw) throw new RuntimeException('Local file header not fetched');

		$localFileHeader = new Data\LocalFile($localFileHeaderRaw);

		# Get compressed file data
		$this->putTemp($file, $this->getRange(
			$file->offset + $localFileHeader->lenHeader,
			$file->offset + $localFileHeader->lenHeader + $file->compressedSize - 1
		));

		return $file->get();
	}

	/**
	 * cURL wrapper
	 * @param $conf cURL config array
	 * @return array
	 */
	private function httpRequest($conf) {
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

	/**
	 * Writes a temporary file
	 * @param $file
	 * @param $data
	 * @return int
	 */
	private function putTemp(Data\CDFile $file, $data) {
		$f = fopen($file->tempName, 'a');
		fputs($f, $data);
		fclose($f);
		return strlen($data);
	}

	/**
	 * Fetches a byte range from the global file URL
	 * @param $start
	 * @param $end
	 * @return string
	 */
	private function getRange($start, $end) {
		return $this->httpRequest(array(
			CURLOPT_URL => $this->info->url,
			CURLOPT_HTTPGET => true,
			CURLOPT_HEADER => false,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_RANGE => sprintf('%d-%d', $start, $end),
			CURLOPT_RETURNTRANSFER => true
		))['response'];
	}

}
