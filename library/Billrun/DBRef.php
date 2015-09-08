<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012-2013 S.D.O.C. LTD. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Billing rate class
 *
 * @package  Rate
 * @since    0.5
 */
class Billrun_DBRef {

	protected static $entities;
	protected static $keys = array(
		'rates' => 'key',
		'plans' => 'name',
	);

	protected static function initCollection($collection_name) {
		if (isset(self::$keys[$collection_name])) {
			$coll = Billrun_Factory::db()->{$collection_name . "Collection"}();
			$resource = $coll->query()->cursor();
			foreach ($resource as $entity) {
				$entity->collection($coll);
				self::$entities[$collection_name]['by_id'][strval($entity->getId())] = $entity;
				self::$entities[$collection_name]['by_key'][$entity[self::$keys[$collection_name]]][] = $entity;
			}
		}
	}

	/**
	 * Get DBRef field from entity. Returns input entity if reference not found.
	 * @param Mongodloid_Entity $entity - Entity to get ref from.
	 * @param string $fieldName - Name of the field to get the ref from.
	 * @return Mongoldoid_Entity
	 */
	public static function getDBRefField($entity, $fieldName) {
		$value = $entity->get($fieldName, true);
		if ($value && MongoDBRef::isRef($value)) {
			$value = Billrun_DBRef::getEntity($value);
		}
		return $value;
	}
	
	/**
	 * 
	 * @param type $db_ref
	 * @param type $time
	 */
	public static function getEntity($db_ref) {
		$matched_entity = null;
		$collection_name = $db_ref['$ref'];
		if (!isset(self::$entities[$collection_name]) && isset(self::$keys[$collection_name])) {
			self::initCollection($collection_name);
		}
		$id = strval($db_ref['$id']);
		if (isset(self::$entities[$collection_name]['by_id'][$id])) {
			$matched_entity = self::$entities[$collection_name]['by_id'][$id];
		}
		return $matched_entity;
	}

}
