<?php

/**
 * Toknot (http://toknot.com)
 *
 * @copyright  Copyright (c) 2011 - 2015 Toknot.com
 * @license    http://toknot.com/LICENSE.txt New BSD License
 * @link       https://github.com/chopins/toknot
 */

namespace Toknot\Db;

use Toknot\Db\DbCRUD;
use Toknot\Db\ActiveQuery;
use Toknot\Db\Exception\DatabaseException;
use \InvalidArgumentException;

final class DbTableJoinObject extends DbCRUD {

    private $preONSQLs = array();
    private $preJONSQLs = array();
    private $firstTable = null;
    public $where = 1;

    /**
     * join some table
     * 
     * @param \Toknot\Db\DbTableObject $table1
     * @param \Toknot\Db\DbTableObject $table2
     * @throws InvalidArgumentException
     */
    public function __init(DbTableObject $table1, DbTableObject $table2) {
        if(ActiveQuery::getDbDriverType() == ActiveQuery::DRIVER_SQLITE) {
            throw new DatabaseException('Unsupport mult-table query for sqlite, you must write SQL by self');
        }
        $tableList = func_get_args();
        foreach ($tableList as $tableObject) {
            if (!$tableObject instanceof DbTableObject) {
                throw new InvalidArgumentException('Must be an instance of \Toknot\Db\DbTableObject');
            }
            $this->interatorArray[$tableObject->tableName] = $tableObject;
        }
        $this->connectInstance = $table1->connectInstance;
        $this->dbINSType = $table1->dbINSType;
    }

    public function getPropertie($name) {
        if (isset($this->interatorArray[$name])) {
            return $this->interatorArray[$name];
        }
    }

    /**
     * Set join condition of tables between of relationship, if set table alias, must be after
     * invoke the method, usage at {@see DbTableJoinObject::find()}
     * 
     * @param string $key1  be linked key
     * @param string $key2  be linked key
     * @param string $_   
     * @throws InvalidArgumentException
     */
    public function tableON($key1, $key2) {
        $keyList = func_get_args();
        $argc = func_num_args();
        if ($argc % 2 != 0) {
            throw new InvalidArgumentException('Must be pairs');
        }
        
        $tableNum = count($this->interatorArray) - 1;
        if ($tableNum != $argc / 2) {
            throw new InvalidArgumentException('Args number invalid');
        }
        
        for ($i = 0; $i < $argc; $i = $i + 2) {
            $f1 = "{$keyList[$i]->tableObject->alias}.{$keyList[$i]->columnName}";
            $f2 = "{$keyList[$i + 1]->tableObject->alias}.{$keyList[$i + 1]->columnName}";
            $this->preONSQLs[] = ActiveQuery::on($f1, $f2);
        }
    }

    protected function tableJOIN() {
        $this->firstTable = array_shift($this->interatorArray);
        foreach ($this->interatorArray as $key => $table) {
            $this->preJONSQLs [$key] = ActiveQuery::leftJoin($table->tableName, $table->alias);
        }
    }

    protected function bulidSQL() {
        $sql = '';
        $select = ActiveQuery::bindTableAlias($this->firstTable->alias, $this->firstTable->columnList);
        $i = 0;
        foreach ($this->preJONSQLs as $key => $tableSQL) {
            $sql .= $tableSQL;
            $sql .= $this->preONSQLs[$i];
            $field = ActiveQuery::bindTableAlias($this->interatorArray[$key]->alias, 
                                        $this->interatorArray[$key]->columnList);
            $i++;
        }
        $select = ActiveQuery::select($this->firstTable->tableName, $field) . ' AS ' . $this->firstTable->alias;
        return $select . $sql . ActiveQuery::where($this->where);
    }

    /**
     * get result by join relationship and set where statement(which is set on DbTableJoinObject::$where)
     * 
     * <code>
     * $ar = new create ActiveRecord()
     * $db = $ar->connect();
     * $joinTable = $db->tableJOIN($db->table1, $db->table2);
     * $joinTable->table1->alias = 'a';
     * $joinTable->table2->alias = 'b';
     * $joinTable->tableON($joinTable->table1->Id, $joinTable->table2->id);
     * $joinTable->where = 'a.id > 5';   //set where is table1 of id greater than 5
     * $joinTable->order = ActiveQuery::ORDER_DESC;
     * $joinTable->orderBy = 'b.id';  //set order by table2 of id
     * $joinTable->find(10);
     * </code>
     * 
     * @param type $start
     * @param type $limit
     */
    public function find($start = 0, $limit = null) {
        $this->tableJOIN();
        $sql = $this->bulidSQL();
        $sql .= ActiveQuery::order($this->order, $this->orderBy);
        $sql .= ActiveQuery::limit($start, $limit);
        $this->readAll($sql);
    }

}

?>
