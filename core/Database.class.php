<?php

namespace App\Core;

use \PDO;

class Database
{
    // singleton object. Leave $me alone.
    private static $me;
    public static $queries = array();
    public $db = false;
    public $host;
    public $name;
    public $username;
    public $password;
    public $dieOnError;
    public $result = null;
    public $redirect = false;
    private $reconnectCount = 0;

    // Singleton constructor
    private function __construct($connect = false) {
        $this->host = _CONFIG_DB_HOST;
        $this->name = _CONFIG_DB_NAME;
        $this->username = _CONFIG_DB_USER;
        $this->password = _CONFIG_DB_PASS;
        $this->dieOnError = _CONFIG_DEBUG;
        if ($connect === true) {
            $this->connect();
        }
    }

    // Get Singleton object
    public static function getDatabase($connect = true, $forceReconnect = false) {
        if (is_null(self::$me) || $forceReconnect === true) {
            self::$me = new Database($connect);
        }

        return self::$me;
    }

    // Do we have a valid database connection?
    public function isConnected() {
        return is_object($this->db);
    }

    // Do we have a valid database connection and have we selected a database?
    public function databaseSelected() {
        if (!$this->isConnected()) {
            return false;
        }

        $result = $this->db->query("SHOW TABLES");

        return is_object($result);
    }

    public function connect() {
        // check if we already have a connection
        if ($this->db !== false && $this->isConnected()) {
            return true;
        }

        // check for the MySQL PDO driver
        if ($this->havePDODriver() == false) {
            $this->notify('PDO driver unavailable. Please contact your host to request '
                    . 'the MySQL PDO driver to be enabled within PHP.');
        }

        try {
            $this->db = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->name . ";charset=utf8", $this->username, $this->password);

            // catch errors if debug is enabled
            if (_CONFIG_DEBUG === true) {
                $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            }
        }
        catch (\Exception $e) {
            $this->notify('Failed connecting to the database with the supplied connection '
                    . 'details. Please check the details are correct and your MySQL user '
                    . 'has permissions to access this database.');
        }

        if ($this->isConnected()) {
            // disable strict mode in MySQL
            $this->db->exec("SET sql_mode = ''");

            // set utf8
            $this->db->exec("SET NAMES utf8");
        }

        return $this->isConnected();
    }

    public function reconnect() {
        $this->db = false;
        $this->reconnectCount++;

        return $this->connect();
    }

    public function close() {
        $this->result = null;
        self::closeDB();
    }

    public static function closeDB() {
        if (!is_null(self::$me)) {
            self::$me->db = null;
            self::$me = null;
        }
    }

    public function query($sQL, $args = null) {
        if (!$this->isConnected()) {
            $this->connect();
        }

        // allow for prepared arguments. Example:
        $sth = $this->db->prepare($sQL);
        $debugSql = $sQL;
        $params = array();
        if (is_array($args)) {
            foreach ($args AS $name => $val) {
                $params[':' . $name] = $val;

                $replacement = "'" . $val . "'";
                if (is_int($val)) {
                    $replacement = $val;
                }
                elseif ($val === null) {
                    $replacement = 'null';
                }

                $debugSql = preg_replace('/:\b' . $name . '\b/u', $replacement, $debugSql);
            }
        }

        $start = microtime();
        $startEx = explode(' ', $start);
        $start = $startEx[1] + $startEx[0];

        // track query
        $nextIndex = $this->numQueries();
        self::$queries[$nextIndex] = array(
            'sql' => $debugSql,
            'start' => $start,
        );
        try {
            $sth->execute($params);
        }
        catch (\PDOException $e) {
            if ($e->getCode() != 'HY000' || !stristr($e->getMessage(), 'server has gone away') || $this->reconnectCount >= 3) {
                $this->notify($e);
            }

            // if we have "PDOException: SQLSTATE[HY000]: General error: 2006 MySQL 
            // server has gone away", try to reconnect and re-run query
            $this->reconnect();

            return $this->query($sQL, $args);
        }
        $end = microtime();
        $endEx = explode(' ', $end);
        $end = $endEx[1] + $endEx[0];

        $total = number_format($end - $start, 6);
        self::$queries[$nextIndex]['end'] = $end;
        self::$queries[$nextIndex]['total'] = $total;

        $this->result = $sth;

        return $this->result;
    }

    // Returns the number of rows.
    // You can pass in nothing, a string, or a db result
    public function numRows($arg = null) {
        $result = $this->resulter($arg);

        return ($result !== false) ? $result->rowCount() : false;
    }

    // Returns true / false if the result has one or more rows
    public function hasRows($arg = null) {
        $result = $this->resulter($arg);

        return is_object($result) && ($result->rowCount() > 0);
    }

    // Returns the number of rows affected by the previous operation
    public function affectedRows() {
        if (!$this->isConnected()) {
            return false;
        }

        return $this->result->rowCount();
    }

    // Returns the auto increment ID generated by the previous insert statement
    public function insertId() {
        if (!$this->isConnected()) {
            return false;
        }

        $id = $this->db->lastInsertId();
        if ($id === 0 || $id === false) {
            return false;
        }

        return $id;
    }

    // Returns a single value.
    // You can pass in nothing, a string, or a db result
    public function getValue($arg = null, $args_to_prepare = array()) {
        $result = $this->resulter($arg, $args_to_prepare);
        $data = false;
        if ($result) {
            $row = $result->fetch(PDO::FETCH_NUM);
            if (is_array($row) && array_key_exists(0, $row)) {
                $data = $row[0];
            }
        }

        return $data;
    }

    // Returns the first row.
    // You can pass in nothing, a string, or a db result
    public function getRow($arg = null, $args_to_prepare = array(), $fetchType = PDO::FETCH_ASSOC) {
        $result = $this->resulter($arg, $args_to_prepare);
        $data = $result->fetch($fetchType);

        return $result->rowCount() ? $data : false;
    }

    // Returns an array of all the rows.
    // You can pass in nothing, a string, or a db result
    public function getRows($arg = null, $args_to_prepare = array(), $fetchType = PDO::FETCH_ASSOC) {
        $result = $this->resulter($arg, $args_to_prepare);
        $data = $result->fetchAll($fetchType);

        return $result->rowCount() ? $data : array();
    }

    // Escapes a value and wraps it in single quotes.
    public function quote($var) {
        if (!$this->isConnected()) {
            $this->connect();
        }

        return $this->db->quote($var);
    }

    // Escapes a value.
    public function escape($var) {
        if (!$this->isConnected()) {
            $this->connect();
        }

        $str = $this->db->quote($var);
        if (strlen($str) > 2) {
            $str = substr($str, 1, strlen($str) - 2);
        }

        return $str;
    }

    public function numQueries() {
        return count(self::$queries);
    }

    public function lastQuery() {
        if ($this->numQueries() > 0) {
            return self::$queries[$this->numQueries() - 1];
        }

        return false;
    }

    private function notify($errMsg = null) {
        if ($errMsg === null) {
            $errors = $this->db->errorInfo();
            $errMsg = implode(".", $errors);
        }
        error_log($errMsg);

        if ($this->dieOnError === true) {
            echo "<p style='border:5px solid red;background-color:#fff;padding:12px;font-family: verdana, sans-serif;'><strong>Database Error:</strong><br/>$errMsg</p>";
            $lastQuery = $this->lastQuery();
            if ($lastQuery !== false) {
                echo "<p style='border:5px solid red;background-color:#fff;padding:12px;font-family: verdana, sans-serif;'><strong>Last Rendered Query:</strong><br/>" . $lastQuery['sql'] . "</p>";
            }

            echo "<pre>";
            debug_print_backtrace();
            echo "</pre>";
            exit;
        }

        if (is_string($this->redirect)) {
            header("Location: {$this->redirect}");
            exit;
        }
    }

    // Takes nothing, a MySQL result, or a query string and returns
    // the correspsonding MySQL result resource or false if none available.
    private function resulter($arg = null, $args_to_prepare = array()) {
        if (is_null($arg) && is_object($this->result)) {
            return $this->result;
        }
        elseif (is_object($arg)) {
            return $arg;
        }
        elseif (is_string($arg)) {
            $this->query($arg, $args_to_prepare);
            if (is_object($this->result)) {
                return $this->result;
            }

            return false;
        }

        return false;
    }

    private function havePDODriver() {
        // check for pdo driver
        if (!class_exists('PDO')) {
            return false;
        }

        return true;
    }

    /**
     * To fetch Only the next row from the result data in form of [key][value] array.
     *
     * @access public
     * @return array|bool   false on if no data returned
     */
    public function fetchAssociative() {
        return $this->statement->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Counts the number of rows in a specific table
     *
     * @access public
     * @param   string  $table
     * @return  integer
     *
     */
    public function countAll($table) {
        return (int) $this->getValue('SELECT COUNT(*) AS count '
                        . 'FROM ' . $table);
    }

}
