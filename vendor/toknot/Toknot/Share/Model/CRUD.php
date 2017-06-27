<?php

/**
 * Toknot (http://toknot.com)
 *
 * @copyright  Copyright (c) 2011 - 2017 Toknot.com
 * @license    http://toknot.com/LICENSE.txt New BSD License
 * @link       https://github.com/chopins/toknot
 */

namespace Toknot\Share\Model;

use Toknot\Share\DB\DBA;

/**
 * CRUD
 *
 */
class CRUD {

    protected $table = null;
    protected $defaultLimt = 50;
    protected $casFieldName = 'ver';

    public function __construct($table) {
        $this->table = DBA::table($table);
    }

    public function getTable() {
        return $this->table;
    }

    public function setCasFieldName($name) {
        $this->casFieldName = $name;
    }

    public function getCasFieldName($name) {
        return $this->casFieldName;
    }

    public function setDefaultLimit($limit) {
        $this->defaultLimt = $limit;
    }

    public function getDefaultLimit() {
        return $this->defaultLimt;
    }

    public function create($row) {
        return $this->table->insert($row);
    }

    public function findByKey($keyValue) {
        return $this->table->findKeyRow($keyValue);
    }

    public function delByKey($keyValue) {
        return $this->table->deleteKeyRow($keyValue);
    }

    public function updateByKey($row, $keyValue) {
        $where = $this->table->buildKeyWhere($keyValue);
        return $this->table->update($row, $where, 1);
    }

    protected function queryLimit(&$limit) {
        return $limit === null ? $this->defaultLimt : $limit;
    }

    public function read($where = 1, $limit = null, $offset = 0) {
        $this->queryLimit($limit);
        return $this->table->getList($where, $limit, $offset);
    }

    public function update($row, $where = 1, $limit = null, $start = 0) {
        $this->queryLimit($limit);
        return $this->table->update($row, $where, $limit, $start);
    }

    public function delete($where, $limit = null, $start = 0) {
        $this->queryLimit($limit);
        return $this->table->delete($where, $limit, $start);
    }

    public function casFindByKey($keyValue, &$cas = 0) {
        $row = $this->findByKey($keyValue);
        $cas = $row[$this->casFieldName];
        return $row;
    }

    public function addCasWhere($keyValue, $cas) {
        $keyWhere = $this->table->buildKeyWhere($keyValue);
        $casWhere = $this->table->cols($this->casFieldName)->eq($cas);
        return $this->table->filter()->andX($keyWhere, $casWhere);
    }

    public function casUpdateByKey($row, $keyValue, $cas) {
        return $this->update($row, $this->addCasWhere($keyValue, $cas));
    }

    public function casDelByKey($keyValue, $cas) {
        return $this->delete($this->addCasWhere($keyValue, $cas));
    }

}
