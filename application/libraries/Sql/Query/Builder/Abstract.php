<?php

	/**
	 * Query builder interface.
	 *
	 * @package Sql::Query::Builder
	 * @author James Stephenson
	 **/
	abstract class Sql_Query_Builder_Abstract
	{
		private $_data;
		
		private $_depth;
		private $_index;
		
		/**
		 * Constructor.
		 *
		 * @author James Stephenson
		 **/
		public function __construct()
		{
			$this->_data = array();
						
			$this->setFields(array());
			$this->setEntity('');
			$this->setConditions('');
			$this->setBinds(array(null));
			$this->setSorts(array());
			$this->setOffet(null);
			$this->setLimit(null);
			
			$this->_depth = 0;
			$this->_index = '';
		}
		
		/**
		 * Perform a select query for the specified fields, from the specified
		 * entity.
		 *
		 * @return $this Return self for chaining.
		 * @param array $fields
		 * @param string|Sql_Query_Builder_I
		 * @author James Stephenson
		 **/
		public function select($fields, $entities)
		{
			if (!is_array($fields)) {
				$fields = array($fields);
			}
			$this->setFields($fields);
			
			if (!is_array($entities)) {
				$entities = array($entities);
			}
			
			$entities = array_values($entities);
			foreach ($entities as $index => $entity) {
				if ($entity instanceof Sql_Query_Builder_Abstract) {
					$entity->setDepth($this->getDepth() + 1);
					$entity->setIndex($entity->getDepth() . '_' . $index);
				}
			}
			$this->setSources($entities);
			
			return $this;
		}
		
		/**
		 * Apply the specified where conditions to the query. Supply an array
		 * of values to be bound to the parameterised string.
		 *
		 * @return $this Return self for chaining.
		 * @param string $conditions
		 * @param array $values
		 * @author James Stephenson
		 **/
		public function where($conditions, array $values = array())
		{
			if ($this->getConditions() == '') {
				$this->setConditions($conditions);
			} else {
				$this->setConditions($this->getConditions() . ' AND ' . $conditions);
			}
			
			$this->setBinds($this->getBinds() + $values);
			
			return $this;
		}
		
		/**
		 * Apply the specified order to the query. Supply an associative array
		 * of field names to sort orders.
		 *
		 * @return $this Return self for chaining.
		 * @param array $orders
		 * @author James Stephenson
		 **/
		public function order(array $orders)
		{
			$this->setSorts($orders);
			
			return $this;
		}
		
		/**
		 * Limit the result set, starting at specified offset and returning
		 * a partition of the specified size.
		 *
		 * @return $this Return self for chaining.
		 * @param int $offset
		 * @param int $partition_size
		 * @author James Stephenson
		 **/
		public function limit($offset, $partition_size)
		{
			$this->setOffset($offset);
			$this->setLimit($partition_size);
			
			return $this;
		}
		
		/**
		 * Render the query.
		 *
		 * @return string The rendered query.
		 * @author James Stephenson
		 **/
		abstract public function build();
		
		/**
		 * Magic method to provide get/set/has for parameter fields.
		 *
		 * @return void|boolean|mixed
		 * @author James Stephenson
		 **/
		public function __call($method, array $args)
		{
			$intent = substr($method, 0, 3);
			$param = strtolower(substr($method, 3));
			switch ($intent) {
				case 'has':
					return !empty($this->_data[$param]);
					break;
				case 'get':
					return $this->_data[$param];
					break;
				case 'set':
					if (sizeof($args) == 1) {
						$this->_data[$param] = $args[0];
					} else {
						throw new Exception('Too few arguments for method: ' . $method);
					}
					break;
				default:
					throw new Exception('Call to unknown method: ' . $method);
			}
		}
		
		public function setIndex($index)
		{
			$this->_index = $index;
		}
		
		public function getIndex()
		{
			return $this->_index;
		}
		
		public function getOrder()
		{
			$sorts = array();
			foreach ($this->getSorts() as $field => $sort) {
				$sorts[] = $field . ' ' . $sort;
			}
			
			return implode(', ', $sorts);
		}
		
		public function addBinds(array $binds)
		{
			$this->setBinds($this->getBinds() + $binds);
		}
		
		public function getDepth()
		{
			return $this->_depth;
		}
		
		public function setDepth($depth)
		{
			$this->_depth = $depth;
		}
		
		public function setSources($sources)
		{
			if (!is_array($sources)) {
				$sources = array($sources);
			}
			
			$this->_data['sources'] = $sources;
		}
		
		public function setFields($fields)
		{
			if (!is_array($fields)) {
				$fields = array($fields);
			}
			
			$this->_data['fields'] = $fields;
		}
		
		public function getOrderBy()
		{
			$orders = array();
			foreach ($this->getSorts() as $key => $value) {
				$orders[] = $key . ' ' . $value;
			}
			return implode(', ', $orders);
		}
		
		public function getSql()
		{
			$this->_ensureBuilt();
			
			return $this->_data['sql'];
		}
		
		public function getBoundParams()
		{
			$this->_ensureBuilt();
			
			$bound = $this->getBinds();
			array_unshift($bound, null);
			unset($bound[0]);
			
			return $bound;
		}
		
		protected function _ensureBuilt()
		{
			if (!$this->hasSql()) {
				$this->build();
			}
		}
		
		protected function _resolveSources()
		{
			$sources = array();
			foreach ($this->getSources() as $index => $source) {
				if ($source instanceof Sql_Query_Builder_Abstract) {
					$q = $source->build();
					$sources[$index] = '(' . $q[0] . ') AS [subquery_' . $q[2] . ']';
					foreach ($this->getBinds() as $bind) {
						$q[1][] = $bind;
					}
					$this->setBinds($q[1]);
				} else {
					$sources[$index] = $source;
				}
			}
			$this->setSources($sources);
		}
		
		protected function _resolveTokens($string)
		{
			foreach ($this->_replacements as $find => $replace) {
				$string = preg_replace('/([\s]+)'.$find.'([\s]+|\()/', '$1'.$replace.'$2', $string);
			}
			
			$string = preg_replace('/`([[:alnum:]]*)?`/', '[$1]', $string);

			return $string;
		}
	} // END interface Sql_Query_Builder_I