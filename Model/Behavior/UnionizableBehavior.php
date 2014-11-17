<?php
/**
 * Unionize
 *
 * Lets you splitup multiple condition fragments
 * into multiple find queries, merged via UNION
 */
class UnionizableException extends CakeException {}
class UnionizableBehavior extends ModelBehavior {

	/**
	 * default settings
	 *
	 * @var array
	 */
	public $__defaultSettings = array(
	);

	/**
	 * placeholder for settings
	 *
	 * @var array
	 */
	public $settings = array();

	/**
	 * placeholder for condition
	 *
	 * @var array
	 */
	public $conditions = array();
	public $sqlSelects = [];

	/**
	 * trickery to get CakePHP to use the "customFind" = "unionize"
	 *
	 * @var array
	 */
	public $mapMethods = array('/^_findUnionize$/' => '_findUnionize');

	/**
	 * Setup the model
	 *
	 * @param object Model $Model
	 * @param array $settings
	 * @return boolean
	 */
	public function setup(Model $Model, $settings = array()) {
		$this->settings[$Model->alias] = $settings + $this->__defaultSettings;
		$this->unionizeClearConditions($Model);
		$Model->findMethods['unionize'] = true;
		return true;
	}

	/**
	 * Gets all Unionizable configured condition fragments
	 * (these will create the various selects)
	 *
	 * @param Model $Model
	 * @return array $conditions
	 */
	public function unionizeGetConditions(Model $Model) {
		return $this->conditions[$Model->alias];
	}

	/**
	 * Add in a fragment for the union
	 *
	 * @param Model $Model
	 * @param array $condition
	 * @return boolean $success
	 */
	public function unionizeSetConditions(Model $Model, $condition) {
		$this->conditions[$Model->alias][] = $condition;
		return true;
	}

	/**
	 * Delete/Clear/Reset all fragments for the union
	 *
	 * @param Model $Model
	 * @return boolean $success
	 */
	public function unionizeClearConditions(Model $Model) {
		$this->conditions[$Model->alias] = [];
		return true;
	}

	/**
	 *
	 *
	 */
	public function _findUnionize(Model $Model, $method, $state, $query, $results = array()) {
		if ($state === 'before') {
			$conditions = $this->unionizeGetConditions($Model);
			if (empty($conditions)) {
				return $query;
			}
			if (!method_exists($Model, 'getQuery') && !is_callable(array($Model, 'getQuery'))) {
				throw new UnionizableException('The CakeDC Search Plugin is not setup on ' . $Model->alias);
			}
			$query = $Model->buildQuery('all', $query);
			$sql = $this->unionizeGetQuery($Model, 'all', $query);
			// TODO
			//   now how could I tell CakePHP to use my SQL vs. it's own from within a find()?
			//   I don't really think I can due to Cake's find() handling...
			//   Instead we've gotta do
			//     $Model->unionizeFind('count', $options);
			//     $Model->unionizeFind('all', $options);
			return [];
		}
		return $results;
	}

	/**
	 * Model->find() replacement for UNIONed queries
	 *
	 * Sometimes you can not simply use an OR due to performance reasons
	 * (whole table scanning, ALL type in explain, etc.)
	 *
	 * So you might need to break the query into multiple UNION based selects
	 *
	 * This is not something CakePHP supports.
	 *
	 * This function attempts to let you use the rest of your Cake
	 * functionality and only have to use the UNION when you need to.
	 *
	 * supported find types
	 *
	 * 'all'
	 * 'count' = 'count-real'
	 * 'count-real' = excludes duplicate records (so A + A + B = 2)
	 * 'count-fast' = included duplicate records (so A + A + B = 3)
	 *
	 * @param Model $Model
	 * @param string $type
	 * @param array $query options
	 * @return mixed $results
	 */
	public function unionizeFind(Model $Model, $type = 'count', $query) {
		$conditions = $this->unionizeGetConditions($Model);
		if (empty($conditions)) {
			return $query;
		}
		if (!method_exists($Model, 'getQuery') && !is_callable(array($Model, 'getQuery'))) {
			throw new UnionizableException('The CakeDC Search Plugin is not setup on ' . $Model->alias);
		}

		if ($type == 'count') {
			$type = 'count-real';
		}

		// count "real" will be a bit more work on the DB
		//   but it will not include duplicate counts
		//     results from the first UNION query will be omitted
		//     from all results from subsequent UNION results
		if ($type == 'count-real') {
			$type = 'all';
			$query['fields'] = $Model->primaryKey;
			$query = $Model->buildQuery($type, $query);
			$query['order'] = false;
			$query['limit'] = false;
			$sql = $this->unionizeGetQuery($Model, $type, $query);
			$sql = sprintf('SELECT COUNT(*) as count FROM (%s) %s',
				$sql,
				$Model->alias
			);
			try {
				$results = $Model->query($sql);
			} catch (Exception $e) {
				$error = $e->getMessage();
				debug(compact('query', 'sql', 'results', 'error'));
				throw new UnionizableException($e->getMessage());
			}
			// should be a "deeply nested" but single count
			//   already SUMMED accross all UNION queries
			$results = Hash::flatten($results);
			return array_sum($results);
		}

		// count "fast" will be a bit less work on the DB
		//   but it will include duplicate counts
		//     results from the first UNION query will be added
		//     to all results from subsequent UNION results
		if (strpos($type, 'count') !== false) {
			$type = 'count';
			$query['fields'] = $Model->primaryKey;
			$query = $Model->buildQuery($type, $query);
			$query['order'] = false;
			$query['limit'] = false;
			$sql = $this->unionizeGetQuery($Model, $type, $query);
			try {
				$results = $Model->query($sql);
			} catch (Exception $e) {
				$error = $e->getMessage();
				debug(compact('query', 'sql', 'results', 'error'));
				throw new UnionizableException($e->getMessage());
			}
			// should be a "deeply nested" but single count
			//   already SUMMED accross all UNION queries
			$results = Hash::flatten($results);
			return array_sum($results);
		}

		// find all
		//   it will not include duplicate counts
		//     results from the first UNION query will be omitted
		//     from all results from subsequent UNION results
		$query = $Model->buildQuery($type, $query);
		$sql = $this->unionizeGetQuery($Model, $type, $query);
		try {
			$results = $Model->query($sql);
		} catch (Exception $e) {
			$error = $e->getMessage();
			debug(compact('query', 'sql', 'results', 'error'));
			throw new UnionizableException($e->getMessage());
		}
		foreach (array_keys($results) as $i) {
			$results[$i] = $this->unionizeFindOrgnaizeResult($Model, $results[$i][0]);
		}
		//debug(compact('sql', 'results'));
		return $results;
	}

	/**
	 * Get the field names from the UNIONed SQL statements
	 * and assign values into those fieldnames
	 * so that Cake gets it's expected results back
	 *
	 * Also, Results come back without being "cast" into CakePHP types.
	 *
	 * @param Model $Model
	 * @param array $node [field=>value,...] (no conainer)
	 * @return array $node [$alias => [field=>value, ...], ...]
	 */
	public function unionizeFindOrgnaizeResult(Model $Model, $node) {
		// get the name of all fields, from the first sqlSelects statement
		$fields = [];
		if (!empty($this->sqlSelects)) {
			$sql = $this->sqlSelects[0];
			$sql = substr($sql, 0, strpos($sql, 'FROM'));
			$sql = trim(str_replace(['SELECT', ' ', '`'], '', $sql));
			$fields = explode(',', $sql);
		}

		if (count($fields) == count($node)) {
			$output = [];
			foreach (array_combine($fields, $node) as $field => $value) {
				// cleanup field type to CakePHP conventions
				$type = $Model->getColumnType($field);
				if ($type == 'boolean') {
					$value = (bool)$value;
				} elseif ($type == 'integer') {
					$value = (int)$value;
				} elseif ($type == 'float') {
					$value = (float)$value;
				}
				// organize alias=>field based on CakePHP conventions
				$output = Hash::insert($output, $field, $value);
			}
			return $output;
		}

		// fail, couldn't get the fields to match
		//   just dumping all into the model's alias
		return [$Model->alias => $node];
	}

	/**
	 * Convert a $query array into a $sql string Unionized
	 *
	 * @param Model $Model
	 * @param string $type [count, all]
	 * @param array $array
	 * @return string $sql
	 */
	public function unionizeGetQuery(Model $Model, $type = 'count', $query) {
		$conditions = $this->unionizeGetConditions($Model);
		if (empty($conditions)) {
			throw new UnionizableException("The UnionizableBehavior requires you to have added at least one condition fragment via {$Model->alias}->unionizeSetConditions()");
		}
		if (!method_exists($Model, 'getQuery') && !is_callable(array($Model, 'getQuery'))) {
			throw new UnionizableException('The CakeDC Search Plugin is not setup on ' . $Model->alias);
		}

		$this->sqlSelects = [];
		$queryOrig = $query;
		foreach ($conditions as $condition) {
			$queryNode = $queryOrig;
			if (!empty($queryNode['conditions'])) {
				$queryNode['conditions'] = array_merge((array)$queryNode['conditions'], $condition);
			} else {
				$queryNode['conditions'] = $condition;
			}
			$queryNode['order'] = false;
			$queryNode['limit'] = null;
			$queryNode['page'] = 1;
			// now convert to SQL SELECT
			$this->sqlSelects[] = $Model->getQuery($type, $queryNode);
		}

		if ($type == 'count') {
			// for COUNT
			//   we want the SUM of each part of our query
			//   http://stackoverflow.com/questions/14798876/sum-count-with-union-in-mysql
			//     NOTE: the `count` field is handled in the getQuery() type=count
			$sql = sprintf("SELECT sum(count) FROM ((\n\n%s\n\n)) %s",
				implode("\n\n) UNION (\n\n", $this->sqlSelects),
				$Model->alias
			);
			return $sql;
		}

		// one last time to get the order by and limit details
		$orderLimit = '';
		if (!empty($query['order'])) {
			$orderLimit .= ' ' . $Model->getDataSource()->order($query['order'], 'ASC', $Model);
		}
		if (!empty($query['limit'])) {
			if (!isset($query['page'])) {
				$query['page'] = 1;
			}
			$orderLimit .= ' ' . $Model->getDataSource()->limit($query['limit'],  ($query['page'] - 1) * $query['limit']);
		}

		// remove the alias
		// (this is a poor implementation)
		//   Unions can not support table.field nor table.alias syntax
		//   http://dev.mysql.com/doc/refman/5.0/en/union.html
		$orderLimit = preg_replace("#`{$Model->alias}`\.#", '', $orderLimit);

		// put together into "final" SQL
		$sql = sprintf("(\n\n%s\n\n) %s",
			implode("\n\n) UNION (\n\n", $this->sqlSelects),
			$orderLimit
		);
		return $sql;
	}

}
