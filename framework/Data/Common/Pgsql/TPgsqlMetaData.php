<?php
/**
 * TPgsqlMetaData class file.
 *
 * @author Wei Zhuo <weizhuo[at]gmail[dot]com>
 * @link http://www.pradosoft.com/
 * @copyright Copyright &copy; 2005-2007 PradoSoft
 * @license http://www.pradosoft.com/license/
 * @version $Id$
 * @package System.Data.Common.Pgsql
 */

/**
 * Load the base TDbMetaData class.
 */
Prado::using('System.Data.Common.TDbMetaData');
Prado::using('System.Data.Common.Pgsql.TPgsqlTableInfo');

/**
 * TPgsqlMetaData loads PostgreSQL database table and column information.
 *
 * @author Wei Zhuo <weizho[at]gmail[dot]com>
 * @version $Id$
 * @package System.Data.Commom.Pgsql
 * @since 3.1
 */
class TPgsqlMetaData extends TDbMetaData
{
	private $_defaultSchema = 'public';

	/**
	 * @param string default schema.
	 */
	public function setDefaultSchema($schema)
	{
		$this->_defaultSchema=$schema;
	}

	/**
	 * @return string default schema.
	 */
	public function getDefaultSchema()
	{
		return $this->_defaultSchema;
	}

	/**
	 * @param string table name with optional schema name prefix, uses default schema name prefix is not provided.
	 * @return array tuple as ($schemaName,$tableName)
	 */
	protected function getSchemaTableName($table)
	{
		if(count($parts= explode('.', $table)) > 1)
			return array($parts[0], $parts[1]);
		else
			return array($this->getDefaultSchema(),$parts[0]);
	}

	/**
	 * Get the column definitions for given table.
	 * @param string table name.
	 * @return TPgsqlTableInfo table information.
	 */
	protected function createTableInfo($table)
	{
		list($schemaName,$tableName) = $this->getSchemaTableName($table);

		// This query is made much more complex by the addition of the 'attisserial' field.
		// The subquery to get that field checks to see if there is an internally dependent
		// sequence on the field.
		$sql =
<<<EOD
		SELECT
			a.attname,
			pg_catalog.format_type(a.atttypid, a.atttypmod) as type,
			a.atttypmod,
			a.attnotnull, a.atthasdef, adef.adsrc,
			(
				SELECT 1 FROM pg_catalog.pg_depend pd, pg_catalog.pg_class pc
				WHERE pd.objid=pc.oid
				AND pd.classid=pc.tableoid
				AND pd.refclassid=pc.tableoid
				AND pd.refobjid=a.attrelid
				AND pd.refobjsubid=a.attnum
				AND pd.deptype='i'
				AND pc.relkind='S'
			) IS NOT NULL AS attisserial

		FROM
			pg_catalog.pg_attribute a LEFT JOIN pg_catalog.pg_attrdef adef
			ON a.attrelid=adef.adrelid
			AND a.attnum=adef.adnum
			LEFT JOIN pg_catalog.pg_type t ON a.atttypid=t.oid
		WHERE
			a.attrelid = (SELECT oid FROM pg_catalog.pg_class WHERE relname=:table
				AND relnamespace = (SELECT oid FROM pg_catalog.pg_namespace WHERE
				nspname = :schema))
			AND a.attnum > 0 AND NOT a.attisdropped
		ORDER BY a.attnum
EOD;
		$this->getDbConnection()->setActive(true);
		$command = $this->getDbConnection()->createCommand($sql);
		$command->bindValue(':table', $tableName);
		$command->bindValue(':schema', $schemaName);
		$tableInfo = $this->createNewTableInfo($schemaName, $tableName);
		$index=0;
		foreach($command->query() as $col)
		{
			$col['index'] = $index++;
			$this->processColumn($tableInfo, $col);
		}
		return $tableInfo;
	}

	/**
	 * @param string table schema name
	 * @param string table name.
	 * @return TPgsqlTableInfo
	 */
	protected function createNewTableInfo($schemaName,$tableName)
	{
		$info['SchemaName'] = $schemaName;
		$info['TableName'] = $tableName;
		if($this->getIsView($schemaName,$tableName))
			$info['IsView'] = true;
		list($primary, $foreign, $unique) = $this->getConstraintKeys($schemaName, $tableName);
		return new TPgsqlTableInfo($info,$primary,$foreign, $unique);
	}

	/**
	 * @param string table schema name
	 * @param string table name.
	 * @return boolean true if the table is a view.
	 */
	protected function getIsView($schemaName,$tableName)
	{
		$sql =
<<<EOD
		SELECT count(c.relname) FROM pg_catalog.pg_class c
		LEFT JOIN pg_catalog.pg_namespace n ON (n.oid = c.relnamespace)
		WHERE (n.nspname=:schema) AND (c.relkind = 'v'::"char") AND c.relname = :table
EOD;
		$this->getDbConnection()->setActive(true);
		$command = $this->getDbConnection()->createCommand($sql);
		$command->bindValue(':schema',$schemaName);
		$command->bindValue(':table', $tableName);
		return intval($command->queryScalar()) === 1;
	}

	/**
	 * @param TPgsqlTableInfo table information.
	 * @param array column information.
	 */
	protected function processColumn($tableInfo, $col)
	{
		$columnId = $col['attname']; //use column name as column Id

		$info['ColumnName'] = '"'.$columnId.'"'; //quote the column names!
		$info['ColumnIndex'] = $col['index'];
		if(!$col['attnotnull'])
			$info['AllowNull'] = true;
		if(in_array($columnId, $tableInfo->getPrimaryKeys()))
			$info['IsPrimaryKey'] = true;
		if($this->isForeignKeyColumn($columnId, $tableInfo))
			$info['IsForeignKey'] = true;
		if(in_array($columnId, $tableInfo->getUniqueKeys()))
			$info['IsUnique'] = true;

		if($col['atttypmod'] > 0)
			$info['ColumnSize'] =  $col['atttypmod'] - 4;
		if($col['atthasdef'])
			$info['DefaultValue'] = $col['adsrc'];
		if($col['attisserial'] || substr($col['adsrc'],0,8) === 'nextval(')
		{
			if(($sequence = $this->getSequenceName($tableInfo, $col['adsrc']))!==null)
			{
				$info['SequenceName'] = $sequence;
				unset($info['DefaultValue']);
			}
		}
		$matches = array();
		if(preg_match('/\((\d+)(?:,(\d+))?+\)/', $col['type'], $matches))
		{
			$info['DbType'] = preg_replace('/\(\d+(?:,\d+)?\)/','',$col['type']);
			if($this->isPrecisionType($info['DbType']))
			{
				$info['NumericPrecision'] = intval($matches[1]);
				if(count($matches) > 2)
					$info['NumericScale'] = intval($matches[2]);
			}
			else
				$info['ColumnSize'] = intval($matches[1]);
		}
		else
			$info['DbType'] = $col['type'];

		$tableInfo->Columns[$columnId] = new TPgsqlTableColumn($info);
	}

	/**
	 * @return string serial name if found, null otherwise.
	 */
	protected function getSequenceName($tableInfo,$src)
	{
		$matches = array();
		if(preg_match('/nextval\([^\']*\'([^\']+)\'[^\)]*\)/i',$src,$matches))
		{
			if(is_int(strpos($matches[1], '.')))
				return $matches[1];
			else
				return $tableInfo->getSchemaName().'.'.$matches[1];
		}
	}

	/**
	 * @return boolean true if column type if "numeric", "interval" or begins with "time".
	 */
	protected function isPrecisionType($type)
	{
		$type = strtolower(trim($type));
		return $type==='numeric' || $type==='interval' || strpos($type, 'time')===0;
	}

	/**
	 * Gets the primary, foreign key, and unique column details for the given table.
	 * @param string schema name
	 * @param string table name.
	 * @return array tuple ($primary, $foreign, $unique)
	 */
	protected function getConstraintKeys($schemaName, $tableName)
	{
		$sql = 'SELECT
				pg_catalog.pg_get_constraintdef(pc.oid, true) AS consrc,
				pc.contype
			FROM
				pg_catalog.pg_constraint pc
			WHERE
				pc.conrelid = (SELECT oid FROM pg_catalog.pg_class WHERE relname=:table
					AND relnamespace = (SELECT oid FROM pg_catalog.pg_namespace
					WHERE nspname=:schema))
		';
		$this->getDbConnection()->setActive(true);
		$command = $this->getDbConnection()->createCommand($sql);
		$command->bindValue(':table', $tableName);
		$command->bindValue(':schema', $schemaName);
		$primary = array();
		$foreign = array();
		$unique = array();
		foreach($command->query() as $row)
		{
			switch($row['contype'])
			{
				case 'p':
					$primary = $this->getPrimaryKeys($row['consrc']);
					break;
				case 'f':
					if(($fkey = $this->getForeignKeys($row['consrc']))!==null)
						$foreign[] = $fkey;
					break;
				case 'u':
					if(($ukey = $this->getUniqueKey($row['consrc']))!==null)
						$unique[] = $ukey;
					break;
			}
		}
		return array($primary,$foreign,$unique);
	}

	/**
	 * Gets the primary key field names
	 * @param string pgsql primary key definition
	 * @return array primary key field names.
	 */
	protected function getPrimaryKeys($src)
	{
		$matches = array();
		if(preg_match('/PRIMARY\s+KEY\s+\(([^\)]+)\)/i', $src, $matches))
			return preg_split('/,\s+/',$matches[1]);
		return array();
	}

	/**
	 * @param string pgsql unique constraint definition
	 * @return string column id if found, null otherwise.
	 */
	protected function getUniqueKey($src)
	{
		$matches=array();
		if(preg_match('/UNIQUE\s+\(([^\)]+)\)/i', $src, $matches))
			return $matches[1];
	}

	/**
	 * Gets foreign relationship constraint keys and table name
	 * @param string pgsql foreign key definition
	 * @return array foreign relationship table name and keys, null otherwise
	 */
	protected function getForeignKeys($src)
	{
		$matches = array();
		$brackets = '\(([^\)]+)\)';
		$find = "/FOREIGN\s+KEY\s+{$brackets}\s+REFERENCES\s+([^\(]+){$brackets}/i";
		if(preg_match($find, $src, $matches))
		{
			$keys = preg_split('/,\s+/', $matches[1]);
			$fkeys = array();
			foreach(preg_split('/,\s+/', $matches[3]) as $i => $fkey)
				$fkeys[$keys[$i]] = $fkey;
			return array('table' => $matches[2], 'keys' => $fkeys);
		}
	}

	/**
	 * @param string column name.
	 * @param TPgsqlTableInfo table information.
	 * @return boolean true if column is a foreign key.
	 */
	protected function isForeignKeyColumn($columnId, $tableInfo)
	{
		foreach($tableInfo->getForeignKeys() as $fk)
		{
			if(in_array($columnId, array_keys($fk['keys'])))
				return true;
		}
		return false;
	}
}

?>