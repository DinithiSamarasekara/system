<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012 S.D.O.C. LTD. All rights reserved.
 * @license         GNU General Public License version 2 or later; see LICENSE.txt
 */

/**
 * Billing Tariff class
 * 
 * Main rate system
 * 
 * @package  Billing
 * @since    0.5
 */
class Billrun_Tariff {

	/**
	 * Instance of the Tariff
	 *
	 * @var self instance (Billrun_Tariff)
	 */
	static protected $instance = null;

	/**
	 * Options of the Tariff
	 *
	 * @var array
	 */
	protected $options = null;

	/**
	 * Rates data of the Tariff
	 *
	 * @var array
	 */
	protected $rates = null;

	/**
	 * constructor
	 * 
	 * @param array $options the options to preset for the class
	 */
	protected function __construct(array $options = array()) {
		$this->options = $options;
		// todo: support lazy load
		$this->load();
	}

	/**
	 * load the tariff from DB
	 */
	protected function load() {
		$this->rates = Billrun_Factory::db()->ratesCollection();
	}

	/**
	 * get tariff rate
	 */
	public function get() {
		$args = func_get_args();
		if (!is_array($args[0])) {
			return;
		}

		$params = $args[0];

		if (isset($params['searchValue'])) {
			$searchValue = $params['searchValue'];
		} else {
			return;
		}

		if (isset($params['searchBy'])) {
			$searchField = $params['searchBy'];
		} else {
			$searchField = 'key';
		}

		if (isset($params['findColumn'])) {
			$returnField = $params['findColumn'];
		} else {
			$returnField = false;
		}

		if (isset($params['callback'])) {
			$callback = $params['callback'];
		} else {
			$callback = false;
		}

		if ($callback) {
//			call_user_func_array($callback, $param_arr);
		}

		$data = $this->rates->query()->equal($searchField, $searchValue);
		if ($returnField) {
			
		}
	}

	/**
	 * set the tariff rate
	 */
	public function set() {
		return false;
	}

	/**
	 * delete tariff rate
	 */
	public function delete() {
		return false;
	}

	/**
	 * save tariff to database
	 */
	public function save() {
		return false;
	}

	/**
	 * Singleton of tariff class
	 * 
	 * @param array $options the options of the instance
	 * 
	 * @return type
	 */
	static public function getInstance(array $options = array()) {
		if (!isset(self::$instance)) {
			self::$instance = new self($options);
		}
		return self::$instance;
	}

}
