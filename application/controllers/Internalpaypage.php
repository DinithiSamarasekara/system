<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

require_once APPLICATION_PATH . '/application/controllers/Externalpaypage.php';

/**
 * Billing paypage controller class
 *
 * @package  Controller
 * @since    5.0
 */

class InternalPaypageController extends ExternalPaypageController {
	use Billrun_Traits_Api_UserPermissions;

	public function init() {
		Billrun_Factory::db();
	}

	public function createAction() {
		$this->allowed();
		$request = $this->getRequest()->getRequest();
		$type = empty($request['aid']) ? 'account' : 'subscriber';
		if (empty($request['aid'])) {
			unset($request['aid']);
		} else {
			$request['aid'] = intval($request['aid']);
		}

		if ($request['action'] !== 'updatePaymentGateway') {
			$create = new Billrun_ActionManagers_Subscribers_Create();
			if (isset($request['services']) && is_array($request['services'])) {
				$request['services'] = json_encode($request['services']);
			}
			$query = array(
				"type" => $type,
				"subscriber" => json_encode($request)
			);
			$jsonObject = new Billrun_AnObj($query);
			if (!$create->parse($jsonObject)) {
				/* TODO: HANDLE ERROR! */
				return false;
			}
			if (!($res = $create->execute())) {
				/* TODO: HANDLE ERROR! */
				return false;
			}
		
			//payment_gateway already exist, redirect to return url
			if (empty($request['payment_gateway'])) {
				header("Location: " . $request['return_url']);
				return false;
			}
		}

		$secret = Billrun_Factory::config()->getConfigValue("shared_secret.key");
		$data = array(
			"aid" => $request['aid'],
			"name" => $request['payment_gateway'],
			"type" => $type,
			"return_url" => urlencode($request['return_url']),
		);
		$signed = Billrun_Utils_Security::addSignature($data, $secret);
		$sendData = array(
			"data" => $signed,
		);

		header("Location: /paymentgateways/getRequest?data=" . json_encode($signed));
		return false;
	}

	protected function getPermissionLevel() {
		return Billrun_Traits_Api_IUserPermissions::PERMISSION_ADMIN;
	}
}