<?php

/**
 * Toknot (http://toknot.com)
 *
 * @copyright  Copyright (c) 2011 - 2017 Toknot.com
 * @license    http://toknot.com/LICENSE.txt New BSD License
 * @link       https://github.com/chopins/toknot
 */

namespace Toknot\Share;

use Toknot\Share\DB\DBA;
use Toknot\Boot\Kernel;
use Toknot\Exception\BaseException;
use Toknot\Boot\Object;

abstract class Model extends Object {

    /**
     * The table name
     *
     * @var string
     */
    protected $table;

    /**
     * The primary key name
     *
     * @var string
     */
    protected $key;
    protected $fetchCursorIndex;
    protected $keyValue;
    protected $currentResult = [];

    /**
     *
     * @var \Doctrine\DBAL\Connection
     */
    protected $conn;
    protected $columnSql;
    private $tmpColumnSql = '';

    /**
     *
     * @var array
     */
    protected $tableInfo;
    protected $lastSql = '';
    protected $transactionActive = false;

    /**
     * check border when update
     *
     * @var boolean
     */
    public $checkBorder = true;
    private $alias = '';
    public $namespace = '';
    private $statement = null;

    /**
     *
     * @var \Doctrine\DBAL\Query\QueryBuilder
     */
    private $qr;
    private $unsigned = ['smallint' => 65535, 'integer' => 4294967295, 'bigint' => 18446744073709551615];
    private $signed = ['smallint' => 32767, 'integer' => 2147483647, 'bigint' => 9223372036854775807];

    /**
     * 
     * @param array $tableInfo
     */
    final public function __construct($tableInfo, $alias = '') {
        //$indexs = Tookit::coalesce($tableInfo, 'indexes');
        //$this->key = Tookit::coalesce($indexs, 'primary');
        $this->tableInfo = $tableInfo;
        $this->alias = $alias;
    }

    final public function useNamespace() {
        $this->namespace = DBA::single()->getDatabase();
    }

    final public function setAlias($alias) {
        $this->alias = $alias;
    }

    final public function getTableAlias() {
        return empty($this->alias) ? $this->table : $this->alias;
    }

    final public function getColumn($column) {
        return $this->getTableAlias() . ".$column";
    }

    public function cacheSQL($cacheHandler) {
        $cacheHandler->save($this->lastSql);
    }

    public function correctUnsignedValue($type, $value) {
        if (isset($this->unsigned[$type]) && $value > $this->unsigned[$type]) {
            return $this->unsigned[$type];
        }
        return $value;
    }

    public function correctSignedValue($type, $value) {
        if (isset($this->signed[$type])) {
            if ($value > 0 && $value > $this->signed[$type]) {
                return $this->signed[$type];
            } else if ($value < 0 && abs($value) > ($this->signed[$type] + 1)) {
                return ($this->signed[$type] + 1) * -1;
            }
        }
        return $value;
    }

    /**
     * Get current table info
     * 
     * @return array
     */
    final public function getTableInfo() {
        return $this->tableInfo;
    }

    /**
     * column list convert to string and add alias
     * 
     * @param string|array $column
     * @param string $alias
     */
    final public function setColumn($column, $alias = '') {
        $glue = $alias == '' ? ', ' : ", $alias.";
        $this->tmpColumnSql = $alias . is_array($column) ? implode($glue, $column) : $column;
    }

    /**
     * set DBAL connect
     * 
     * @param \Doctrine\DBAL\Connection $con
     */
    final public function connect($con) {
        $this->conn = $con;
    }

    /**
     * 
     * @return \Doctrine\DBAL\Query\QueryBuilder
     */
    final public function builder() {
        return $this->conn->createQueryBuilder();
    }

    /**
     * 
     * @param string $type
     * @return \Doctrine\DBAL\Query\QueryBuilder
     */
    final public function ready($type) {
        $fnc = $type == 'select' ? 'from' : $type;
        return $this->builder()->$fnc($this->tableName());
    }

    /**
     * get last exec sql
     * 
     * @return string
     */
    final public function getLastSql() {
        return $this->lastSql;
    }

    /**
     * get column type
     * 
     * @param string $key
     * @return string
     */
    final public function getColumnType($key) {
        $t = $this->tableInfo['column'][$key]['type'];
        return DBA::getDBType($t);
    }

    /**
     * get current table name
     * 
     * @return string
     */
    public function tableName() {
        if ($this->namespace) {
            $this->table = "{$this->namespace}.{$this->table}";
        }
        return $this->table;
    }

    /**
     * get primary key for current table
     * 
     * @return string
     */
    public function keyName() {
        return $this->key;
    }

    /**
     * get result for smt
     * 
     * @param int $limit
     * @param int $start
     * @param int $fetchMode
     * @return array
     */
    public function get($limit = 50, $start = 0, $fetchMode = \PDO::FETCH_ASSOC) {
        $this->qr->setFirstResult($start);
        $this->qr->setMaxResults($limit);
        $this->lastSql = $this->qr->getSQL();
        try {
            $smt = $this->qr->execute();
            return $smt->fetchAll($fetchMode);
        } catch (\PDOException $e) {
            return Kernel::single()->echoException($e);
        }
    }

    /**
     * execute a sql and return result resources
     * 
     * @param int $limit
     * @param int $start
     * @return $this
     */
    public function execute($limit = 50, $start = 0) {
        $this->qr->setFirstResult($start);
        $this->qr->setMaxResults($limit);
        $this->lastSql = $this->qr->getSQL();
        try {
            $this->statement = $this->qr->execute();
            return $this;
        } catch (\PDOException $e) {
            return Kernel::single()->echoException($e);
        }
    }

    public function iterator($where, $limit = 50, $start = 0) {
        if ($this->iteratorArray) {
            $this->iteratorArray->closeCursor();
            $this->currentResult = [];
        }
        $this->iteratorArray = $this->select($where)->execute($limit, $start);
        return $this;
    }

    /**
     * get a record from result resources
     * 
     * @param Statement $smt
     * @param int $fetchMode
     * @return array
     */
    public function fetch($fetchMode = \PDO::FETCH_ASSOC) {
        try {
            return $this->statement->fetch($fetchMode);
        } catch (\PDOException $e) {
            return Kernel::single()->echoException($e);
        }
    }

    public function getRow() {
        $smt = $this->qr->execute();
        return $smt->fetch();
    }

    public function current() {

        if ($this->key) {
            $this->keyValue = $this->currentResult[$this->key];
        }
        return $this->currentResult;
    }

    public function rewind() {
        $this->fetchCursorIndex = 0;
        $this->currentResult = [];
        sleep(3);
    }

    public function key() {
        if ($this->key) {
            return $this->keyValue;
        }
        return $this->fetchCursorIndex;
    }

    public function valid() {
        if (!$this->iteratorArray) {
            return false;
        }
        $this->currentResult = $this->fetch($this->iteratorArray, DBA::$fechStyle, DBA::$cursorOri, $this->fetchCursorIndex);
        return $this->currentResult;
    }

    public function next() {
        ++$this->fetchCursorIndex;
        return true;
    }

    /**
     * get result where a key
     * 
     * @param string $keyValue
     * @param string $expr
     * @return []
     */
    public function getKeyValue($keyValue) {
        return $this->select([$this->key, $keyValue, '='])->get(1);
    }

    /**
     * get count
     * 
     * @param array $where
     * @param string $key
     * @return int
     */
    public function count($where = [], $key = '') {
        $this->qr = $this->ready('select');
        $ck = $key ? $key : ($this->key ? $this->key : '*');
        $this->qr->select("COUNT($ck) AS cnt")
                ->where($this->where($where));
        return $this->get(1)[0]['cnt'];
    }

    public function query($sql, $where) {
        $this->qr->select($sql)->where($this->where($where));
        return $this;
    }

    /**
     * insert data
     * 
     * @param array $value
     * @return int
     */
    public function insert($value) {
        $values = array_fill_keys(array_keys($value), '?');
        $this->qr = $this->ready(__FUNCTION__)->values($values);

        $i = 0;
        foreach ($value as $v) {
            $this->setQueryArg($i, $v);
            $i++;
        }

        $this->lastSql = $this->qr->getSQL();
        try {
            $this->qr->execute();
        } catch (\PDOException $e) {
            return Kernel::single()->echoException($e);
        }
        return $this->conn->lastInsertId();
    }

    /**
     * convert to compute sql
     * 
     * @param string $left
     * @param string $right
     * @param string $expr
     * @return string
     */
    public function compute($left, $right, $expr) {
        $defaultExpr = ['+', '-', '*', '/'];
        if (in_array($expr, $defaultExpr)) {
            if ($this->checkBorder && $expr == '-' && $this->hasColumn($left) &&
                    $this->isUnsigned($left)) {
                return "IF($left>=$right,$left $expr $right,0)";
            }
            return "$left $expr $right";
        } else {
            return "$expr($left,$expr)";
        }
    }

    /**
     * update data
     * 
     * @param array $values         [key => [expre,$leftValue,$rightValue]], [key=>[=,key,1]]
     *                              [key => value, key2=>value2]
     * @param array|string $where   [key, value, com] or [&& ,[key,value,com],[key,value,com]
     * @param int $limit
     * @param int $start
     * @return int
     */
    public function update($values, $where = [], $limit = 500, $start = 0) {
        $this->qr = $this->ready(__FUNCTION__);
        $i = 0;
        foreach ($values as $key => $v) {
            if (is_array($v)) {
                $this->qr->set($key, $this->compute($v[1], $v[2], $v[0]));
            } else {
                $placeholde = ":s$i$key";
                $this->qr->set($key, $placeholde);
                $this->qr->setParameter($placeholde, $v);
                $i++;
            }
        }

        $this->qr->where($this->where($where));
        $this->qr->setFirstResult($start);
        $this->qr->setMaxResults($limit);
        $this->lastSql = $this->qr->getSQL();

        try {
            return $this->qr->execute();
        } catch (\PDOException $e) {
            return Kernel::single()->echoException($e);
        }
    }

    /**
     * Compare and update an value on has primary key,(Optimistic lock)
     * 
     * @param array $values     new data
     * @param string $keyValue  key value
     * @param int $cas          cas value
     * @param string $casFeild  cas feild name
     * @return int
     */
    public function cas($values, $keyValue, $cas, $casFeild = 'cas') {
        $values = array_merge($values, [$casFeild => ['+', $casFeild, 1]]);
        return $this->update($values, [[$this->key, $keyValue], [$casFeild, $cas]]);
    }

    /**
     * delete data
     * 
     * @param array|string $where
     * @param int $limit
     * @param int $start
     * @return int
     */
    public function delete($where, $limit = 500, $start = 0) {
        $this->qr = $this->ready(__FUNCTION__);
        $this->qr->where($this->where($where));
        $this->qr->setFirstResult($start);
        $this->qr->setMaxResults($limit);
        $this->lastSql = $this->qr->getSQL();
        try {
            return $this->qr->execute();
        } catch (\PDOException $e) {
            return Kernel::single()->echoException($e);
        }
    }

    /**
     * 
     * @return \Doctrine\DBAL\Query\Expression\ExpressionBuilder
     */
    public function expr() {
        return $this->builder()->expr();
    }

    /**
     * 
     * @param array $param
     * @return string
     */
    public function compKey($param) {
        $operator = isset($param[2]) ? $param[2] : '=';
        return $this->expr()->comparison($param[0], $operator, $param[1]);
    }

    /**
     * check key wheter is not or at 0 index
     * 
     * @param int $i
     * @param string $type
     * @return boolean
     */
    public function notAndOr($i, $type) {
        $com = ($type == DBA::T_OR || $type == DBA::T_AND);
        return $i === 0 && !$com;
    }

    /**
     * check is colunm feild whether is unsigend, the column defalut is unsigend
     * 
     * @param string $column
     * @return boolean
     */
    public function isUnsigned($column) {
        if (isset($this->tableInfo['column'][$column]['unsigned'])) {
            return $this->tableInfo['column'][$column]['unsigned'];
        }
        return true;
    }

    /**
     * check current table whther has specify column
     * 
     * @param string $column
     * @return boolean
     */
    public function hasColumn($column) {
        return isset($this->tableInfo['column'][$column]);
    }

    /**
     * set where sql, like [key,value,composite]
     * 
     * @param array $param = [
     *                          '&&',['||', 
     *                                      ['id','1','='],['name','this','=']
     *                                ],
     *                                ['email','name@domain','='],
     *                                ['user','foo','=]
     *                        ]
     * @return \Doctrine\DBAL\Query\Expression\CompositeExpression
     */
    public function where($param) {
        if (is_string($param)) {
            return $param;
        }
        if (!is_array($param)) {
            return 1;
        }
        $where = [];
        foreach ($param as $k => $v) {
            if ($this->notAndOr($k, $v)) {
                $v = $param[1];
                $hold = ":w{$k}{$param[0]}";
                $param[1] = $hold;
                $this->setQueryArg($hold, $v);
                return $this->compKey($param);
            } elseif ($k === 0) {
                $type = DBA::getCompType($v);
            } else {
                $where[] = $this->where($v);
            }
        }
        return DBA::composite($type, $where);
    }

    public function setQueryArg($placeholder, $v) {
        $this->qr->setParameter($placeholder, $v);
    }

    /**
     * 
     * @return string
     * @after \Toknot\Share\Model::setColumn()
     */
    public function selectColumn() {
        $columnSql = $this->tmpColumnSql ? $this->tmpColumnSql : $this->columnSql;
        $this->tmpColumnSql = '';
        return $columnSql;
    }

    /**
     * select data
     * 
     * @param array|string $where
     * @param int $limit
     * @param int $start
     * @return  \Toknot\Share\Model
     * @before $this->setColumn()
     */
    public function select($where = '') {
        $columnSql = $this->selectColumn();
        $this->qr = $this->ready(__FUNCTION__);
        $this->qr->select($columnSql)
                ->where($this->where($where));
        return $this;
    }

    /**
     * get join function name
     * 
     * @param string $type
     * @return string
     */
    public function getJoinFunc($type) {
        switch (strtolower($type)) {
            case 'right':
                return 'rightJoin';
            case 'inner':
                return 'innerJoin';
            default:
                return 'leftJoin';
        }
    }

    /**
     * set column alias of sql
     * 
     * @param \Toknot\Share\Model $tb
     * @return string
     */
    public function columnAlias(Model $tb) {
        $tb->setColumn(array_keys($tb->getTableInfo()['column']), $tb->getTableAlias());
        return $tb->selectColumn();
    }

    /**
     * add join table
     * 
     * @param \Toknot\Share\Model $tb
     * @param string $join
     * @param array $on
     * @return string
     */
    public function addJoinTable(Model $tb, $join, $on) {
        $condition = $this->compKey($on);
        $select = $this->columnAlias($tb);
        $t2 = $tb->tableName();
        $this->qr->$join($this->getTableAlias(), $t2, $t2->getTableAlias(), $condition);
        return $select;
    }

    /**
     * 
     * @param \Toknot\Share\Model|array $tables
     * @param array $on
     * @param array $where
     * @param string $type
     * @return \Toknot\Share\Model
     */
    public function join($tables, $on, $where, $type = 'left') {
        $select = [];
        $this->qr = $this->builder()->from($this->tableName(), $this->getTableAlias());
        $join = $this->getJoinFunc($type);
        $select[] = $this->columnAlias($this);
        if (is_array($tables)) {
            foreach ($tables as $k => $tb) {
                $select[] = $this->addJoinTable($tb, $join, $on[$k]);
            }
        } else {
            $select[] = $this->addJoinTable($tb, $join, $on);
        }
        $selectSql = implode(',', $select);

        $this->qr->select($selectSql);

        $this->qr->where($this->where($where));
        return $this;
    }

    public function againSelect($where, $feild = []) {
        if ($this->qr->getType() != DBA::SELECT) {
            throw new BaseException('can not found first selct query');
        }

        $subSql = '(' . $this->qr->getSQL() . ')';
        $this->qr = $this->builder()->from($subSql);
        $column = empty($feild) ? '*' : implode(',', $feild);
        $this->qr->select($column)->where($this->where($where));
        return $this;
    }

    /**
     * set order by key
     * 
     * @param string $sort
     * @param string $order
     * @return \Toknot\Share\Model
     */
    public function orderBy($sort, $order = null) {
        $this->qr->orderBy($sort, $order);
        return $this;
    }

    /**
     * set group by key
     * 
     * @param string $key
     * @return \Toknot\Share\Model
     */
    public function groupBy($key) {
        $this->qr->groupBy($key);
        return $this;
    }

    /**
     * set haveing key
     * 
     * @param string $clause
     * @return \Toknot\Share\Model
     */
    public function having($clause) {
        $this->qr->having($clause);
        return $this;
    }

    /**
     * select at left join 
     * 
     * @param \Toknot\Share\Model|array $table
     * @param array $on
     * @param array|string $where
     * @return \Toknot\Share\Model
     */
    public function leftJoin($table, $on, $where) {
        return $this->join($table, $on, $where, 'left');
    }

    /**
     * select at right join
     * 
     * @param \Toknot\Share\Model|array $table
     * @param array $on  the value smaliar 
     *                      [$column1,$column2,$expr] or mulit-dimensional-array
     * @param array|string $where  where array
     * @return \Toknot\Share\Model
     */
    public function rightJoin($table, $on, $where) {
        return $this->join($table, $on, $where, 'right');
    }

    /**
     * select at inner join
     * 
     * @param \Toknot\Share\Model|array $table
     * @param array $on
     * @param array|string $where
     * @return \Toknot\Share\Model
     */
    public function innerJoin($table, $on, $where) {
        return $this->join($table, $on, $where, 'inner');
    }

    /**
     * save data when exists primary key update
     * 
     * @param array $data
     * @return int
     */
    public function save($data) {
        $this->qr = $this->ready('insert');
        $insertValue = [];
        foreach ($data as $k => $v) {
            $placehold = ":i$k";
            $insertValue[$k] = $placehold;
            $this->setQueryArg($placehold, $v);
        }

        $this->qr->values($insertValue);
        $sql = $this->qr->getSQL();

        $sql .= ' ON DUPLICATE KEY UPDATE ';
        $update = [];
        foreach ($data as $key => $v) {
            if ($key == $this->key) {
                continue;
            }
            $hold = ":u{$key}";
            $this->setQueryArg($hold, $v);
            $update[] = $this->compKey([$key, $hold]);
        }

        $sql .= implode(',', $update);
        $this->lastSql = $sql;

        try {
            $this->conn->executeUpdate($sql, $this->qr->getParameters(), $this->qr->getParameterTypes());
        } catch (\Exception $e) {

            Kernel::single()->echoException($e);
        }
        return $this->conn->lastInsertId();
    }
    

    public function getList($where, $limit = 20, $start = 0) {
        return $this->select($where)->get($limit, $start);
    }

    public function getAscList($where, $orderby, $limit = 20, $start = 0) {
        return $this->select($where)->orderBy('asc', $orderby)
                        ->get($limit, $start);
    }

    public function getDescList($where, $orderby, $limit = 20, $start = 0) {
        return $this->select($where)->orderBy('desc', $orderby)
                        ->get($limit, $start);
    }

    public function getGroupList($where, $group, $limit = 20, $start = 0) {
        return $this->select($where)->groupBy($group)->get($limit, $start);
    }

}
