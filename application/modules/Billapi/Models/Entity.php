<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2016 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Billapi model for operations on BillRun entities
 *
 * @package  Billapi
 * @since    5.3
 */
class Models_Entity {

	use Models_Verification;
	
	/**
	 * Entirty available statuses values
	 */
	const FUTURE = 'future';
	const EXPIRED = 'expired';
	const ACTIVE = 'active';
	const ACTIVE_WITH_FUTURE = 'active_with_future';

	/**
	 * The DB collection name
	 * @var string
	 */
	protected $collectionName;

	/**
	 * The DB collection
	 * @var Mongodloid_Collection
	 */
	protected $collection;

	/**
	 * The entity billapi configuration
	 * @var array
	 */
	protected $config;

	/**
	 * The wanted query
	 * @var array
	 */
	protected $query = array();

	/**
	 * The new data
	 * @var array
	 */
	protected $update = array();

	/**
	 * The wanted sort (for get operations)
	 * @var array
	 */
	protected $sort = array();

	/**
	 * Page number for get operations
	 * @var int
	 */
	protected $page = 0;

	/**
	 * Page size for get operations
	 * @var int
	 */
	protected $size = 10;

	/**
	 * the entity before the change
	 * 
	 * @var array
	 */
	protected $before = null;

	/**
	 * the entity after the change
	 * 
	 * @var array
	 */
	protected $after = null;

	/**
	 * the change action applied on the entity
	 * 
	 * @var string
	 */
	protected $action = 'change';
	
	/**
	 * minimum update datetime (unix timestamp)
	 * 
	 * @var int
	 */
	static protected $minUpdateDatetime;

	/**
	 * the change action applied on the entity
	 * 
	 * @var string
	 */
	protected $availableOperations = array('query', 'update', 'sort');

	public function __construct($params) {
		if ($params['collection'] == 'accounts') {
			$this->collectionName = 'subscribers';
		} else {
			$this->collectionName = $params['collection'];
		}
		$this->collection = Billrun_Factory::db()->{$this->collectionName . 'Collection'}();
		$this->config = Billrun_Factory::config()->getConfigValue('billapi.' . $params['collection'], array());
		if (isset($params['request']['action'])) {
			$this->action = $params['request']['action'];
		}
		$this->init($params);
	}

	protected function init($params) {
		$query = isset($params['request']['query']) ? @json_decode($params['request']['query'], TRUE) : array();
		$update = isset($params['request']['update']) ? @json_decode($params['request']['update'], TRUE) : array();
		if (json_last_error() != JSON_ERROR_NONE) {
			throw new Billrun_Exceptions_Api(0, array(), 'Input parsing error');
		}

		list($translatedQuery, $translatedUpdate) = $this->validateRequest($query, $update, $this->action, $this->config[$this->action], 999999);
		$this->setQuery($translatedQuery);
		$this->setUpdate($translatedUpdate);
		foreach ($this->availableOperations as $operation) {
			if (isset($params[$operation])) {
				$this->{$operation} = $params[$operation];
			}
		}
		$page = Billrun_Util::getFieldVal($params['page'], 0);
		$this->page = Billrun_Util::IsIntegerValue($page) ? $page : 0;
		$size = Billrun_Util::getFieldVal($params['size'], 10);
		$this->size = Billrun_Util::IsIntegerValue($size) ? $size : 10;
		if (isset($this->query['_id'])) {
			$this->setBefore($this->loadById($this->query['_id']));
		}
		if (isset($this->config[$this->action]['custom_fields']) && $this->config[$this->action]['custom_fields']) {
			$this->addCustomFields($this->config[$this->action]['custom_fields'], $update);
		}
	}

	/**
	 * method to add entity custom fields values from request
	 * 
	 * @param array $fields array of field settings
	 */
	protected function addCustomFields($fields, $originalUpdate) {
//		$ad = $this->getCustomFields();
		$customFields = $this->getCustomFields();
		$additionalFields = array_column($customFields, 'field_name');
		$mandatorylValues = array_map(function($field) {return isset($field['mandatory']) ? $field['mandatory'] : false;}, $customFields);
		$mandatoryFields = array_combine($additionalFields, $mandatorylValues);
		$defaultFields = array_column($this->config[$this->action]['update_parameters'], 'name');
		$customFields = array_diff($additionalFields, $defaultFields);
//		print_R($customFields);
		foreach ($customFields as $field) {
			if ($mandatoryFields[$field] && (Billrun_Util::getIn($originalUpdate, $field, '') === '')) {
				throw new Billrun_Exceptions_Api(0, array(), "Mandatory field: $field is missing");
			}
			$val = Billrun_Util::getIn($originalUpdate, $field, false);
			if ($val) {
				Billrun_Util::setIn($this->update, $field, $val);
			}
		}
//		print_R($this->update);die;
	}

	protected function getCustomFields() {
		return Billrun_Factory::config()->getConfigValue($this->collectionName . ".fields", array());
	}

	/**
	 * Create a new entity
	 * @param type $data the entity to create
	 * @return boolean
	 * @throws Billrun_Exceptions_Api
	 */
	public function create() {
		$this->action = 'create';
		unset($this->update['_id']);
		if (empty($this->update['from'])) {
			$this->update['from'] = new MongoDate();
		}
		if (empty($this->update['to'])) {
			$this->update['to'] = new MongoDate(strtotime('+149 years'));
		}
		if ($this->duplicateCheck($this->update)) {
			$status = $this->insert($this->update);
			$this->trackChanges($this->update['_id']);
			return isset($status['ok']) && $status['ok'];
		} else {
			throw new Billrun_Exceptions_Api(0, array(), 'Entity already exists');
		}
	}

	/**
	 * Performs the update action by a query and data to update
	 * @param array $query
	 * @param array $data
	 */
	public function update() {
		$this->action = 'update';
		
		if (!$this->query || empty($this->query) || !isset($this->query['_id'])) {
			return;
		}
		
		if ($this->preCheckUpdate() !== TRUE) {
			return false;
		}
		$status = $this->dbUpdate($this->query, $this->update);
		if (!isset($status['nModified']) || !$status['nModified']) {
			return false;
		}
		$this->trackChanges($this->query['_id']);
		return true;
	}

	/**
	 * method to check if the update is valid
	 * actual for update and closeandnew methods
	 * 
	 * @throws Billrun_Exceptions_Api
	 */
	protected function preCheckUpdate($time = null) {
		$ret = $this->checkDateRangeFields($time);
		Billrun_Factory::dispatcher()->trigger('beforeBillApiUpdate', array($this->before, &$this->query, &$this->update, &$ret));
		return $ret;
	}
	
	/**
	 * method to check date range fields
	 * by default checking only to field (not in the past)
	 * 
	 * @param int $time (optional) unix timestamp for minimum to value
	 * 
	 * @return true if check success else false
	 */
	protected function checkDateRangeFields($time = null) {
		if (is_null($time)) {
			$time = time();
		}
		if (isset($this->before['to']->sec) && $this->before['to']->sec < self::getMinimumUpdateDate()) {
			return false;
		}
		return true;
	}

	/**
	 * method to close the current entity and open a new one (for in-advance changes of entities)
	 * 
	 * @return mixed array of insert status, on failure false
	 * 
	 * @todo avoid overlapping of entities
	 */
	public function closeandnew() {
		$this->action = 'closeandnew';
		if ($this->preCheckUpdate() !== TRUE) {
			return false;
		}
		$now = new MongoDate();
		if (!isset($this->update['from'])) {
			$this->update['from'] = $now;
		}
		
		$this->checkMinimumDate($this->update, 'from', 'Revision update');
		$this->verifyLastEntry();
		
		if ($this->before['from']->sec > $this->update['from']->sec) {
			throw new Billrun_Exceptions_Api(1, array(), 'Revision update minimum date is ' . date('Y-m-d', $this->before['from']->sec));
			return false;
		}
		
		$closeAndNewPreUpdateOperation = array(
			'$set' => array(
				'to' => new MongoDate($this->update['from']->sec)
			)
		);
		$res = $this->collection->update($this->query, $closeAndNewPreUpdateOperation);
		if (!isset($res['nModified']) || !$res['nModified']) {
			return false;
		}

//		$oldId = $this->query['_id'];
		unset($this->update['_id']);
		$status = $this->insert($this->update);
		$newId = $this->update['_id'];
		$this->trackChanges($newId);
		return isset($status['ok']) && $status['ok'];
	}
	
	/**
	 * method get the minimum time to update
	 * 
	 * @return unix timestamp
	 */
	protected static function getMinimumUpdateDate() {
		if (empty(self::$minUpdateDatetime)) {
			self::$minUpdateDatetime = Billrun_Billingcycle::getStartTime(Billrun_Billingcycle::getLastClosedBillingCycle());
		}
		return self::$minUpdateDatetime;
	}

	/**
	 * Gets an entity by a query
	 * @param array $query
	 * @param array $data
	 * @return array the entities found
	 */
	public function get() {
		if (isset($this->config['active_documents']) && $this->config['active_documents']) {
			$add_query = Billrun_Utils_Mongo::getDateBoundQuery();
			$this->query = array_merge($add_query, $this->query);
		}
		$ret = $this->runQuery($this->query, $this->sort);
		if (isset($this->config['get']['columns_filter_out']) && count($this->config['get']['columns_filter_out'])) {
			$filter_columns = $this->config['get']['columns_filter_out'];
			array_walk($ret, function(&$item) use ($filter_columns) {
				$item = array_diff_key($item, array_flip($filter_columns));
			});
		}
		return $ret;
	}
	
	/**
	 * Verify that an entity can be deleted.
	 * 
	 * @return boolean
	 */
	protected function canEntityBeDeleted() {
		return true;
	}
	
	/**
	 * method to check if the current query allocate the last entry
	 * 
	 * @return boolean true if the last entry else false
	 */
	protected function verifyLastEntry() {
		$entry = $this->collection->query($this->query)->cursor()->sort(array('_id' => 1))->current();
		if (isset($entry['_id']) && $this->before['_id'] != $entry['_id']) {
			throw new Billrun_Exceptions_Api(1500, array(), "Cannot remove old entries, but only the last created entry that exists");
		}
		return true;
	}

	/**
	 * method to check minimum date by the last billing cycle
	 * 
	 * @param array $params the parameters the field exists
	 * @param string $field the field to check
	 * @param string $action the action that is checking
	 * 
	 * @return true on success else false
	 * 
	 * @throws Billrun_Exceptions_Api
	 */
	protected function checkMinimumDate($params, $field = 'to', $action = null) {
		if (is_null($action)) {
			$action = $this->action;
		}

		$fromMinTime = self::getMinimumUpdateDate();
		if (isset($params[$field]->sec) && $params[$field]->sec < $fromMinTime) {
			throw new Billrun_Exceptions_Api(1, array(), ucfirst($action) . ' minimum date is ' . date('Y-m-d', $fromMinTime));
			return false;
		}
		return true;
	}
	/**
	 * Deletes an entity by a query
	 * @param array $query
	 * @param array $update
	 * @return type
	 */
	public function delete() {
		$this->action = 'delete';
		if (!$this->canEntityBeDeleted()) {
			throw new Billrun_Exceptions_Api(2, array(), 'entity cannot be deleted');
		}
		
		if (!$this->query || empty($this->query) || !isset($this->query['_id']) || !isset($this->before) && $this->before->isEmpty()) { // currently must have some query
			return false;
		}
		
		$this->verifyLastEntry();
		$this->checkMinimumDate($this->before, 'from');

		$status = $this->remove($this->query); // TODO: check return value (success to remove?)
		if (!isset($status['ok']) || !$status['ok']) {
			return false;
		}
		$this->trackChanges(null); // assuming remove by _id
		
		if (isset($this->before['from']->sec) && $this->before['from']->sec >= self::getMinimumUpdateDate()) {
			return $this->reopenPreviousEntry();
		}
		return true;
	}
	
	/**
	 * make entity expired by setting to field with datetime of now
	 * 
	 * @return boolean true on success else false
	 */
	public function close() {
		$this->action = 'close';
		if (!$this->query || empty($this->query)) { // currently must have some query
			return;
		}

		if (!isset($this->update['to'])) {
			$this->update = array(
				'to' => new MongoDate()
			);
		}
		
		$this->checkMinimumDate($this->update);

		$status = $this->dbUpdate($this->query, $this->update);
		if (!isset($status['nModified']) || !$status['nModified']) {
			return false;
		}
		$this->trackChanges($this->query['_id']);
		return true;
	}
	
	/**
	 * move from date of entity including change the previous entity to field
	 * 
	 * @return boolean true on success else false
	 */
	public function move() {
		$this->action = 'move';
		if (!$this->query || empty($this->query) || !isset($this->query['_id'])) { // currently must have some query
			return;
		}
		
		if (!isset($this->update['from'])) {
			$this->update = array(
				'from' => new MongoDate()
			);
		}
		
		if ($this->update['from']->sec > $this->before['to']->sec) {
			throw new Billrun_Exceptions_Api(0, array(), 'Requested start date greater than end date');
		}
		
		$this->checkMinimumDate($this->update, 'from');
		
		$keyField = $this->getKeyField();
		$query = array(
			$keyField => $this->before[$keyField], 
			'to' => array(
				'$lte' => $this->before['from'],
			)
		);
		$previousEntry = $this->collection->query($query)->cursor()->sort(array('to' => -1))->current();

		if (!empty($previousEntry) && !$previousEntry->isEmpty() && $previousEntry['from']->sec > $this->update['from']->sec) {
			throw new Billrun_Exceptions_Api(0, array(), 'Requested start date is less than previous end date');
		}
		
		$status = $this->dbUpdate($this->query, $this->update);
		if (!isset($status['nModified']) || !$status['nModified']) {
			return false;
		}
		$this->trackChanges($this->query['_id']);
		
		if (!empty($previousEntry) && !$previousEntry->isEmpty()) {
			$this->setQuery(array('_id' => $previousEntry['_id']->getMongoID()));
			$this->setUpdate(array('to' => new MongoDate($this->update['from']->sec - 1)));
			$this->setBefore($previousEntry);
			return $this->update();
		}
		return true;
	}

	/**
	 * DB update currently limited to update of one record
	 * @param type $query
	 * @param type $data
	 */
	protected function dbUpdate($query, $data) {
		unset($data['_id']);
		$update = array(
			'$set' => $data,
		);
		return $this->collection->update($query, $update);
	}

	/**
	 * Run a DB query against the current collection
	 * @param array $query
	 * @return array the result set
	 */
	protected function runQuery($query, $sort) {
		$res = $this->collection->find($query);

		if ($this->page != -1) {
			$res->skip($this->page * $this->size);
		}

		if ($this->size != -1) {
			$res->limit($this->size);
		}

		if ($sort) {
			$res = $res->sort($sort);
		}
		
		$records =  array_values(iterator_to_array($res));
		foreach($records as  &$record) {
			$record = Billrun_Utils_Mongo::recursiveConvertRecordMongoDatetimeFields($record);
		}
		return $records;
	}

	/**
	 * Performs a delete from the DB by a query
	 * @param array $query
	 */
	protected function remove($query) {
		return $this->collection->remove($query);
	}
	
	/**
	 * future entity was removed - need to update the to of the previous change
	 */
	protected function reopenPreviousEntry() {
		$key = $this->getKeyField();
		$previousEntryQuery = array(
			$key => $this->before[$key],
		);
		$previousEntrySort = array(
			'_id' => -1
		);
		$previousEntry = $this->collection->query($previousEntryQuery)->cursor()
				->sort($previousEntrySort)->limit(1)->current();
		if (!$previousEntry->isEmpty()) {
			$this->setQuery(array('_id' => $previousEntry['_id']->getMongoID()));
			$this->setUpdate(array('to' => $this->before['to']));
			$this->setBefore($previousEntry);
			return $this->update();
		}
		return TRUE;
	}

	/**
	 * method to update the update instruct
	 * @param array $u mongo update instruct
	 */
	public function setUpdate($u) {
		$this->update = $u;
	}
	
	/**
	 * method to update the query instruct
	 * @param array $q mongo query instruct
	 */
	public function setQuery($q) {
		$this->query = $q;
	}

	/**
	 * method to update the before entity
	 * @param array $b the before entity
	 */
	public function setBefore($b) {
		$this->before = $b;
	}

	/**
	 * method to return the before state of the entity
	 *
	 * @return array $b the before state entity
	 */
	public function getBefore() {
		return $this->before;
	}

	/**
	 * method to return the after state of the entity
	 *
	 * @return array $b the after state entity
	 */
	public function getAfter() {
		return $this->after;
	}

	/**
	 * method to track changes with audit trail
	 * 
	 * @param MongoId $newId the new id; if null take from update array _id field
	 * @param MongoId $oldId the old id; if null this is new document (insert operation)
	 * 
	 * @return boolean true on success else false
	 */
	protected function trackChanges($newId = null) {
		$field = $this->getKeyField();
		if (is_null($newId) && isset($this->update['_id'])) {
			$newId = $this->update['_id'];
		}

		if ($newId) {
			$this->after = $this->loadById($newId);
		}

		try {
			$user = Billrun_Factory::user();
			if (!is_null($user)) {
				$trackUser = array(
					'_id' => $user->getMongoId()->getMongoID(),
					'name' => $user->getUsername(),
				);
			} else { // in case 3rd party API update with token => there is no user
				$trackUser = array(
					'_id' => null,
					'name' => '_3RD_PARTY_TOKEN_',
				);
			}
			$logEntry = array(
				'source' => 'audit',
				'type' => $this->action,
				'urt' => new MongoDate(),
				'user' => $trackUser,
				'collection' => $this->collectionName,
				'old' => !is_null($this->before) ? $this->before->getRawData() : null,
				'new' => !is_null($this->after) ? $this->after->getRawData() : null,
				'key' => isset($this->update[$field]) ? $this->update[$field] : 
							(isset($this->before[$field]) ? $this->before[$field] : null),
			);
			$logEntry['stamp'] = Billrun_Util::generateArrayStamp($logEntry);
			Billrun_Factory::db()->logCollection()->save(new Mongodloid_Entity($logEntry));
			return true;
		} catch (Exception $ex) {
			Billrun_Factory::log('Failed on insert to audit trail. ' . $ex->getCode() . ': ' . $ex->getMessage(), Zend_Log::ERR);
		}
		return false;
	}

	/**
	 * method to load the entity from DB by _id
	 * 
	 * @param mixed $id MongoId or id (string) of the entity
	 * 
	 * @return array the entity loaded
	 */
	protected function loadById($id) {
		$fetchQuery = array('_id' => ($id instanceof MongoId) ? $id : new MongoId($id));
		return $this->collection->query($fetchQuery)->cursor()->current();
	}

	/**
	 * Inserts a document to the DB, as is
	 * @param array $data
	 */
	protected function insert(&$data) {
		$ret = $this->collection->insert($data, array('w' => 1, 'j' => 1));
		return $ret;
	}

	/**
	 * Returns true if current record does not overlap with existing records in the DB
	 * @param array $data
	 * @param array $ignoreIds
	 * @return boolean
	 */
	protected function duplicateCheck($data, $ignoreIds = array()) {
		$query = array();
		foreach (Billrun_Util::getFieldVal($this->config['duplicate_check'], []) as $fieldName) {
			$query[$fieldName] = $data[$fieldName];
		}
		if ($ignoreIds) {
			$query['_id'] = array(
				'$nin' => $ignoreIds,
			);
		}
		return $query ? !$this->collection->query($query)->count() : TRUE;
	}

	/**
	 * Return the key field by collection
	 * 
	 * @return String
	 */
	protected function getKeyField() {
		switch ($this->collectionName) {
			case 'users':
				return 'username';
			case 'rates':
				return 'key';
			case 'subscribers':
				return 'sid'; // for account it should be 'aid'
			default:
				return 'name';
		}
	}
	
	/**
	 * Add revision info (status, early_expiration) to record
	 * 
	 * @param array $record - Record to set revision info.
	 * @param string $collection - Record collection
	 * 
	 * @return The record with revision info.
	 */
	public static function setRevisionInfo($record, $collection) {
		$status = self::getStatus($record, $collection);
		$earlyExpiration = self::isEarlyExpiration($record, $status);
		$isCurrentCycle = $record['from']->sec >= self::getMinimumUpdateDate();
		$record['revision_info'] = array(
			"status" => $status,
			"early_expiration" => $earlyExpiration,
			"updatable" => $isCurrentCycle,
			"closeandnewable" => $isCurrentCycle,
			"movable" => $isCurrentCycle,
			"removable" => $isCurrentCycle,
		);
		return $record;
	}
	
	/**
	 * Calculate record status
	 * 
	 * @param array $record - Record to set revision info.
	 * @param string $collection - Record collection name
	 * 
	 * @return string Status, available values are: "future", "expired", "active_with_future", "active"
	 */
	static function getStatus($record, $collection) {
		if ($record['to']->sec < time()) {
			return self::EXPIRED;
		}
		if ($record['from']->sec > time()) {
			return self::FUTURE;
		}
		// For active records, check if it has furure revisions
		$query = Billrun_Utils_Mongo::getDateBoundQuery($record['to']->sec, true);
		$uniqueFields = Billrun_Factory::config()->getConfigValue("billapi.{$collection}.duplicate_check", array());
		foreach ($uniqueFields as $fieldName) {
			$query[$fieldName] = $record[$fieldName];
		}
		$recordCollection = Billrun_Factory::db()->{$collection . 'Collection'}();
		$isFutureExist = $recordCollection->query($query)->count() > 0;
		
		if ($isFutureExist) {
			return self::ACTIVE_WITH_FUTURE;
		}
		return self::ACTIVE;
	}
	
	/**
	 * Check if record was closed by close action.
	 * true if the "to" field is less than 50 years from record "from" date.
	 * 
	 * @param array $record - Record to set revision info.
	 * @param string $status - Record status, available values are: "expired", "active_with_future", "active", "future"
	 * 
	 * @return bool
	 */
	static function isEarlyExpiration($record, $status) {
		if ($status === self::FUTURE || $status === self::ACTIVE) {
			return $record['from']->sec > $record['to']->sec;
		}
		return false;
	}

}
