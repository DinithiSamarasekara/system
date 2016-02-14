<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * This is a prototype for a Realtime response action.
 *
 */
abstract class Billrun_ActionManagers_Realtime_Responder_Base {

	/**
	 * Db line to create the response from
	 * 
	 * @var type array
	 */
	protected $row;
	
	/**
	 * The name of the api response
	 * 
	 * @var string 
	 */
	protected $responseApiName = 'basic';

	/**
	 * Create an instance of the RealtimeAction type.
	 */
	public function __construct(array $options = array()) {
		$this->row = $options['row'];
		$this->responseApiName = $this->getResponsApiName();
	}
	
	/**
	 * Sets API name
	 */
	public abstract function getResponsApiName();

	/**
	 * Checks if the responder is valid
	 * 
	 * @return boolean
	 */
	public function isValid() {
		return (!is_null($this->row));
	}

	/**
	 * Get response message
	 */
	public function getResponse() {
		$responseData = $this->getResponseData();
		return $responseData;
	}
	
	/**
	 * Gets the fields to show on response
	 * 
	 * @return type
	 */
	protected function getResponseFields() {
		return Billrun_Factory::config()->getConfigValue('realtimeevent.responseData.basic', array());
	}

	/**
	 * Gets response message data
	 * 
	 * @return array
	 */
	protected function getResponseData() {
		$ret = array();
		$responseFields = $this->getResponseFields();
		foreach ($responseFields as $responseField => $rowField) {
			if (is_array($rowField)) {
				$ret[$responseField] = (isset($rowField['classMethod']) ? $this->{$rowField['classMethod']}() : '');
			} else {
				$ret[$responseField] = (isset($this->row[$rowField]) ? $this->row[$rowField] : '');
			}
		}
		return $ret;
	}

	/**
	 * Gets response status.
	 * @return int 1 - success, o -failure
	 */
	protected function getStatus() {
		if (isset($this->row['granted_return_code'])) {
			$returnCodes = Billrun_Factory::config()->getConfigValue('prepaid.customer', array());
			switch($this->row['granted_return_code']) {
				case ($returnCodes['no_rate']):
				case ($returnCodes['no_subscriber']):
					return 0;
			} 
		}

		return 1;
	}
	
	/**
	 * Gets response description
	 * @return string Description
	 */
	protected function getDesc() {
		if (isset($this->row['granted_return_code'])) {
			$returnCodes = Billrun_Factory::config()->getConfigValue('prepaid.customer', array());
			switch($this->row['granted_return_code']) {
				case ($returnCodes['no_available_balances']):
					return "No balance";
				case ($returnCodes['no_rate']):
					return "Error: No rate";
				case ($returnCodes['no_subscriber']):
					return "Error: No subscriber";
			} 
		}

		return "Success";
	}
	
	/**
	 * Gets error code number (if an error occured)
	 * @return int ErrorCode
	 */
	protected function getErrorCode() {
		if (isset($this->row['granted_return_code'])) {
			$returnCodes = Billrun_Factory::config()->getConfigValue('prepaid.customer', array());
			switch($this->row['granted_return_code']) {
				case ($returnCodes['no_available_balances']):
					return Billrun_Factory::config()->getConfigValue("realtime_error_base") + 2;
				case ($returnCodes['no_rate']):
					return Billrun_Factory::config()->getConfigValue("realtime_error_base") + 1;
				case ($returnCodes['no_subscriber']):
					return Billrun_Factory::config()->getConfigValue("realtime_error_base") + 3;
			} 
		}

		return NULL;
	}
}
