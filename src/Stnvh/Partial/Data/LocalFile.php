<?php

namespace Stnvh\Partial\Data;

/**
 * Class LocalFile
 * @package Stnvh\Partial\Data
 */
class LocalFile extends ByteParser {
	public $map = array(
		'signature' => array(4, 'V*'),
		'version' => array(2, 'v*'),
		'flags' => array(2, 'v*'),
		'method' => array(2, 'v*'),
		'modTime' => array(2, 'v*'),
		'modDate' => array(2, 'v*'),
		'crc32' => array(4, 'V*'),
		'compressedSize' => array(4,  'V*'),
		'size' => array(4, 'V*'),
		'lenFileName' => array(2, 'v*'),
		'lenExtra' => array(2, 'v*'),
		'name' => array('lenFileName', false),
		'extra' => array('lenExtra', false)
	);
}
