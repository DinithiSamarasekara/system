<?php

/**
 * Discount management
 */
class Billrun_DiscountManager {
	use Billrun_Traits_ConditionsCheck;

	protected $startTime = null;
	protected $endTime = null;
	protected $eligibleDiscounts = [];
	protected static $discounts = [];
	protected static $discountsFields = [];

	public function __construct($accountRevisions, $subscribersRevisions = [], $params = []) {
		$time = Billrun_Util::getIn($params, 'time', time());
		$billrunKey = Billrun_Billingcycle::getBillrunKeyByTimestamp($time);
		$this->startTime = Billrun_Billingcycle::getStartTime($billrunKey);
		$this->endTime = Billrun_Billingcycle::getEndTime($billrunKey);
		$this->loadEligibleDiscounts($accountRevisions, $subscribersRevisions);
	}

	/**
	 * loads account's discount eligibilities
	 * 
	 * @param array $accountRevisions
	 * @param array $subscribersRevisions
	 */
	protected function loadEligibleDiscounts($accountRevisions, $subscribersRevisions = []) {
		$this->eligibleDiscounts = [];
		$accountDiscountsFields = self::getDiscountsFields('account', $this->endTime);
		$subscrbierDiscountsFields = self::getDiscountsFields('subscriber', $this->endTime);
		$accountRevisions = self::prepareEntityRevisions($accountRevisions, $accountDiscountsFields);
		foreach ($subscribersRevisions as &$subscriberRevisions) {
			$subscriberRevisions = self::prepareEntityRevisions($subscriberRevisions, $subscrbierDiscountsFields);
		}
		
		foreach (self::getDiscounts($this->endTime) as $discount) {
			$eligibilityDates = $this->getDiscountEligibility($discount, $accountRevisions, $subscribersRevisions);
			if (!empty($eligibilityDates)) {
				$this->eligibleDiscounts[$discount['key']] = [
					'discount' => $discount,
					'eligibility' => $eligibilityDates,
				];
			}
		}
	}
	
	/**
	 * splits existing revisions' ranges fields
	 * 
	 * @param type $entityRevisions
	 * @param array $fields - specific fields to split, if null received - splits all fields
	 * @return array
	 * @todo implement
	 */
	public static function prepareEntityRevisions($entityRevisions, $fields = null) {
		return $entityRevisions;
	}

	/**
	 * Get eligible discounts for account
	 * 
	 * @return array - array of discounts for the account
	 */
	public function getEligibleDiscounts($discountsOnly = false) {
		if ($discountsOnly) {
			return array_column($this->eligibleDiscounts, 'discount');
		}
		
		return $this->eligibleDiscounts;
	}

	/**
	 * get all active discounts in the system
	 * uses internal static cache
	 * 
	 * @param unixtimestamp $time
	 * @param array $query
	 * @return array
	 */
	public static function getDiscounts($time = null, $query = []) {
		if (empty(self::$discounts)) {
			$cycleEndTime = new MongoDate(is_null($time) ? time() : $time);
			$basicQuery = [
				'params' => [
					'$exists' => 1,
				],
				'from' => [
					'$lt' => $cycleEndTime,
				],
				'to' => [
					'$gt' => $cycleEndTime,
				],
			];
			$discountColl = Billrun_Factory::db()->discountsCollection();
			$loadedDiscounts = $discountColl->query(array_merge($basicQuery, $query))->cursor();
			self::$discounts = [];
			foreach ($loadedDiscounts as $discount) {
				self::$discounts[$discount['key']] = $discount;
			}
		}

		return self::$discounts;
	}

	/**
	 * get all fields used by discount for the given $type
	 * uses internal static cache
	 * 
	 * @param string $type
	 * @return array
	 */
	public static function getDiscountsFields($type, $time = null) {
		if (empty(self::$discountsFields[$type])) {
			self::$discountsFields[$type] = [];
			foreach (self::getDiscounts($time) as $discount) {
				foreach (Billrun_Util::getIn($discount, ['params', 'conditions'], []) as $condition) {
					if (!isset($condition[$type])) {
						continue;
					}
					
					foreach (Billrun_Util::getIn($condition, [$type, 'fields'], []) as $field) {
						self::$discountsFields[$type][] = $field['field'];
					}
				}
			}
			
			self::$discountsFields[$type] = array_unique(self::$discountsFields[$type]);
		}

		return self::$discountsFields[$type];
	}
	
	/**
	 * Get sorted time intervals when the account is eligible for the given discount 
	 * 
	 * @param array $conditions
	 * @param array $accountRevisions
	 * @param array $subscribersRevisions
	 * @return array of intervals
	 */
	protected function getDiscountEligibility($discount, $accountRevisions, $subscribersRevisions = []) {
		$conditions = Billrun_Util::getIn($discount, 'params.conditions', []);
		if (empty($conditions)) { // no conditions means apply to all entities
			return [
				$this->getAllCycleInterval(),
			];
		}
		
		$minSubscribers = Billrun_Util::getIn($discount, 'params.min_subscribers', 1);
		$maxSubscribers = Billrun_Util::getIn($discount, 'params.max_subscribers', null);
		$eligibility = [];
		
		if (count($subscribersRevisions) < $minSubscribers) { // skip conditions check if there are not enough subscribers
			return false;
		}
		
		foreach ($conditions as $condition) { // OR logic
			$conditionEligibility = $this->getConditionEligibility($condition, $accountRevisions, $subscribersRevisions, $minSubscribers, $maxSubscribers);
			
			if (empty($conditionEligibility)) {
				continue;
			}
			
			$eligibility = array_merge($eligibility, $conditionEligibility);
		}
		
		$eligibility = Billrun_Utils_Time::mergeTimeIntervals($eligibility);
		
		foreach ($eligibility as &$eligibilityInterval) {
			$eligibilityInterval['to']--; // intervals are calculated until start of next day so merge will be available
		}
		
		return $eligibility;
	}
	
	/**
	 * Get time intervals when the given condition is met for the account
	 * 
	 * @param array $conditions
	 * @param array $accountRevisions
	 * @param array $subscribersRevisions
	 * @param int $minSubscribers
	 * @param int $maxSubscribers - or null if no maximum is set
	 * @return array of intervals
	 */
	protected function getConditionEligibility($condition, $accountRevisions, $subscribersRevisions = [], $minSubscribers = 1, $maxSubscribers = null) {
		$accountEligibility = [];
		$subsEligibility = [];
		
		$accountConditions = Billrun_Util::getIn($condition, 'account.fields', []);
		
		if (empty($accountConditions)) {
			$accountEligibility[] = $this->getAllCycleInterval();
		} else {
			$accountEligibility = $this->getConditionsEligibilityForEntity($accountConditions, $accountRevisions);
			if (empty($accountEligibility)) {
				return false; // account conditions must match
			}
			$accountEligibility = Billrun_Utils_Time::mergeTimeIntervals($accountEligibility);
		}

		foreach ($subscribersRevisions as $subscriberRevisions) {
			$subscribersConditions = Billrun_Util::getIn($condition, 'subscriber.0.fields', []); // currently supports 1 condtion's type
		
			if (empty($subscribersConditions)) {
				$subsEligibility[$subscriberRevisions[0]['sid']] = [
					$this->getAllCycleInterval(),
				];
			} else {			
				$subEligibility = $this->getConditionsEligibilityForEntity($subscribersConditions, $subscriberRevisions);
				if (empty($subEligibility)) {
					continue; // if the current subscriber does not match, check other subscribers
				}
				$subsEligibility[$subscriberRevisions[0]['sid']] = Billrun_Utils_Time::mergeTimeIntervals($subEligibility);
			}
		}
		
		$ret = [];
		// goes only over accout's eligibility because it must met
		foreach ($accountEligibility as $accountEligibilityInterval) {
			// check eligibility day by day
			for ($day = $accountEligibilityInterval['from']; $day <= $accountEligibilityInterval['to']; $day = strtotime('+1 day', $day)) {
				$eligibleSubsInDay = 0;
				$dayFrom = strtotime('midnight', $day);
				$dayTo = strtotime('+1 day', $dayFrom);
				foreach ($subsEligibility as $subEligibility) {
					foreach ($subEligibility as $subEligibilityIntervals) {
						if ($subEligibilityIntervals['from'] <= $day && $subEligibilityIntervals['to'] >= $day) {
							$eligibleSubsInDay++;
							
							if (!is_null($maxSubscribers) && $eligibleSubsInDay > $maxSubscribers) { // passed max subscribers in current day
								continue 3; // check next day
							}
							
							if (is_null($maxSubscribers) && $eligibleSubsInDay >= $minSubscribers) { // passed min subscribers, and no max is defined
								$ret[] = [
									'from' => $dayFrom,
									'to' => $dayTo,
								];
								continue 3; // check next day
							}
							
							continue 2; // check next subscriber
						}
						
						if ($subEligibilityIntervals['from'] > $day) {
							continue 2; // intervals are sorted, check next subscriber
						}
					}
				}
				
				if ($eligibleSubsInDay >= $minSubscribers) { // account is eligible for the discount in current day
					$ret[] = [
						'from' => $dayFrom,
						'to' => $dayTo,
					];
				}
			}
		}
		
		return Billrun_Utils_Time::mergeTimeIntervals($ret);
	}

	/**
	 * get array of intervals on which the entity meets the conditions
	 * 
	 * @param array $conditions
	 * @param array $entityRevisions
	 * @return array of intervals
	 */
	protected function getConditionsEligibilityForEntity($conditions, $entityRevisions) {
		$eligibility = [];
		foreach ($entityRevisions as $entityRevision) {
			if ($this->isConditionsMeet($entityRevision, $conditions)) {
				$eligibility[] = [
					'from' => $entityRevision['from']->sec,
					'to' => $entityRevision['to']->sec,
				];
			}
				
		}
		
		return $eligibility;
	}
	
	/**
	 * gets intervals covers entire cycle
	 * 
	 * @return array
	 */
	protected function getAllCycleInterval() {
		return [
			'from' => $this->startTime,
			'to' => $this->endTime,
		];
	}

}
