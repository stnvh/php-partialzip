<?php

namespace Stnvh\Partial\Data;

/**
 * Class CDFile
 * @package Stnvh\Partial\Data
 */
class CDFile extends PartialData {
	public $map = array(
		'version' => array(2, 'v*'),
		'versionExtract' => array(2, 'v*'),
		'flags' => array(2, 'v*'),
		'method' => array(2, 'v*'),
		'modTime' => array(2, 'v*'),
		'modDate' => array(2, 'v*'),
		'crc32' => array(4, 'V*'),
		'compressedSize' => array(4,  'V*'),
		'size' => array(4, 'V*'),
		'lenFileName' => array(2, 'v*'),
		'lenExtra' => array(2, 'v*'),
		'lenComment' => array(2, 'v*'),
		'diskStart' => array(2, 'v*'),
		'internalAttr' => array(2, 'v*'),
		'externalAttr' => array(4, 'v*'),
		'offset' => array(4, 'V*'),
		'name' => array('lenFileName', false),
		'extra' => array('lenExtra', false),
		'comment' => array('lenComment', false),
	);

	public function format($raw, $map = false) {
		parent::format($raw, $map);
		$this->filename = basename($this->name);
	}
}
