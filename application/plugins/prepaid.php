<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2015 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Prepaid plugin
 *
 * @package  Application
 * @subpackage Plugins
 * @since    0.5
 */
class prepaidPlugin extends Billrun_Plugin_BillrunPluginBase {

	/**
	 * plugin name
	 *
	 * @var string
	 */
	protected $name = 'prepaid';
	
	/**
	 * Method to trigger api outside of Billrun.
	 * afterSubscriberBalanceNotFound trigger after the subscriber has no available balance (relevant only for prepaid subscribers)
	 * 
	 * @param array $row the line from lines collection
	 * 
	 * @return boolean true for success, false otherwise
	 * 
	 */
	public function afterSubscriberBalanceNotFound($row) {
		return false; // TODO: temporary, disable send of clear call
		return self::sendClearCallRequest($row);
	}
	
	/**
	 * Send a request of ClearCall
	 * 
	 * @param type $row
	 * @return boolean true for success, false otherwise
	 */
	protected static function sendClearCallRequest($row) {
		$encoder = Billrun_Encoder_Manager::getEncoder(array(
			'usaget' => $row['usaget']
		));
		if (!$encoder) {
			Billrun_Factory::log('Cannot get encoder', Zend_Log::ALERT);
			return false;
		}
		
		$row['record_type'] = 'clear_call';
		$responder = Billrun_ActionManagers_Realtime_Responder_Manager::getResponder($row);
		if (!$responder) {
			Billrun_Factory::log('Cannot get responder', Zend_Log::ALERT);
			return false;
		}

		$request = array($encoder->encode($responder->getResponse(), "request"));
		// Sends request
		$requestUrl = Billrun_Factory::config()->getConfigValue('IN.request.url.realtimeevent');
		return Billrun_Util::sendRequest($requestUrl, $request);
	}
	
	public function afterUpdateSubscriberBalance($row, $balance, &$pricingData, $calculator) {
		try {
			$pp_includes_name = $balance->get('pp_includes_name');
			if (!empty($pp_includes_name)) {
				$pricingData['pp_includes_name'] = $pp_includes_name;
			}
			$pp_includes_external_id = $balance->get('pp_includes_external_id');
			if (!empty($pp_includes_external_id)) {
				$pricingData['pp_includes_external_id'] = $pp_includes_external_id;
			}

			$balance_after = $this->getBalanceValue($balance);
			$balance_usage = $this->getBalanceUsage($balance, $row);
			$pricingData["balance_before"] = $balance_after - $balance_usage;
			$pricingData["balance_after"] = $balance_after;
			$pricingData["balance_usage_unit"] = Billrun_Util::getUsagetUnit($balance->get('charging_by_usaget'));
		} catch (Exception $ex) {
			Billrun_Factory::log('prepaid plugin afterUpdateSubscriberBalance error', Zend_Log::ERR);
			Billrun_Factory::log($ex->getCode() . ': ' . $ex->getMessage(), Zend_Log::ERR);
		}
	}
	
	protected function getBalanceValue($balance) {
		if ($balance->get('charging_by_usaget') == 'total_cost') {
			return $balance->get('balance')['cost'];
		}
		return $balance->get('balance')['totals'][$balance['charging_by_usaget']][$balance['charging_by']];
	}
	
	protected function getBalanceUsage($balance, $row) {
		if ($balance->get('charging_by_usaget') == 'total_cost' || $balance->get('charging_by_usaget') == 'cost') {
			return $row['aprice'];
		}
		return $row['usagev'];
	}

}
