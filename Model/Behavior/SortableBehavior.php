<?php
/**
 * Class: SortableBehavior
 * Plugin: Order
 * Author: Ben McClure
 * Based on: http://bakery.cakephp.org/articles/dardosordi/2008/07/29/sortablebehavior-sort-your-models-arbitrarily
 */
 class SortableBehavior extends ModelBehavior {
 	public function setup($Model, $settings = array()) {
 		if (!isset($this->settings[$Model->alias])) {
			$this->settings[$Model->alias] = array('field' => 'order', 'enabled' => true, 'cluster' => false);
		}

		$this->settings[$Model->alias] = array_merge($this->settings[$Model->alias], (array) $settings);
 	}

	public function beforeFind($Model, $query) {
		extract($this->settings[$Model->alias]);

		if (!$enabled) {
			return $query;
		}

		if (is_string($query['fields']) && strpos($query['fields'], 'COUNT') === 0) {
			return $query;
		}

		$order = (is_array($query['order'])) ? current($query['order']) : $query['order'];

		if (empty($order)) {
			$query['order'] = array(array($this->_fieldName($Model) => 'ASC'));

			if ($group !== false) {
				$query['order'] = array_merge(array($group => 'ASC'), $query['order']);
			}
		}

		return $query;
	}

	public function beforeSave($Model) {
		extract($this->settings[$Model->alias]);

		if (!$enabled) {
			return true;
		}

		$fixPosition = false;
		$isInsert = !$Model->id;
		$newPosition = (isset($Model->data[$Model->alias][$field]) && !empty($Model->data[$Model->alias][$field])) ? $Model->data[$Model->alias][$field] : null;
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
		return $this->setPosition($Model, $id, 1);
	}

	public function moveUp($Model, $id = null, $step = 1) {
		return $this->setPosition($Model, $id, $this->position($Model, $id) - $step);
	}

	public function moveDown($Model, $id = null, $step = 1) {
		return $this->setPosition($Model, $id, $this->position($Model, $id) + $step);
	}

	public function moveBottom($Model, $id = null) {
		return $this->setPosition($Model, $id, $this->lastPosition($Model, $this->_clusterId($Model, $id)));
	}

	public function setPosition($Model, $id = null, $destination = 1) {
		$this->settings[$Model->alias]['enabled'] = false;

		extract($this->settings[$Model->alias]);

		if ($id) {
			$Model->id = $id;
		}

		$position = $this->position($Model);

		$id = $Model->id;
		$Model->id = null;

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

			$query = $this->_query($Model, $clusterId, array("$fieldName $operator1" => $position, "$fieldName $operator2" => $destination));

			$Model->updateAll(array($fieldName => "$fieldName $updateValue 1"), $query);
			$Model->saveField($field, $destination);
		}

		$this->settings[$Model->alias]['enabled'] = true;

		return true;
	}

	public function position($Model, $id = null) {
		if ($id) {
			$Model->id = $id;
		}

		return $Model->field($this->settings[$Model->alias]['field']);
	}

	public function lastPosition($Model, $clusterId = null) {
		$id = $Model->id;

		$Model->id = null;

		$field = $this->settings[$Model->alias]['field'];
		$fields = array($field);
 		$order = array($field => 'DESC');
		$query = $this->_query($Model, $clusterId);
		$last = $Model->find('first',  compact('fields', 'order', 'conditions'));

		$Model->id = $id;

		return (!empty($last)) ? current(current($last)) : false;
	}

	protected function _fieldName($Model) {
		return $Model->alias.'.'.$this->settings[$Model->alias]['field'];
	}

	protected function _clusterId(&$model, $id = null) {
		$cluster = $this->settings[$Model->alias]['cluster'];

		if ($cluster === false) {
			return null;
		}

		if ($id) {
			$Model->id = $id;
		}

		return $Model->field($cluster);
	}

	function _query($Model, $clusterId = null, $query = array()) {
		$cluster = $this->settings[$Model->alias]['cluster'];

		if (($cluster !== false) && !is_null($clusterId)) {
			$query = array_merge($query, array($cluster => $clusterId));
		}

		return $query;
	}

	public function findByPosition($Model, $position, $clusterId = null) {
		$field = $this->_fieldName($Model);

		return $Model->find($this->_query($Model, $clusterId, array($field => $position)));
	}
 }
