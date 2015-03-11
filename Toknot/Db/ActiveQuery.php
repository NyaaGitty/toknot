<?php

/**
 * Toknot (http://toknot.com)
 *
 * @copyright  Copyright (c) 2011 - 2015 Toknot.com
 * @license    http://toknot.com/LICENSE.txt New BSD License
 * @link       https://github.com/chopins/toknot
 */

namespace Toknot\Db;

use Toknot\Boot\ArrayObject;

class ActiveQuery {

    const ORDER_ASC = 'ASC';
    const ORDER_DESC = 'DESC';
    const READ = 'SELECT';
    const UPDATE = 'UPDATE';
    const COLUMN_SET = 'SET';
    const INSERT = 'INSERT';
    const DELETE = 'DELETE';
    const JOIN = 'JOIN';
    const CREATE = 'CREATE';
    const LOGICAL_AND = 'AND';
    const LOGICAL_OR = 'OR';
    const EQUAL = '=';
    const LESS_THAN = '<';
    const GREATER_THAN = '>';
    const LESS_OR_EQUAL = '<=';
    const GREATER_OR_EQUAL = '>=';
    const SHOW_TABLES = 'SHOW TABLES';
    const FETCH_ASSOC = 'ASSOC';
    const FETCH_NUM = 'NUM';
    const FETCH_BOTH = 'BOTH';
    const FETCH_OBJ = 'OBJ';
    const DRIVER_MYSQL = 'mysql';
    const DRIVER_SQLITE = 'sqlite';

    private static $dbDriverType = self::DRIVER_MYSQL;

    public static function setDbDriverType($type) {
        self::$dbDriverType = $type;
    }

    public static function getDbDriverType() {
        return self::$dbDriverType;
    }

    public static function parseSQLiteColumn($sql) {
        strtok($sql, '(');
        $feildInfo = strtok(')');
        $columnList = array();
        $columnList[] = strtok($feildInfo, ' ');
        $pro = strtok(',');
        while ($pro) {
            $feild = strtok(' ');
            if (!$feild) {
                break;
            }
            $columnList[] = $feild;
            $pro = strtok(',');
        }
        return $columnList;
    }

    public static function createTable($tableName) {
        $tableName = self::backtick($tableName);
        if (self::$dbDriverType == self::DRIVER_MYSQL) {
            return "CREATE TABLE IF NOT EXISTS $tableName";
        }
        return "CREATE TABLE $tableName";
    }

    public static function setColumn(&$table) {
        $columnList = $table->showSetColumnList();
        $sqlList = array();
        foreach ($columnList as $columnName => $column) {
            $sqlList[$columnName] = " $columnName {$column->type}";
            if ($column->length > 0) {
                $sqlList[$columnName] .= "($column->length)";
            }
            if ($column->isPK) {
                $sqlList[$columnName] .= ' primary key';
            }
            if ($column->autoIncrement) {
                $sqlList[$columnName] .= ' autoincrement';
            }
        }
        return '(' . implode(',', $sqlList) . ')';
    }

    public static function select($tableName, $field = '*') {
        $tableName = self::backtick($tableName);
        return "SELECT $field FROM $tableName";
    }

    public static function bindParams($params, $sql) {
        if (empty($params)) {
            return $sql;
        }
        if(self::$dbDriverType == self::DRIVER_MYSQL) {
            $fn = 'mysql_real_escape_string';
        } elseif(self::$dbDriverType == self::DRIVER_SQLITE) {
            $fn = 'sqlite_escape_string';
        } else {
            $fn = 'addslashes';
        }
        foreach ($params as &$v) {
            $v = "'" . $fn($v) . "'";
        }
        return str_replace('?', $params, $sql);
    }

    public static function field(array $array) {
        return implode(',', $array);
    }

    public static function update($tableName) {
        $tableName = self::backtick($tableName);
        return "UPDATE $tableName SET";
    }

    public static function set($field) {
        $setList = array();
        foreach ($field as $key => $val) {
            $key = self::backtick($key);;
            $setList = "$key='" . $val . "'";
        }
        return ' ' . implode(',', $setList);
    }

    public static function delete($tableName) {
        $tableName = self::backtick($tableName);
        return "DETELE FROM $tableName";
    }

    public static function leftJoin($tableName, $alias) {
        $tableName = self::backtick($tableName);;
        return " LEFT JOIN $tableName AS $alias";
    }

    public static function on($key1, $key2) {
        $key1 = self::backtick($key1);;
        $key2 = self::backtick($key2);
        return " ON $key1=$key2";
    }

    public static function alias($name, $alias) {
        $name = self::backtick($name);
        return " $name AS $alias";
    }

    public static function transformDsn($dsn) {
        $config = new ArrayObject;
        $str = strtok($dsn, ':');
        $config->type = $str;
        while ($str) {
            $str = strtok('=');
            $config->$str = strtok(';');
        }
        return $config;
    }

    public static function showColumnList($tableName) {
        if (self::$dbDriverType == self::DRIVER_SQLITE) {
            return "SELECT * FROM sqlite_master WHERE type='table' AND name='$tableName'";
        }
        return "SHOW COLUMNS FROM $tableName";
    }

    public static function showTableList($database = null) {
        if (self::$dbDriverType == self::DRIVER_SQLITE) {
            return "SELECT name FROM sqlite_master WHERE type='table' ORDER BY name";
        }
        if ($database == null) {
            return "SHOW TABLES";
        }
        return "SHOW TABLES FROM $database";
    }

    public static function limit($start, $limit = null) {
        if ($limit === null) {
            return " LIMIT {$start}";
        } else {
            $limit = (int) $limit;
            return " LIMIT {$start},{$limit}";
        }
    }

    public static function conditionLimit($condition, $start, $limit) {
        switch ($condition) {
            case ActiveQuery::EQUAL:
                return ActiveQuery::limit(0, 1);
            case ActiveQuery::LESS_OR_EQUAL:
            case ActiveQuery::LESS_THAN:
            case ActiveQuery::GREATER_OR_EQUAL:
            case ActiveQuery::GREATER_THAN:
                return ActiveQuery::limit($start, $limit);
            default:
                throw new InvalidArgumentException('Condition must be ActiveQuery defined opreater of comparison');
        }
    }

    public static function order($order, $field) {
        if ($field == NULL) {
            return '';
        }
        if ($order == self::ORDER_ASC) {
            return " ORDER BY $field ASC";
        } else {
            return " ORDER BY $field DESC";
        }
    }

    public static function where($sql = 1) {
        return " WHERE $sql";
    }

    public static function bindTableAlias($alias, $columnList) {
        return ' ' . $alias . '.`' . implode("`, $alias.`", $columnList).'`';
    }

    public static function insert($tableName, $field) {
        $field = '`' . implode('`,`', keys($field)) . '`';
        $values = "'" . implode("','", $field) . "'";
        return "INSERT INTO $tableName ($field) VALUES($values)";
    }

    public static function updateDecrement($field, $num = 1, $negative = false) {
        $field = self::backtick($field);
        if ($negative) {
            return " $field = $field - $num";
        }
        return " $field = IF(($field - $num)>0,($field-$num),0)";
    }

    public static function updateIncrement($field, $num = 1) {
        $field = self::backtick($field);
        return " $field = $field + $num";
    }

    public static function backtick($field) {
        return '`' . str_replace('.', '`.`', $field) . '`';
    }

}