<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2013 S.D.O.C. LTD. All rights reserved.
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Billing abstract processor ilds class
 *
 * @package  Billing
 * @since    1.0
 */
abstract class Billrun_Processor_Base_SeparatorFieldLines extends Billrun_Processor {

	/**
	 * the type of the object
	 *
	 * @var string
	 */
	static protected $type = 'separator_field_lines';
	
	/**
	 * method to parse the data
	 */
	protected function parse() {
		if (!is_resource($this->fileHandler)) {
			Billrun_Factory::log()->log("Resource is not configured well", Zend_Log::ERR);
			return false;
		}
	
		while ($line = $this->fgetsIncrementLine($this->fileHandler)) {
			$record_type = $this->getLineType($line);
	
			// @todo: convert each case code snippet to protected method (including triggers)
			switch ($record_type) {
				case 'H': // header
					if (isset($this->data['header'])) {
						Billrun_Factory::log()->log("double header", Zend_Log::ERR);
						return false;
					}
					$this->data['header'] = $this->buildHeader($line);

					break;
				case 'T': //trailer
					if (isset($this->data['trailer'])) {
						Billrun_Factory::log()->log("double trailer", Zend_Log::ERR);
						return false;
					}
				
					$this->data['trailer'] = $this->buildTrailer($line);

					break;
				case 'D': //data
					if (!isset($this->data['header'])) {
						Billrun_Factory::log()->log("No header found", Zend_Log::ERR);
						return false;
					}

					$row = $this->buildData($line);
					if($this->isValidDataRecord($row)) {
						$this->data['data'][] = $row;
					}

					break;
				default:
					//raise warning
					break;
			}
		}
		return true;
	}

	protected function buildHeader($line) {
		$this->parser->setStructure($this->header_structure);
		$this->parser->setLine($line);
		// @todo: trigger after header load (including $header)
		$header = $this->parser->parse();

		// @todo: trigger after header parse (including $header)
		$header['source'] = self::$type;
		$header['type'] = static::$type;
		$header['file'] = basename($this->filePath);
		$header['process_time'] = date(self::base_dateformat);
		return $header;
	}
	
	protected function buildTrailer($line) {
			$this->parser->setStructure($this->trailer_structure);
			$this->parser->setLine($line);
			// @todo: trigger after trailer load (including $header, $data, $trailer)
			$trailer = $this->parser->parse();

			// @todo: trigger after trailer parse (including $header, $data, $trailer)
			$trailer['source'] = self::$type;
			$trailer['type'] = static::$type;
			$trailer['header_stamp'] = $this->data['header']['stamp'];
			$trailer['file'] = basename($this->filePath);
			$trailer['process_time'] = date(self::base_dateformat);
			return $trailer;
	}
	
	protected function buildData($line, $line_number = null) {
			$this->parser->setStructure($this->data_structure); // for the next iteration
			$this->parser->setLine($line);
			// @todo: trigger after row load (including $header, $row)
			$row = $this->parser->parse();
			// @todo: trigger after row parse (including $header, $row)
			$row['source'] = self::$type;
			$row['type'] = static::$type;
			$row['log_stamp'] = $this->getFileStamp();
			$row['file'] = basename($this->filePath);
			$row['process_time'] = date(self::base_dateformat);
			if ($this->line_numbers) {
				$row['line_number'] = $this->current_line;
			}
			return $row;
	}

	/**
	 * Check is a given data record is a valid record.
	 * @param $dataLine a structure containing the data record as it will be saved to the DB.
	 * @return true (by default) if the line is valid or false if theres some problem.
	 */
	abstract protected function isValidDataRecord($dataLine);
}
