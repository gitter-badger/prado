<?php
/**
 * TMappedStatement and related classes.
 *
 * @author Wei Zhuo <weizhuo[at]gmail[dot]com>
 * @link http://www.pradosoft.com/
 * @copyright Copyright &copy; 2005 PradoSoft
 * @license http://www.pradosoft.com/license/
 * @version $Id$
 * @package System.DataAccess.SQLMap.Statements
 */

/**
 * TMappedStatement class executes SQL mapped statements. Mapped Statements can 
 * hold any SQL statement and use Parameter Maps and Result Maps for input and output.
 *
 * This class is usualy instantiated during SQLMap configuration by TSqlDomBuilder.
 *
 * @author Wei Zhuo <weizhuo[at]gmail[dot]com>
 * @version $Id$
 * @package System.DataAccess.SQLMap.Statements
 * @since 3.0
 */
class TMappedStatement extends TComponent implements IMappedStatement
{
	/**
	 * @var TSqlMapStatement current SQL statement.
	 */
	private $_statement;

	/**
	 * @var TPreparedCommand SQL command prepareer
	 */
	private $_command;

	/**
	 * @var TSqlMapper sqlmap used by this mapper.
	 */
	private $_sqlMap;

	/**
	 * @var TPostSelectBinding[] post select statement queue.
	 */
	private $_selectQueque=array();

	/**
	 * @var boolean true when data is mapped to a particular row.
	 */
	private $_IsRowDataFound = false;

	/**
	 * @var TSQLMapObjectCollectionTree group by object collection tree
	 */
	private $_groupBy;

	/**
	 * @var Post select is to query for list.
	 */
	const QUERY_FOR_LIST = 0;

	/**
	 * @var Post select is to query for list.
	 */
	const QUERY_FOR_ARRAY = 1;
	
	/**
	 * @var Post select is to query for object.
	 */
	const QUERY_FOR_OBJECT = 2;
	
	/**
	 * @return string Name used to identify the TMappedStatement amongst the others.
	 * This the name of the SQL statement by default.
	 */
	public function getID()
	{
		return $this->_statement->ID;
	}

	/**
	 * @return TSqlMapStatement The SQL statment used by this MappedStatement
	 */
	public function getStatement()
	{
		return $this->_statement;
	}

	/**
	 * @return TSqlMapper The SqlMap used by this MappedStatement
	 */
	public function getSqlMap()
	{
		return $this->_sqlMap;
	}

	/**
	 * @return TPreparedCommand command to prepare SQL statements.
	 */
	public function getCommand()
	{
		return $this->_command;
	}

	/**
	 * Empty the group by results cache.
	 */
	protected function initialGroupByResults()
	{
		$this->_groupBy = new TSQLMapObjectCollectionTree();
	}

	/**
	 * Creates a new mapped statement.
	 * @param TSqlMapper an sqlmap.
	 * @param TSqlMapStatement An SQL statement.
	 */
	public function __construct(TSqlMapper $sqlMap, TSqlMapStatement $statement)
	{
		$this->_sqlMap = $sqlMap;
		$this->_statement = $statement;
		$this->_command = new TPreparedCommand();
		$this->initialGroupByResults();
	}

	/**
	 * Execute SQL Query.
	 * @param IDbConnection database connection
	 * @param array SQL statement and parameters.
	 * @return mixed record set if applicable.
	 * @throws TSqlMapExecutionException if execution error or false record set.
	 * @throws TSqlMapQueryExecutionException if any execution error
	 */
	protected function executeSQLQuery($connection, $sql)
	{
		try
		{
			if(!($recordSet = $connection->execute($sql['sql'],$sql['parameters'])))
			{
				throw new TSqlMapExecutionException(
					'sqlmap_execution_error_no_record', $this->getID(), 
					$connection->ErrorMsg());
			}
			return $recordSet;
		}
		catch (Exception $e)
		{
			throw new TSqlMapQueryExecutionException($this->getStatement(), $e);
		}
	}

	/**
	 * Execute SQL Query with limits.
	 * @param IDbConnection database connection
	 * @param array SQL statement and parameters.
	 * @return mixed record set if applicable.
	 * @throws TSqlMapExecutionException if execution error or false record set.
	 * @throws TSqlMapQueryExecutionException if any execution error
	 */
	protected function executeSQLQueryLimit($connection, $sql, $max, $skip)
	{
		try
		{
			$recordSet = $connection->selectLimit($sql['sql'],$max,$skip,$sql['parameters']);
			if(!$recordSet)
			{
				throw new TSqlMapExecutionException(
							'sqlmap_execution_error_query_for_list', 
							$connection->ErrorMsg());
			}
			return $recordSet;
		}
		catch (Exception $e)
		{
			throw new TSqlMapQueryExecutionException($this->getStatement(), $e);
		}
	}

	/**
	 * Executes the SQL and retuns a List of result objects.
	 * @param IDbConnection database connection
	 * @param mixed The object used to set the parameters in the SQL.
	 * @param object result collection object.
	 * @param integer The number of rows to skip over.
	 * @param integer The maximum number of rows to return.
	 * @return array a list of result objects
	 * @param callback row delegate handler
	 * @see executeQueryForList()
	 */
	public function executeQueryForList($connection, $parameter, $result, $skip=-1, $max=-1, $delegate=null)
	{
		$sql = $this->_command->create($connection, $this->_statement, $parameter);
		return $this->runQueryForList($connection, $parameter, $sql, $result, $skip, $max, $delegate);
	}

	/**
	 * Executes the SQL and retuns a List of result objects.
	 *
	 * This method should only be called by internal developers, consider using
	 * <tt>executeQueryForList()</tt> first.
	 *
	 * @param IDbConnection database connection
	 * @param mixed The object used to set the parameters in the SQL.
	 * @param array SQL string and subsititution parameters.
	 * @param object result collection object.
	 * @param integer The number of rows to skip over.
	 * @param integer The maximum number of rows to return.
	 * @param callback row delegate handler
	 * @return array a list of result objects
	 * @see executeQueryForList()
	 */
	public function runQueryForList($connection, $parameter, $sql, $result, $skip=-1, $max=-1, $delegate=null)
	{
		$list = $result instanceof ArrayAccess ? $result : 
							$this->_statement->createInstanceOfListClass();
		$recordSet = $this->executeSQLQueryLimit($connection, $sql, $max, $skip);
		if(!is_null($delegate))
		{
			while($row = $recordSet->fetchRow())
			{
				$obj = $this->applyResultMap($row);
				$param = new TResultSetListItemParameter($obj, $parameter, $list);
				$this->raiseRowDelegate($delegate, $param);
			}			
		}
		else
		{
			while($row = $recordSet->fetchRow())
				$list[] = $this->applyResultMap($row);
		}

		if(!$this->_groupBy->isEmpty())
		{
			$list = $this->_groupBy->collect();
			$this->initialGroupByResults();
		}	

		$this->executePostSelect($connection);
		$this->onExecuteQuery($sql);

		return $list;
	}

	/**
	 * Executes the SQL and retuns all rows selected in a map that is keyed on 
	 * the property named in the keyProperty parameter.  The value at each key 
	 * will be the value of the property specified in the valueProperty parameter.  
	 * If valueProperty is null, the entire result object will be entered.
	 * @param IDbConnection database connection
	 * @param mixed The object used to set the parameters in the SQL. 
	 * @param string The property of the result object to be used as the key.
	 * @param string The property of the result object to be used as the value (or null).
	 * @param callback row delegate handler
	 * @return array An array of object containing the rows keyed by keyProperty.
	 */
	public function executeQueryForMap($connection, $parameter, $keyProperty, $valueProperty=null, $delegate=null)
	{
		$sql = $this->_command->create($connection, $this->_statement, $parameter);
		return $this->runQueryForMap($connection, $parameter, $sql, $keyProperty, $valueProperty, $delegate);
	}

	/**
	 * Executes the SQL and retuns all rows selected in a map that is keyed on 
	 * the property named in the keyProperty parameter.  The value at each key 
	 * will be the value of the property specified in the valueProperty parameter.  
	 * If valueProperty is null, the entire result object will be entered.
	 *
	 * This method should only be called by internal developers, consider using
	 * <tt>executeQueryForMap()</tt> first.
	 *
	 * @param IDbConnection database connection
	 * @param mixed The object used to set the parameters in the SQL.
	 * @param array SQL string and subsititution parameters.
	 * @param string The property of the result object to be used as the key.
	 * @param string The property of the result object to be used as the value (or null).
	 * @param callback row delegate, a callback function
	 * @return array An array of object containing the rows keyed by keyProperty.
	 * @see executeQueryForMap()
	 */
	public function runQueryForMap($connection, $parameter, $sql, $keyProperty, $valueProperty=null, $delegate=null)
	{
		$map = array();
		$recordSet = $this->executeSQLQuery($connection, $sql);
		if(!is_null($delegate))
		{
			while($row = $recordSet->fetchRow())
			{
				$obj = $this->applyResultMap($row);
				$key = TPropertyAccess::get($obj, $keyProperty);
				$value = is_null($valueProperty) ? $obj : 
							TPropertyAccess::get($obj, $valueProperty);
				$param = new TResultSetMapItemParameter($key, $value, $parameter, $map);
				$this->raiseRowDelegate($delegate, $param);
			}
		}
		else
		{
			while($row = $recordSet->fetchRow())
			{
				$obj = $this->applyResultMap($row);
				$key = TPropertyAccess::get($obj, $keyProperty);
				$map[$key] = is_null($valueProperty) ? $obj : 
								TPropertyAccess::get($obj, $valueProperty);
			}
		}
		$this->onExecuteQuery($sql);
		return $map;
	}

	/**
	 * Raises delegate handler.
	 * This method is invoked for each new list item. It is the responsibility
	 * of the handler to add the item to the list. 
	 * @param object event parameter
	 */
	protected function raiseRowDelegate($handler, $param)
	{
		if(is_string($handler))
		{
			call_user_func($handler,$this,$param);
		}
		else if(is_callable($handler,true))
		{
			// an array: 0 - object, 1 - method name/path
			list($object,$method)=$handler;
			if(is_string($object))	// static method call
				call_user_func($handler,$this,$param);
			else
			{
				if(($pos=strrpos($method,'.'))!==false)
				{
					$object=$this->getSubProperty(substr($method,0,$pos));
					$method=substr($method,$pos+1);
				}
				$object->$method($this,$param);
			}
		}
		else
			throw new TInvalidDataValueException('sqlmap_invalid_delegate', $this->getID(), $handler);
	}

	/**
	 * Executes an SQL statement that returns a single row as an object of the 
	 * type of the <tt>$result</tt> passed in as a parameter.	 
	 * @param IDbConnection database connection
	 * @param mixed The parameter data (object, arrary, primitive) used to set the parameters in the SQL
	 * @param mixed The result object.
	 * @return ${return}
	 */
	public function executeQueryForObject($connection, $parameter, $result)
	{
		$sql = $this->_command->create($connection, $this->_statement, $parameter);
		return $this->runQueryForObject($connection, $sql, $result);
	}

	/**
	 * Executes an SQL statement that returns a single row as an object of the 
	 * type of the <tt>$result</tt> passed in as a parameter.
	 *
	 * This method should only be called by internal developers, consider using
	 * <tt>executeQueryForObject()</tt> first.
	 *
	 * @param IDbConnection database connection
	 * @param array SQL string and subsititution parameters.
	 * @param object The result object.
	 * @return object the object.
	 * @see executeQueryForObject()
	 */
	public function runQueryForObject($connection, $sql, &$result)
	{
		$recordSet = $this->executeSQLQuery($connection, $sql);
		
		$object = null;
		
		while($row = $recordSet->fetchRow())
			$object = $this->applyResultMap($row, $result);	

		if(!$this->_groupBy->isEmpty())
		{
			$list = $this->_groupBy->collect();
			$this->initialGroupByResults();
			$object = $list[0];
		}	

		$this->executePostSelect($connection);
		$this->onExecuteQuery($sql);
		
		return $object;
	}

	/**
	 * Execute an insert statement. Fill the parameter object with the ouput 
	 * parameters if any, also could return the insert generated key.
	 * @param IDbConnection database connection
	 * @param mixed The parameter object used to fill the statement.
	 * @return string the insert generated key.
	 */
	public function executeInsert($connection, $parameter)
	{
		$generatedKey = $this->getPreGeneratedSelectKey($connection, $parameter);

		$sql = $this->_command->create($connection, $this->_statement, $parameter);

		$result = $this->executeSQLQuery($connection, $sql);

		if(is_null($generatedKey))
			$generatedKey = $this->getPostGeneratedSelectKey($connection, $parameter);

		$this->executePostSelect($connection);
		$this->onExecuteQuery($sql);
		return $generatedKey;
	}

	/**
	 * Gets the insert generated ID before executing an insert statement.
	 * @param IDbConnection database connection
	 * @param mixed insert statement parameter.
	 * @return string new insert ID if pre-select key statement was executed, null otherwise.
	 */
	protected function getPreGeneratedSelectKey($connection, $parameter)
	{
		if($this->_statement instanceof TSqlMapInsert)
		{
			$selectKey = $this->_statement->getSelectKey();
			if(!is_null($selectKey) && !$selectKey->getIsAfter())
				return $this->executeSelectKey($connection, $parameter, $selectKey);
		}
	}

	/**
	 * Gets the inserted row ID after executing an insert statement.
	 * @param IDbConnection database connection
	 * @param mixed insert statement parameter.
	 * @return string last insert ID, null otherwise.
	 */
	protected function getPostGeneratedSelectKey($connection, $parameter)
	{
		if($this->_statement instanceof TSqlMapInsert)
		{
			$selectKey = $this->_statement->getSelectKey();
			if(!is_null($selectKey) && $selectKey->getIsAfter())
				return $this->executeSelectKey($connection, $parameter, $selectKey);
		}
	}
	
	/**
	 * Execute the select key statement, used to obtain last insert ID.
	 * @param IDbConnection database connection
	 * @param mixed insert statement parameter
	 * @param TSqlMapSelectKey select key statement
	 * @return string last insert ID.
	 */
	protected function executeSelectKey($connection, $parameter, $selectKey)
	{
		$mappedStatement = $this->sqlMap->getMappedStatement($selectKey->getID());
		$generatedKey = $mappedStatement->executeQueryForObject(
									$connection, $parameter, null);
		if(strlen($prop = $selectKey->getProperty()) > 0)
				TPropertyAccess::set($parameter, $prop, $generatedKey);
		return $generatedKey;
	}

	/**
	 * Execute an update statement. Also used for delete statement. 
	 * Return the number of rows effected.
	 * @param IDbConnection database connection
	 * @param mixed The object used to set the parameters in the SQL.
	 * @return integer The number of rows effected.
	 */
	public function executeUpdate($connection, $parameter)
	{
		$sql = $this->_command->create($connection, $this->_statement, $parameter);
		$this->executeSQLQuery($connection, $sql);
		$this->executePostSelect($connection);
		$this->onExecuteQuery($sql);
		return $connection->Affected_Rows();
	}
	
	/**
	 * Process 'select' result properties
	 * @param IDbConnection database connection
	 */
	protected function executePostSelect($connection)
	{

		while(count($this->_selectQueque))
		{
			$postSelect = array_shift($this->_selectQueque);
			$method = $postSelect->getMethod();
			$statement = $postSelect->getStatement();
			$property = $postSelect->getResultProperty()->getProperty();
			$keys = $postSelect->getKeys();
			$resultObject = $postSelect->getResultObject();

			if($method == self::QUERY_FOR_LIST || $method == self::QUERY_FOR_ARRAY)
			{
				$values = $statement->executeQueryForList($connection, $keys, null);
				
				if($method == self::QUERY_FOR_ARRAY) 
					$values = $values->toArray();
				TPropertyAccess::set($resultObject, $property, $values);
			}
			else if($method == self::QUERY_FOR_OBJECT)
			{
				$value = $statement->executeQueryForObject($connection, $keys, null);
				TPropertyAccess::set($resultObject, $property, $value);
			}
		}
	}
	
	/**
	 * Raise the execute query event.
	 * @param array prepared SQL statement and subsititution parameters
	 */
	public function onExecuteQuery($sql)
	{
		$this->raiseEvent('OnExecuteQuery', $this, $sql);
	}

	/**
	 * Apply result mapping.
	 * @param array a result set row retrieved from the database
	 * @param object the result object, will create if necessary.
	 * @return object the result filled with data, null if not filled.
	 */
	protected function applyResultMap($row, &$resultObject=null)
	{
		if($row === false) return null;

		$resultMapName = $this->_statement->getResultMap();
		$resultClass = $this->_statement->getResultClass();

		if($this->_sqlMap->getResultMaps()->contains($resultMapName))
			return $this->fillResultMap($resultMapName, $row, null, $resultObject);
		else if(strlen($resultClass) > 0)
			return $this->fillResultClass($resultClass, $row, $resultObject);
		else
			return $this->fillDefaultResultMap(null, $row, $resultObject);
	}

	/**
	 * Fill the result using ResultClass, will creates new result object if required.
	 * @param string result object class name
	 * @param array a result set row retrieved from the database
	 * @param object the result object, will create if necessary.
	 * @return object result object filled with data
	 */
	protected function fillResultClass($resultClass, $row, $resultObject)
	{
		if(is_null($resultObject))
			$resultObject = $this->_statement->createInstanceOfResultClass($row);

		if($resultObject instanceOf ArrayAccess)
			return $this->fillResultArrayList($row, $resultObject);
		else if(is_object($resultObject))
			return $this->fillResultObjectProperty($row, $resultObject);
		else
			return $this->fillDefaultResultMap(null, $row, $resultObject);
	}

	/**
	 * Apply the result to a TList or an array.
	 * @param array a result set row retrieved from the database
	 * @param object result object, array or list
	 * @return object result filled with data.
	 */
	protected function fillResultArrayList($row, $resultObject)
	{
		if($resultObject instanceof TList)
			foreach($row as $v)
				$resultObject[] = $v;
		else
			foreach($row as $k => $v)
				$resultObject[$k] = $v;
		return $resultObject;
	}

	/**
	 * Apply the result to an object.
	 * @param array a result set row retrieved from the database
	 * @param object result object, array or list
	 * @return object result filled with data.
	 */
	protected function fillResultObjectProperty($row, $resultObject)
	{
		$index = 0;
		foreach($row as $k=>$v)
		{
			$property = new TResultProperty;
			$property->initialize($this->_sqlMap);
			if(is_string($k) && strlen($k) > 0)
				$property->setColumn($k);
			$property->setColumnIndex(++$index);
			$type = gettype(TPropertyAccess::get($resultObject,$k));
			$property->setType($type);
			$value = $property->getDatabaseValue($row);	
			TPropertyAccess::set($resultObject, $k,$value);
		}
		return $resultObject;
	}

	/**
	 * Fills the result object according to result mappings.
	 * @param string result map name.
	 * @param array a result set row retrieved from the database
	 * @param object result object to fill, will create new instances if required.
	 * @return object result object filled with data.
	 */
	protected function fillResultMap($resultMapName, $row, $parentGroup=null, &$resultObject=null)
	{
		$resultMap = $this->_sqlMap->getResultMap($resultMapName);
		$resultMap = $resultMap->resolveSubMap($row);

		if(is_null($resultObject))
			$resultObject = $resultMap->createInstanceOfResult();
		if(is_object($resultObject))
		{
			if(strlen($resultMap->getGroupBy()) > 0)
				return $this->addResultMapGroupBy($resultMap, $row, $parentGroup, $resultObject);
			else
				foreach($resultMap->getColumns() as $property)
					$this->setObjectProperty($resultMap, $property, $row, $resultObject);
		}
		else
		{
			$resultObject = $this->fillDefaultResultMap($resultMap, $row, $resultObject);
		}
		return $resultObject;
	}

	/**
	 * ResultMap with GroupBy property. Save object collection graph in a tree
	 * and collect the result later.
	 * @param TResultMap result mapping details.
	 * @param array a result set row retrieved from the database
	 * @param object the result object
	 * @return object result object.
	 */
	protected function addResultMapGroupBy($resultMap, $row, $parent, &$resultObject)
	{
		$group = $this->getResultMapGroupKey($resultMap, $row);

		if(empty($parent))
		{
			$rootObject = array('object'=>$resultObject, 'property' => null); 
			$this->_groupBy->add(null, $group, $rootObject);
		}
				
		foreach($resultMap->getColumns() as $property)
		{
			//set properties.
			$this->setObjectProperty($resultMap, $property, $row, $resultObject);
			$nested = $property->getResultMapping();
			
			//nested property
			if($this->_sqlMap->getResultMaps()->contains($nested))
			{
				$nestedMap = $this->_sqlMap->getResultMap($nested);
				$groupKey = $this->getResultMapGroupKey($nestedMap, $row);
				
				//add the node reference first
				if(empty($parent))
					$this->_groupBy->add($group, $groupKey, '');
					
				//get the nested result mapping value
				$value = $this->fillResultMap($nested, $row, $groupKey);

				//add it to the object tree graph
				$groupObject = array('object'=>$value, 'property' => $property->getProperty());
				if(empty($parent))
					$this->_groupBy->add($group, $groupKey, $groupObject);
				else
					$this->_groupBy->add($parent, $groupKey, $groupObject);
			}
		}
		return $resultObject;
	}
	
	/**
	 * Gets the result 'group by' groupping key for each row.
	 * @param TResultMap result mapping details.
	 * @param array a result set row retrieved from the database
	 * @return string groupping key.
	 */
	protected function getResultMapGroupKey($resultMap, $row)
	{
		$groupBy = $resultMap->getGroupBy();
		if(isset($row[$groupBy]))
			return $resultMap->getID().$row[$groupBy];
		else
			return $resultMap->getID().crc32(serialize($row));
	}

	/**
	 * Fill the result map using default settings. If <tt>$resultMap</tt> is null
	 * the result object returned will be guessed from <tt>$resultObject</tt>.
	 * @param TResultMap result mapping details.
	 * @param array a result set row retrieved from the database
	 * @param object the result object
	 * @return mixed the result object filled with data.
	 */
	protected function fillDefaultResultMap($resultMap, $row, $resultObject)
	{
		if(is_null($resultObject)) 
			$resultObject='';

		if(!is_null($resultMap))
			$result = $this->fillArrayResultMap($resultMap, $row, $resultObject);
		else
			$result = $row;
				
		//if scalar result types
		if(count($result) == 1 && ($type = gettype($resultObject))!= 'array')
			return $this->getScalarResult($result, $type);
		else
			return $result;
	}
	
	/**
	 * Retrieve the result map as an array.
	 * @param TResultMap result mapping details.
	 * @param array a result set row retrieved from the database
	 * @param object the result object
	 * @return array array list of result objects.
	 */
	protected function fillArrayResultMap($resultMap, $row, $resultObject)
	{
		$result = array();
		foreach($resultMap->getColumns() as $column)
		{
			if(is_null($column->getType()) 
				&& !is_null($resultObject) && !is_object($resultObject))
			$column->setType(gettype($resultObject));
			$result[$column->getProperty()] = $column->getDatabaseValue($row);
		}
		return $result;
	}

	/**
	 * Converts the first array value to scalar value of given type.
	 * @param array list of results
	 * @param string scalar type.
	 * @return mixed scalar value.
	 */
	protected function getScalarResult($result, $type)
	{
		$scalar = array_shift($result);
		settype($scalar, $type);
		return $scalar;
	}

	/**
	 * Set a property of the result object with appropriate value.
	 * @param TResultMap result mapping details.
	 * @param TResultProperty the result property to fill.
	 * @param array a result set row retrieved from the database
	 * @param object the result object
	 */
	protected function setObjectProperty($resultMap, $property, $row, &$resultObject)
	{
		$select = $property->getSelect();
		$key = $property->getProperty();
		$nested = $property->getNestedResultMap();
		if($key === '')
		{
			$resultObject = $property->getDatabaseValue($row);	
		}
		else if(strlen($select) == 0 && is_null($nested))
		{
			$value = $property->getDatabaseValue($row);
			
			$this->_IsRowDataFound = $this->_IsRowDataFound || ($value != null);
			if(is_array($resultObject) || is_object($resultObject))
				TPropertyAccess::set($resultObject, $key, $value);
			else
				$resultObject = $value;
		}
		else if(!is_null($nested))
		{
			if($property->isListType($resultObject) || $property->isArrayType($resultObject))
			{
				if(strlen($resultMap->getGroupBy()) <= 0)
					throw new TSqlMapExecutionException(
						'sqlmap_non_groupby_array_list_type', $resultMap->getID(),
						get_class($resultObject), $key);
			}
			else
			{
				$obj = $nested->createInstanceOfResult();
				if($this->fillPropertyWithResultMap($nested, $row, $obj) == false)
					$obj = null;
				TPropertyAccess::set($resultObject, $key, $obj);
			}
		}
		else //'select' ResultProperty
		{
			$this->enquequePostSelect($select, $resultMap, $property, $row, $resultObject);
		}
	}

	/**
	 * Add nested result property to post select queue.
	 * @param string post select statement ID
	 * @param TResultMap current result mapping details.
	 * @param TResultProperty current result property.
	 * @param array a result set row retrieved from the database
	 * @param object the result object
	 */
	protected function enquequePostSelect($select, $resultMap, $property, $row, $resultObject)
	{
		$statement = $this->_sqlMap->getMappedStatement($select);
		$key = $this->getPostSelectKeys($resultMap, $property, $row);
		$postSelect = new TPostSelectBinding;
		$postSelect->setStatement($statement);
		$postSelect->setResultObject($resultObject);
		$postSelect->setResultProperty($property);
		$postSelect->setKeys($key);

		if($property->isListType($resultObject))
		{
			$values = null;
			if($property->getLazyLoad())
			{
				$values = TLazyLoadList::newInstance($statement, $key,
								$resultObject, $property->getProperty());
				TPropertyAccess::set($resultObject, $property->getProperty(), $values);
			}
			else
				$postSelect->setMethod(self::QUERY_FOR_LIST);
		}
		else if($property->isArrayType($resultObject))
			$postSelect->setMethod(self::QUERY_FOR_ARRAY);
		else
			$postSelect->setMethod(self::QUERY_FOR_OBJECT);

		if(!$property->getLazyLoad())
			array_push($this->_selectQueque, $postSelect);
	}

	/**
	 * Finds in the post select property the SQL statement primary selection keys.
	 * @param TResultMap result mapping details
	 * @param TResultProperty result property
	 * @param array current row data.
	 * @return array list of primary key values.
	 */
	protected function getPostSelectKeys($resultMap, $property,$row)
	{
		$value = $property->getColumn();
		if(is_int(strpos($value.',',0)) || is_int(strpos($value, '=',0)))
		{
			$keys = array();
			foreach(explode(',', $value) as $entry)
			{
				$pair =explode('=',$entry);
				$keys[trim($pair[0])] = $row[trim($pair[1])];
			}
			return $keys;
		}
		else
		{
			return $property->getOrdinalValue($row);
		}
	}

	/**
	 * Fills the property with result mapping results.
	 * @param TResultMap nested result mapping details.
	 * @param array a result set row retrieved from the database
	 * @param object the result object
	 * @return boolean true if the data was found, false otherwise.
	 */
	protected function fillPropertyWithResultMap($resultMap, $row, &$resultObject)
	{
		$dataFound = false;
		foreach($resultMap->getColumns() as $property)
		{
			$this->_IsRowDataFound = false;		
			$this->setObjectProperty($resultMap, $property, $row, $resultObject);
			$dataFound = $dataFound || $this->_IsRowDataFound;
		}
		$this->_IsRowDataFound = $dataFound;
		return $dataFound;
	}
}

class TPostSelectBinding
{
	private $_statement=null;
	private $_property=null;
	private $_resultObject=null;
	private $_keys=null;
	private $_method=TMappedStatement::QUERY_FOR_LIST;

	public function getStatement(){ return $this->_statement; }
	public function setStatement($value){ $this->_statement = $value; }

	public function getResultProperty(){ return $this->_property; }
	public function setResultProperty($value){ $this->_property = $value; }

	public function getResultObject(){ return $this->_resultObject; }
	public function setResultObject($value){ $this->_resultObject = $value; }

	public function getKeys(){ return $this->_keys; }
	public function setKeys($value){ $this->_keys = $value; }

	public function getMethod(){ return $this->_method; }
	public function setMethod($value){ $this->_method = $value; }
}

/**
 * TSQLMapObjectCollectionTree class.
 * 
 * Maps object collection graphs as trees. Nodes in the collection can
 * be {@link add} using parent relationships. The object collections can be
 * build using the {@link collect} method.
 *
 * @author Wei Zhuo <weizhuo[at]gmail[dot]com>
 * @version $Id$
 * @package System.DataAccess.SQLMap.Statements
 * @since 3.1
 */
class TSQLMapObjectCollectionTree
{
	/**
	 * @var array object graph as tree
	 */
	private $_tree = array();
	/**
	 * @var array tree node values
	 */
	private $_entries = array();
	/**
	 * @var array resulting object collection
	 */
	private $_list = array();
	
	/**
	 * @return boolean true if the graph is empty
	 */
	public function isEmpty()
	{
		return count($this->_entries) == 0;
	}
	
	/**
	 * Add a new node to the object tree graph.
	 * @param string parent node id
	 * @param string new node id
	 * @param mixed node value
	 */
	public function add($parent, $node, $object='')
	{
		if(isset($this->_entries[$parent]) && !is_null($this->_entries[$parent])  
			&& isset($this->_entries[$node]) && !is_null($this->_entries[$node]))
		{
			$this->_entries[$node] = $object;
			return;
		}
		$this->_entries[$node] = $object;
		if(empty($parent))
		{
			if(isset($this->_entries[$node]))
				return;
			$this->_tree[$node] = array();
		}
		$found = $this->addNode($this->_tree, $parent, $node);
		if(!$found && !empty($parent))
		{
			$this->_tree[$parent] = array();
			if(!isset($this->_entries[$parent]) || $object !== '')
				$this->_entries[$parent] = $object;
			$this->addNode($this->_tree, $parent, $node);
		}
	}
	
	/**
	 * Find the parent node and add the new node as its child.
	 * @param array list of nodes to check
	 * @param string parent node id
	 * @param string new node id
	 * @return boolean true if parent node is found.
	 */
	protected function addNode(&$childs, $parent, $node)
	{
		$found = false;
		reset($childs);
		for($i = 0, $k = count($childs); $i < $k; $i++)
		{
			$key = key($childs);
			next($childs);
			if($key == $parent)
			{
				$found = true;
				$childs[$key][$node] = array();
			}
			else
			{
				$found = $found || $this->addNode($childs[$key], $parent, $node);
			}
		}
		return $found;
	}
	
	/**
	 * @return array object collection
	 */
	public function collect()
	{
		while(count($this->_tree) > 0)
			$this->collectChildren(null, $this->_tree);
		return $this->getCollection();
	}
	
	/**
	 * @param array list of nodes to check
	 * @return boolean true if all nodes are leaf nodes, false otherwise
	 */
	protected function hasChildren(&$nodes)
	{
		$hasChildren = false;
		foreach($nodes as $node)
			if(count($node) != 0)
				return true;
		return $hasChildren;		
	}
	
	/**
	 * Visit all the child nodes and collect them by removing.
	 * @param string parent node id
	 * @param array list of child nodes.
	 */
	protected function collectChildren($parent, &$nodes)
	{
		$noChildren = !$this->hasChildren($nodes);		
		$childs = array();
		for(reset($nodes); $key = key($nodes);)
		{
			next($nodes);
			if($noChildren)
			{
				$childs[] = $key;
				unset($nodes[$key]);
			}
			else
				$this->collectChildren($key, $nodes[$key]);
		}
		if(count($childs) > 0)
			$this->onChildNodesVisited($parent, $childs);
	}
	
	/**
	 * Set the object properties for all the child nodes visited.
	 * @param string parent node id
	 * @param array list of child nodes visited.
	 */
	protected function onChildNodesVisited($parent, $nodes)
	{
		if(empty($parent) || empty($this->_entries[$parent]))
			return;
	
		$parentObject = $this->_entries[$parent]['object'];
		$property = $this->_entries[$nodes[0]]['property'];		
		
		$list = TPropertyAccess::get($parentObject, $property);
	
		foreach($nodes as $node)
		{
			if($list instanceof TList)
				$parentObject->{$property}[] = $this->_entries[$node]['object'];
			else if(is_array($list))
				$list[] = $this->_entries[$node]['object'];
			else
				throw TSqlMapExecutionException(
					'sqlmap_property_must_be_list');
		}
		
		if(is_array($list))
			TPropertyAccess::set($parentObject, $property, $list);
	
		if($this->_entries[$parent]['property'] === null)
			$this->_list[] = $parentObject;
	}
	
	/**
	 * @return array object collection.
	 */
	protected function getCollection()
	{
		return $this->_list;
	}
}

class TResultSetListItemParameter extends TComponent
{
	private $_resultObject;
	private $_parameterObject;
	private $_list;

	public function __construct($result, $parameter, &$list)
	{
		$this->_resultObject = $result;
		$this->_parameterObject = $parameter;
		$this->_list = &$list;
	}

	public function getResult()
	{
		return $this->_resultObject;
	}

	public function getParameter()
	{
		return $this->_parameterObject;
	}

	public function &getList()
	{
		return $this->_list;
	}
}

class TResultSetMapItemParameter extends TComponent
{
	private $_key;
	private $_value;
	private $_parameterObject;
	private $_map;

	public function __construct($key, $value, $parameter, &$map)
	{
		$this->_key = $key;
		$this->_value = $value;
		$this->_parameterObject = $parameter;
		$this->_map = &$map;
	}

	public function getKey()
	{
		return $this->_key;
	}
	
	public function getValue()
	{
		return $this->_value;
	}

	public function getParameter()
	{
		return $this->_parameterObject;
	}

	public function &getMap()
	{
		return $this->_map;
	}
}

?>