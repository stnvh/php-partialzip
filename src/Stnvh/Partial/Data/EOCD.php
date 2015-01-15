<?php

namespace Stnvh\Partial\Data;

/**
 * Class EOCD
 * @package Stnvh\Partial\Data
 */
class EOCD extends PartialData {
	public $map = array(
		'signature' => array(4, 'V*'),
		'diskNo' => array(2, 'v*'),
		'CDDiskNo' => array(2, 'v*'),
		'CDDiskEntries' => array(2, 'v*'),
		'CDEntries' => array(2, 'v*'),
		'CDSize' => array(4, 'V*'),
		'CDOffset' => array(4, 'V*'),
		'lenComment' => array(4,  'V*'),
		'comment' => array('lenComment', false)
	);
}
