<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Holds the logic for updating balances using charging plans.
 *
 * @author Tom Feigin
 */
class Billrun_ActionManagers_Balances_Updaters_Id extends Billrun_ActionManagers_Balances_Updaters_Updater{
	
	protected $type = 'MongoId';
	
	protected $balancesRecord = null;

	/**
	 * Get the subscriber by the update parameters.
	 * @param int $subscriberId - The subscriber's ID
	 * @param Mongo Record $recordToSet - The record to be set with the update function.
	 * @return false if error, subscriber element if successful
	 */
	protected function handleSubscriber($subscriberId, $recordToSet) {
		// Get the subscriber.
		$subscriber = $this->getSubscriber($subscriberId);	
		
		// Subscriber was not found.
		if(!$subscriber) {
			$errorCode = Billrun_Factory::config()->getConfigValue("balances_error_base") + 3;
			$this->reportError($errorCode, Zend_Log::NOTICE);
			return false;
		}
		
		if(!$this->validateServiceProviders($subscriberId, $recordToSet)) {
			return false;
		}
		
		return $subscriber;
	}
	
	/**
	 * Update the balances, based on the plans table
	 * @param type $query - Query to find row to update.
	 * @param type $recordToSet - Values to update.
	 * @param type $subscriberId - Id for the subscriber to update.
	 * @return The updated record, false if failed.
	 */
	public function update($query, $recordToSet, $subscriberId) {
		$coll = Billrun_Factory::db()->balancesCollection();
		$this->getBalanceRecord($coll, $query);
		if(!$this->balancesRecord){
			$errorCode = Billrun_Factory::config()->getConfigValue("balances_error_base") + 2;
			$this->reportError($errorCode, Zend_Log::NOTICE);
			return false;
		}
		
		$subscriber = $this->handleSubscriber($subscriberId, $recordToSet);
		if($subscriber===false){
			return false;
		}
		
		$this->handleExpirationDate($recordToSet, $subscriberId);
		
		$balancesColl = Billrun_Factory::db()->balancesCollection();
		
		$updateResult = $this->updateBalance($query, $balancesColl, $recordToSet);
		$updateResult[0]['source'] = 
			Billrun_Factory::db()->subscribersCollection()->createRefByEntity($subscriber);
		$updateResult[0]['subscriber'] = 
			$subscriber;
		return $updateResult;
	}
	
	/**
	 * Get the record from the balance collection.
	 * @param type $balancesColl
	 * @param type $query
	 * @return type
	 */
	protected function getBalanceRecord($balancesColl, $query) {
		$cursor = $balancesColl->query($query)->cursor();

		// Find the record in the collection.
		$balanceRecord = $cursor->current();
		
		if(!$balanceRecord || $balanceRecord->isEmpty()) {
			$errorCode = Billrun_Factory::config()->getConfigValue("balances_error_base") + 4;
			$this->reportError($errorCode, Zend_Log::NOTICE);
			return;
		}
		
		$this->balancesRecord = $balanceRecord;
	}
	
	/**
	 * Update a single balance.
	 * @param type $query
	 * @param type $balancesColl
	 * @return Array with the wallet as the key and the Updated record as the value
	 */
	protected function updateBalance($query, $balancesColl, $recordToSet) {
		$valueFieldName = array();
		$valueToUseInQuery = null;
		
		// Find the record in the collection.
		if(!$this->balancesRecord) {
			$errorCode = Billrun_Factory::config()->getConfigValue("balances_error_base") + 5;
			$this->reportError($errorCode, Zend_Log::NOTICE);
			return false;
		}
		
		list($chargingBy, $chargingByValue) = each($this->balancesRecord['balance']);
		
		if(!is_array($chargingByValue)){
			$valueFieldName= 'balance.' . $chargingBy;
			$valueToUseInQuery = $chargingByValue;
		}else{
			list($chargingByValueName, $value)= each($chargingByValue);
			$valueFieldName= 'balance.totals.' . $chargingBy . '.' . $chargingByValueName;
			$valueToUseInQuery = $value;
			$chargingBy=$chargingByValueName;
		}

		$valueUpdateQuery = array();
		$queryType = $this->isIncrement ? '$inc' : '$set';
		$valueUpdateQuery[$queryType]
				   [$valueFieldName] = $valueToUseInQuery;
		$valueUpdateQuery[$queryType]
				   ['to'] = $recordToSet['to'];

		$options = array(
			// We do not want to upsert if trying to update by ID.
			'upsert' => false,
			'new' => true,
		);
		
		$ppPair['pp_includes_external_id'] = $this->balancesRecord['pp_includes_external_id'];
		$ppPair['pp_includes_name'] = $this->balancesRecord['pp_includes_name'];
		
		$usedWallet = new Billrun_DataTypes_Wallet($chargingBy, $chargingByValue, $ppPair);
		
		$balance = $balancesColl->findAndModify($query, $valueUpdateQuery, array(), $options, true);
		// Return the new document.
		return array(array('wallet'=>$usedWallet, 'balance' => $balance));
	}
}