<?php

namespace App\Core;

use App\Core\Database;

// base model class
class Model
{
    /**
     * Table name based on the Model
     *
     * @var string
     */
    public static $tableName = null;

    /**
     * Primary key column for our table
     *
     * @var string
     */
    public static $primaryKeyColumn = 'id';

    /**
     * constructor
     */
    public function __construct() {
        
    }

    public function get($param) {
        return $this->$param;
    }

    public function set($param, $value) {
        return $this->$param = $value;
    }

    public function save() {
        // should we update or insert the record, assuming we have an id we update
        $pKColumnName = $this->getPrimaryKeyColumn();
        if (property_exists($this, $pKColumnName) && isset($this->$pKColumnName)) {
            // update the record
            return $this->update();
        }

        // insert the records
        return $this->insert();
    }

    public function delete() {
        return self::deleteById($this->id);
    }

    private function update() {
        // get values of object properties
        $columns = $values = array();
        foreach ($this as $pName => $pVal) {
            $prop = new \ReflectionProperty($this, $pName);
            if ($prop->isPublic() && $pName != $this->getPrimaryKeyColumn()) {
                $columnName = $this->escapeIdentifier($pName);
                $columns[] = $columnName;
                $values[$columnName] = $pVal;
            }
        }

        // ensure we append the id column at the end
        $values[$this->getPrimaryKeyColumn()] = $this->{$this->getPrimaryKeyColumn()};

        // prep the update columns
        $cmd = array();
        foreach ($columns as $k => $column) {
            $cmd[] = $column . ' = :' . $column;
        }

        // append the PK onto the replacements
        $values[$this->getPrimaryKeyColumn()] = $this->{$this->getPrimaryKeyColumn()};

        // prepare query
        $sQL = 'UPDATE ' . self::getTableName() . ' '
                . 'SET ' . implode(', ', $cmd) . ' '
                . 'WHERE ' . $this->getPrimaryKeyColumn() . ' =:' . $this->getPrimaryKeyColumn() . ' '
                . 'LIMIT 1';

        $db = Database::getDatabase();
        $db->query($sQL, $values);

        return $db->affectedRows();
    }

    private function insert() {
        // get values of object properties
        $columns = $values = array();
        foreach ($this as $pName => $pVal) {
            $prop = new \ReflectionProperty($this, $pName);
            if ($prop->isPublic()) {
                $columnName = $this->escapeIdentifier($pName);
                $columns[] = $columnName;
                $values[$columnName] = $pVal;
            }
        }

        // prepare query
        $sQL = 'INSERT INTO ' . self::getTableName() . ' '
                . '(' . implode(', ', $columns) . ') VALUES '
                . '(:' . implode(', :', $columns) . ')';
        $db = Database::getDatabase();
        $db->query($sQL, $values);

        // save the id for later
        $pKColumnName = $this->getPrimaryKeyColumn();
        $this->$pKColumnName = $db->insertId();

        return $this->$pKColumnName;
    }

    /*
     * escape a given identifier
     * @param string $name
     * @return string
     */

    private function escapeIdentifier($name) {
        $name = strval($name);
        $name = str_replace(array(chr(0), "\n", "\r", "\t", "'", "\""), "", $name);

        return $name;
    }

    /**
     * STATIC METHODS
     */

    /**
     * Used to create an empty model for inserting a new record
     * 
     * @return model
     */
    public static function create() {
        return self::getModel();
    }

    /**
     * Load one model based on the data primary key
     * 
     * @param type $id
     * @return model
     */
    public static function loadOneById($id) {
        // load our row based on the primary key column
        return self::loadOne(self::getPrimaryKeyColumn(), $id);
    }

    /**
     * Load one model based on a matching column and value
     * 
     * @param type $column
     * @param type $value
     * @return mixed
     */
    public static function loadOne($column, $value) {
        // create our sql for the lookup
        $sQL = 'SELECT * '
                . 'FROM `%s` '
                . 'WHERE `%s` = :value '
                . 'LIMIT 1';
        $sQL = sprintf($sQL, self::getTableName(), $column);

        // execute the SQL on the database
        $db = Database::getDatabase();
        $rs = $db->getRow($sQL, array(
            'value' => $value,
        ));

        if (!$rs) {
            // handle no results
            return false;
        }

        // map our data to the relevant model object and return
        return self::hydrateSingleRecord($rs);
    }

    public static function loadAll($orderBy = null) {
        // create our sql for the lookup
        $sQL = 'SELECT * '
                . 'FROM `%s` ';
        if (strlen($orderBy)) {
            $sQL .= 'ORDER BY ' . $orderBy;
        }
        $sQL = sprintf($sQL, self::getTableName());

        // execute the SQL on the database
        $db = Database::getDatabase();
        $rs = $db->getRows($sQL);

        if (!$rs) {
            // handle no results
            return false;
        }

        // map our data to the relevant model object and return
        return self::hydrateAllRecords($rs);
    }

    public static function loadByClause($clauseStr, $replacements = array(), $orderBy = null, $limit = null) {
        // create our sql for the lookup
        $sQL = 'SELECT * '
                . 'FROM `%s` ';
        $sQL .= 'WHERE ' . $clauseStr;
        if (strlen($orderBy)) {
            $sQL .= ' ORDER BY ' . $orderBy;
        }
        if ((int)$limit) {
            $sQL .= ' LIMIT ' . (int)$limit;
        }
        $sQL = sprintf($sQL, self::getTableName());

        // execute the SQL on the database
        $db = Database::getDatabase();
        $rs = $db->getRows($sQL, $replacements);

        if (!$rs) {
            // handle no results
            return false;
        }

        // map our data to the relevant model object and return
        return self::hydrateAllRecords($rs);
    }
    
    public static function loadOneByClause($clauseStr, $replacements = array()) {
        // create our sql for the lookup
        $sQL = 'SELECT * '
                . 'FROM `%s` ';
        $sQL .= 'WHERE ' . $clauseStr . ' ';
        $sQL = sprintf($sQL, self::getTableName());

        // execute the SQL on the database
        $db = Database::getDatabase();
        $rs = $db->getRow($sQL, $replacements);

        if (!$rs) {
            // handle no results
            return false;
        }

        // map our data to the relevant model object and return
        return self::hydrateSingleRecord($rs);
    }

    public static function count($clauseStr = '', $replacements = array()) {
        // create our sql for the lookup
        $sQL = 'SELECT COUNT(*) AS `count` '
                . 'FROM `%s` ';
        if (strlen($clauseStr) > 0) {
            $sQL .= 'WHERE ' . $clauseStr;
        }
        $sQL = sprintf($sQL, self::getTableName());

        // execute the SQL on the database
        $db = Database::getDatabase();
        $rs = $db->getRow($sQL, $replacements);

        if (!$rs) {
            // handle no results
            return false;
        }

        // return the total items
        return (int) $rs['count'];
    }

    public static function sum($sumColumn, $clauseStr = '', $replacements = array()) {
        // create our sql for the lookup
        $sQL = 'SELECT SUM(`%s`) AS `total` '
                . 'FROM `%s` ';
        if (strlen($clauseStr) > 0) {
            $sQL .= 'WHERE ' . $clauseStr;
        }
        $sQL = sprintf($sQL, $sumColumn, self::getTableName());

        // execute the SQL on the database
        $db = Database::getDatabase();
        $rs = $db->getRow($sQL, $replacements);

        if (!$rs) {
            // handle no results
            return false;
        }

        // return the total items
        return (int) $rs['total'];
    }

    public static function hydrateAllRecords($rows) {
        // prep response query
        $rs = array();

        // loop results and create our objects
        foreach ($rows AS $row) {
            $rs[] = self::hydrateSingleRecord($row);
        }

        return $rs;
    }

    public static function hydrateSingleRecord($row) {
        // instantiate our model object
        $obj = self::getModel();

        // loop results and store our data as properties on the object
        foreach ($row AS $col => $val) {
            // only do cols with ctype_alnum
            if(preg_match('/^[a-zA-Z0-9\_\-]+$/', $col)) {
                $obj->{$col} = $val;
            }
        }

        return $obj;
    }

    public static function getModel() {
        $modelName = get_called_class();

        return new $modelName();
    }

    public static function getPrimaryKeyColumn() {
        return self::$primaryKeyColumn;
    }

    public static function setPrimaryKeyColumn($primaryKeyColumn) {
        self::$primaryKeyColumn = $primaryKeyColumn;
    }

    /**
     * get table name based on class name
     *
     * @return  string
     */
    public static function getTableName() {
        // first check for the $tableName static property set in the parent class
        $parentClass = new \ReflectionProperty(get_called_class(), 'tableName');
        if (strlen($parentClass->getValue())) {
            return $parentClass->getValue();
        }

        // if the $tableName is not set, assume the parent class name matches the
        // database table
        $tableName = str_replace('\\', DS, get_called_class());
        $tableName = basename($tableName);
        $tableName = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $tableName));

        return $tableName;
    }

    /**
     * delete record by id
     *
     * @param  string $id
     * @return bool
     */
    public static function deleteById($id) {
        return self::deleteByClause('id = :id', array(
                    'id' => $id
        ));
    }

    public static function deleteByClause($clauseStr, $replacements = array()) {
        // create our sql for the lookup
        $sQL = 'DELETE '
                . 'FROM `%s` ';
        $sQL .= 'WHERE ' . $clauseStr;
        $sQL = sprintf($sQL, self::getTableName());

        // execute the SQL on the database
        $db = Database::getDatabase();
        $rs = $db->query($sQL, $replacements);

        if (!$rs) {
            // handle failure
            return false;
        }

        // record deleted
        return true;
    }
    
    public static function loadAllAsAssocArray($orderBy = null, $labelColumn = 'label', $idColumn = 'id') {
        // create our sql for the lookup
        $rows = static::loadAll($orderBy);
        $rs = array();
        if($rows) {
            foreach($rows AS $row) {
                $rs[$row->$idColumn] = $row->$labelColumn;
            }
        }
        
        return $rs;
    }

    /**
     * get errors
     *
     * @return array errors
     */
    public function errors() {
        return $this->errors;
    }

}
