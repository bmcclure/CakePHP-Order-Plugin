<?php
/**
 * Class: SortableBehavior
 * Plugin: Order
 * Author: Ben McClure
 * Based on: http://bakery.cakephp.org/articles/dardosordi/2008/07/29/sortablebehavior-sort-your-models-arbitrarily
 */
 class SortableBehavior extends ModelBehavior {
	 protected $_defaultSettings = array(
	 	'field' => 'position',
		'enabled' => true,
		'cluster' => false
	 );

 	public function setup($Model, $settings = array()) {
 		if (!isset($this->settings[$Model->alias])) {
			$this->settings[$Model->alias] = $this->_defaultSettings;
		}

		$this->settings[$Model->alias] = array_merge($this->settings[$Model->alias], (array) $settings);
 	}

	public function beforeFind($Model, $query) {
		extract($this->settings[$Model->alias]);

		if (!$enabled) {
			return $query;
		}

		if (isset($query['fields']) && is_string($query['fields']) && strpos($query['fields'], 'COUNT') === 0) {
			return $query;
		}

		if (isset($query['order']) && !empty($query['order'])) {
			return $query;
		}

		$query['order'] = array($this->_fieldName($Model) => 'ASC');

		if ($cluster !== false) {
			$query['order'] = array_merge(array($cluster => 'ASC'), $query['order']);
		}

		return $query;
	}

	public function beforeSave($Model) {
		extract($this->settings[$Model->alias]);

		if (!$enabled) {
			return true;
		}

		$isInsert = !$Model->id;

		$newPosition = null;
		if (isset($Model->data[$Model->alias][$field]) && !empty($Model->data[$Model->alias][$field])) {
			$newPosition = $Model->data[$Model->alias][$field];
		}

		$clusterId = $this->_clusterId($Model);

		$Model->data[$Model->alias][$field] = $this->lastPosition($Model, $clusterId) + 1;

		$Model->__fixPosition = $newPosition;

		return true;
	}

	public function afterSave($Model, $created) {
		extract($this->settings[$Model->alias]);

		if (!$enabled) {
			return true;
		}

		$position = $Model->data[$Model->alias][$field];

		if ($Model->__fixPosition) {
			$position = $Model->__fixPosition;

			$Model->__fixPosition = null;

			$this->setPosition($Model, $Model->id, $position);
		}
	}

	public function beforeDelete($Model, $cascade = true) {
		extract($this->settings[$Model->alias]);

		if (!$enabled) {
			return true;
		}

		$Model->__fixPosition = $this->position($Model);

		return true;
	}

	public function afterDelete($Model) {
		extract($this->settings[$Model->alias]);

		if (!$enabled) {
			return true;
		}

		$fieldName = $this->_fieldName($Model);

		$position = $Model->__fixPosition;
		$Model->__fixPosition = null;

		$Model->updateAll(array($fieldName => "$fieldName - 1"), array("$fieldName >=" => $position));
	}

	public function moveTop($Model, $id = null) {
		$this->setPosition($Model, $id, 1);
	}

	public function moveUp($Model, $id = null, $step = 1) {
		$this->setPosition($Model, $id, $this->position($Model, $id) - $step);
	}

	public function moveDown($Model, $id = null, $step = 1) {
		$this->setPosition($Model, $id, $this->position($Model, $id) + $step);
	}

	public function moveBottom($Model, $id = null) {
		$this->setPosition($Model, $id, $this->lastPosition($Model, $this->_clusterId($Model, $id)));
	}

	protected function _disable($Model) {
		// Cache the previous state
		$this->_enable($Model, $this->settings[$Model->alias]['enabled']);

		$this->settings[$Model->alias]['enabled'] = false;
	}

	protected function _enable($Model, $cacheValue = null) {
		if ($cacheValue != null) {
			if (!isset($cached)) {
				static $cached = array();
			}

			$cached[$Model->alias] = $cacheValue;
			return;
		}

		$enable = true;
		if (isset($cached[$Model->alias])) {
			$enable = $cached[$Model->alias];

			unset($cached[$Model->alias]);
		}

		$this->settings[$Model->alias]['enabled'] = $enable;
	}

	public function setPosition($Model, $id = null, $destination = 1) {
		$this->_disable($Model);

		extract($this->settings[$Model->alias]);

		$position = $this->position($Model);

		$clusterId = $this->_clusterId($Model);
		$fieldName = $this->_fieldName($Model);

		$delta = $position - $destination;

		if ($position != $destination) {
			if ($position > $destination) {
				if ($destination < 1) {
					$destination = 1;
				}

				$operator1 = '<=';
				$operator2 = '>=';
				$updateValue = '+';
			} elseif ($position < $destination) {
				$last = $this->lastPosition($Model, $clusterId);
				if ($destination > $last) {
					$destination = $last;
				}

				$operator1 = '>=';
				$operator2 = '<=';
				$updateValue = '-';
			}

			$Model->updateAll(array($fieldName => "$fieldName $updateValue 1"), $this->_conditions($Model, $clusterId, array(
				"$fieldName $operator1" => $position,
				"$fieldName $operator2" => $destination
			)));

			$this->_savePosition($Model, $id, $destination);
		}

		$this->_enable($Model);
	}

	protected function _savePosition($Model, $id = null, $position = 1) {
		if (!is_null($id)) {
			$oldId = $Model->id;
			$Model->id = $id;
		}

		$Model->saveField($this->settings[$Model->alias]['field'], $position);

		if (!is_null($id)) {
			$Model->id = $oldId;
		}
	}

	public function position($Model, $id = null) {
		if ($id) {
			$Model->id = $id;
		}

		return $Model->field($this->settings[$Model->alias]['field']);
	}

	public function lastPosition($Model, $clusterId = null) {
		$field = $this->settings[$Model->alias]['field'];

		$last = $Model->find('first', array(
			'fields' => array($field),
			'order' => array($field => 'DESC'),
			'conditions' => $this->_conditions($Model, $clusterId),
		));

		return (!empty($last)) ? $last[$Model->alias][$field] : 0;
	}

	public function findByPosition($Model, $position, $clusterId = null) {
		$field = $this->_fieldName($Model);

		return $Model->find('first', array('conditions' => $this->_conditions($Model, $clusterId, array($field => $position))));
	}

	protected function _fieldName($Model) {
		return $Model->alias.'.'.$this->settings[$Model->alias]['field'];
	}

	protected function _clusterId($Model, $id = null) {
		$cluster = $this->settings[$Model->alias]['cluster'];

		if (is_null($cluster) || ($cluster === false)) {
			return null;
		}

		if ($id) {
			$Model->id = $id;
		}
		$clusterId = $Model->field($cluster);

		if(!empty($Model->data[$Model->alias][$cluster])){
			$clusterId = $Model->data[$Model->alias][$cluster];
		}

		return $clusterId;
	}

	protected function _conditions($Model, $clusterId = null, $conditions = array()) {
		$cluster = $this->settings[$Model->alias]['cluster'];

		if (($cluster !== false) && !is_null($clusterId)) {
			$conditions = array_merge($conditions, array($cluster => $clusterId));
		}

		return $conditions;
	}
 }
