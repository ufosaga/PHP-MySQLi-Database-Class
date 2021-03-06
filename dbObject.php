<?php
/**
 * Mysqli Model wrapper
 *
 * @category  Database Access
 * @package   MysqliDb
 * @author    Alexander V. Butenko <a.butenka@gmail.com>
 * @copyright Copyright (c) 2015
 * @license   http://opensource.org/licenses/gpl-3.0.html GNU Public License
 * @link      http://github.com/joshcam/PHP-MySQLi-Database-Class 
 * @version   2.6-master
 *
 * @method dbObject query ($query, $numRows)
 * @method dbObject rawQuery ($query, $bindParams, $sanitize)
 * @method dbObject groupBy (string $groupByField)
 * @method dbObject orderBy ($orderByField, $orderbyDirection, $customFields)
 * @method dbObject where ($whereProp, $whereValue, $operator)
 * @method dbObject orWhere ($whereProp, $whereValue, $operator)
 * @method dbObject setQueryOption ($options)
 * @method dbObject setTrace ($enabled, $stripPrefix)
 * @method dbObject withTotalCount ()
 * @method dbObject startTransaction ()
 * @method dbObject commit ()
 * @method dbObject rollback ()
 * @method dbObject ping ()
 **/
abstract class dbObject {
    /**
     * Working instance of MysqliDb created earlier
     *
     * @var MysqliDb
     */
    private static $db;
    /**
     * Models path
     *
     * @var string modelPath
     */
    protected static $modelPath;
    /**
     * An array that holds object data
     *
     * @var array
     */
    public $data;
    /**
     * Flag to define is object is new or loaded from database
     *
     * @var boolean
     */
    public $isNew = true;
    /**
     * Return type: 'Array' to return results as array, 'Object' as object
     * 'Json' as json string
     *
     * @var string
     */
    public $returnType = 'Object';
    /**
     * An array that holds has* objects which should be loaded togeather with main
     * object togeather with main object
     *
     * @var array
     */
    private $_with = Array();
    /**
     * Per page limit for pagination
     *
     * @var int
     */
    /**
     * An array that holds the changes flag for keys
     *
     * @var array
     */
    private $_changeFlags = array();

    public static $pageLimit = 20;
    /**
     * Variable that holds total pages count of last paginate() query
     *
     * @var int
     */
    public static $totalPages = 0;
    /**
     * An array that holds insert/update/select errors
     *
     * @var array
     */
    public $errors = null;
    /**
     * Primary key for an object. 'id' is a default value.
     *
     * @var string
     */
    protected static $primaryKey = 'id';
    /**
     * Table name for an object. Class name will be used by default
     *
     * @var string
     */
    protected static $dbTable;

    /**
     * An array that holds the db fields
     *
     * @var array
     */
    protected static $dbFields;

    /**
     * @param array $data Data to preload on object creation
     */
    public function __construct ($data = null) {
        if (!self::$db)
            self::$db = MysqliDb::getInstance();

        if (!static::$dbTable)
            static::$dbTable = get_class ($this);

        if ($data)
            $this->data = $data;
    }

    /**
     * Magic setter function
     * @param string $name
     * @param mixed $value
     * @return mixed
     */
    public function __set ($name, $value) {
        if (isset($this->data[$name])) {
            $oldValue = $this->data[$name];
        }

        if (!isset($oldValue) || $oldValue != $value) {
            if (isset($this->_changeFlags[$name])) {
                $this->_changeFlags[$name] += 1;
            } else {
                $this->_changeFlags[$name] = 1;
            }
        }

        $this->data[$name] = $value;
    }

    /**
     * Magic getter function
     *
     * @param string $name Variable name
     *
     * @return mixed
     */
    public function __get ($name) {
        if (isset ($this->data[$name]) && $this->data[$name] instanceof dbObject)
            return $this->data[$name];

        if (property_exists ($this, 'relations') && isset ($this->relations[$name])) {
            $relationType = strtolower ($this->relations[$name][0]);
            $modelName = $this->relations[$name][1];
            switch ($relationType) {
                case 'hasone':
                    $key = isset ($this->relations[$name][2]) ? $this->relations[$name][2] : $name;
                    $obj = new $modelName;
                    $obj->returnType = $this->returnType;
                    return $this->data[$name] = $obj->byId($this->data[$key]);
                    break;
                case 'hasmany':
                    $key = $this->relations[$name][2];
                    $obj = new $modelName;
                    $obj->returnType = $this->returnType;
                    return $this->data[$name] = $obj->where($key, $this->data[static::$primaryKey])->get();
                    break;
                default:
                    break;
            }
        }

        if (isset ($this->data[$name])) {
            return $this->data[$name];
        }

        if (property_exists (self::$db, $name))
            return self::$db->$name;
    }

    public function __isset ($name) {
        if (isset ($this->data[$name]))
            return isset ($this->data[$name]);

        if (property_exists (self::$db, $name))
            return isset (self::$db->$name);
    }

    public function __unset ($name) {
        unset ($this->data[$name]);
    }

    /**
     * Helper function to create dbObject with Json return type
     *
     * @return dbObject
     */
    private function JsonBuilder () {
        $this->returnType = 'Json';
        return $this;
    }

    /**
     * Helper function to create dbObject with Array return type
     *
     * @return dbObject
     */
    private function ArrayBuilder () {
        $this->returnType = 'Array';
        return $this;
    }

    /**
     * Helper function to create dbObject with Object return type.
     * Added for consistency. Works same way as new $objname ()
     *
     * @return dbObject
     */
    private function ObjectBuilder () {
        $this->returnType = 'Object';
        return $this;
    }

    /**
     * Helper function to create a virtual table class
     *
     * @param string $tableName Table name
     * @return dbObject
     */
    public static function table ($tableName) {
        $tableName = preg_replace ("/[^-a-z0-9_]+/i",'', $tableName);
        if (!class_exists ($tableName))
            eval ("class $tableName extends dbObject {}");
        return new $tableName ();
    }
    /**
     * @return mixed insert id or false in case of failure
     */
    public function insert () {
        if (!empty ($this->timestamps) && in_array ("createdAt", $this->timestamps))
            $this->createdAt = date("Y-m-d H:i:s");
        $sqlData = $this->prepareData ();
        if (!$this->validate ($sqlData))
            return false;

        $id = self::$db->insert (static::$dbTable, $sqlData);
        if (!empty (static::$primaryKey) && empty ($this->data[static::$primaryKey]))
            $this->data[static::$primaryKey] = $id;
        $this->isNew = false;

        return $id;
    }

    /**
     * @param array $data Optional update data to apply to the object
     */
    public function update ($data = null) {
        if (empty (self::$db))
            return false;

        if (empty ($this->data[static::$primaryKey]))
            return false;

        if ($data) {
            foreach ($data as $k => $v)
                $this->$k = $v;
        }

        if (!empty ($this->timestamps) && in_array ("updatedAt", $this->timestamps))
            $this->updatedAt = date("Y-m-d H:i:s");

        $sqlData = $this->prepareData ();

        if (count($sqlData) < 2)
            return true;

        if (!$this->validate ($sqlData))
            return false;

        self::$db->where (static::$primaryKey, $this->data[static::$primaryKey]);
        return self::$db->update (static::$dbTable, $sqlData);
    }

    /**
     * Save or Update object
     *
     * @return mixed insert id or false in case of failure
     */
    public function save ($data = null) {
        if ($this->isNew)
            return $this->insert();
        return $this->update ($data);
    }

    /**
     * Delete method. Works only if object primaryKey is defined
     *
     * @return boolean Indicates success. 0 or 1.
     */
    public function delete () {
        if (empty ($this->data[static::$primaryKey]))
            return false;

        self::$db->where (static::$primaryKey, $this->data[static::$primaryKey]);
        return self::$db->delete (static::$dbTable);
    }

    /**
     * Get object by primary key.
     *
     * @access public
     * @param int $id Primary Key
     * @param array|string $fields Array or coma separated list of fields to fetch
     *
     * @return dbObject|array
     */
    protected function byId ($id, $fields = null) {
        self::$db->where (MysqliDb::$prefix . static::$dbTable . '.' . static::$primaryKey, $id);
        return $this->getOne ($fields);
    }

    /**
     * Convinient function to fetch one object. Mostly will be togeather with where()
     *
     * @access public
     * @param array|string $fields Array or coma separated list of fields to fetch
     *
     * @return dbObject
     */
    protected function getOne ($fields = null) {
        $this->processHasOneWith ();
        $results = self::$db->ArrayBuilder()->getOne (static::$dbTable, $fields);
        if (self::$db->count == 0)
            return null;

        $this->processArrays ($results);
        $this->data = $results;
        $this->processAllWith ($results);
        if ($this->returnType == 'Json')
            return json_encode ($results);
        if ($this->returnType == 'Array')
            return $results;

        $item = new static ($results);
        $item->isNew = false;

        return $item;
    }

    /**
     * Fetch all objects
     *
     * @access public
     * @param integer|array $limit Array to define SQL limit in format Array ($count, $offset)
     *                             or only $count
     * @param array|string $fields Array or coma separated list of fields to fetch
     *
     * @return array Array of dbObjects
     */
    protected function get ($limit = null, $fields = null) {
        $objects = Array ();
        $this->processHasOneWith ();
        $results = self::$db->ArrayBuilder()->get (static::$dbTable, $limit, $fields);
        if (self::$db->count == 0)
            return null;

        foreach ($results as &$r) {
            $this->processArrays ($r);
            $this->data = $r;
            $this->processAllWith ($r, false);
            if ($this->returnType == 'Object') {
                $item = new static ($r);
                $item->isNew = false;
                $objects[] = $item;
            }
        }
        $this->_with = Array();
        if ($this->returnType == 'Object')
            return $objects;

        if ($this->returnType == 'Json')
            return json_encode ($results);

        return $results;
    }

    /**
     * Function to set witch hasOne or hasMany objects should be loaded togeather with a main object
     *
     * @access public
     * @param string $objectName Object Name
     *
     * @return dbObject
     */
    private function with ($objectName) {
        if (!property_exists ($this, 'relations') && !isset ($this->relations[$objectName]))
            die ("No relation with name $objectName found");

        $this->_with[$objectName] = $this->relations[$objectName];

        return $this;
    }

    /**
     * Function to join object with another object.
     *
     * @access public
     * @param string $objectName Object Name
     * @param string $key Key for a join from primary object
     * @param string $joinType SQL join type: LEFT, RIGHT,  INNER, OUTER
     * @param string $primaryKey SQL join On Second primaryKey
     *
     * @return dbObject
     */
    private function join ($objectName, $key = null, $joinType = 'LEFT', $primaryKey = null) {
        $joinObj = new $objectName;
        if (!$key)
            $key = $objectName . "id";

        if (!$primaryKey)
            $primaryKey = MysqliDb::$prefix . $joinObj->dbTable . "." . $joinObj->primaryKey;
		
        if (!strchr ($key, '.'))
            $joinStr = MysqliDb::$prefix . static::$dbTable . ".{$key} = " . $primaryKey;
        else
            $joinStr = MysqliDb::$prefix . "{$key} = " . $primaryKey;

        self::$db->join ($joinObj->dbTable, $joinStr, $joinType);
        return $this;
    }

    /**
     * Function to get a total records count
     *
     * @return int
     */
    protected function count () {
        $res = self::$db->ArrayBuilder()->getValue (static::$dbTable, "count(*)");
        if (!$res)
            return 0;
        return $res;
    }

    /**
     * Pagination wraper to get()
     *
     * @access public
     * @param int $page Page number
     * @param array|string $fields Array or coma separated list of fields to fetch
     * @return array
     */
    private function paginate ($page, $fields = null) {
        self::$db->pageLimit = self::$pageLimit;
        $res = self::$db->paginate (static::$dbTable, $page, $fields);
        self::$totalPages = self::$db->totalPages;
        return $res;
    }

    /**
     * Catches calls to undefined methods.
     *
     * Provides magic access to private functions of the class and native public mysqlidb functions
     *
     * @param string $method
     * @param mixed $arg
     *
     * @return mixed
     */
    public function __call ($method, $arg) {
        if (method_exists ($this, $method))
            return call_user_func_array (array ($this, $method), $arg);

        call_user_func_array (array (self::$db, $method), $arg);
        return $this;
    }

    /**
     * Catches calls to undefined static methods.
     *
     * Transparently creating dbObject class to provide smooth API like name::get() name::orderBy()->get()
     *
     * @param string $method
     * @param mixed $arg
     *
     * @return mixed
     */
    public static function __callStatic ($method, $arg) {
        $obj = new static;
        $result = call_user_func_array (array ($obj, $method), $arg);
        if (method_exists ($obj, $method))
            return $result;
        return $obj;
    }

    /**
     * Converts object data to an associative array.
     *
     * @return array Converted data
     */
    public function toArray () {
        $data = $this->data;
        $this->processAllWith ($data);
        foreach ($data as &$d) {
            if ($d instanceof dbObject)
                $d = $d->data;
        }
        return $data;
    }

    /**
     * Converts object data to a JSON string.
     *
     * @return string Converted data
     */
    public function toJson () {
        return json_encode ($this->toArray());
    }

    /**
     * Converts object data to a JSON string.
     *
     * @return string Converted data
     */
    public function __toString () {
        return $this->toJson ();
    }

    /**
     * Function queries hasMany relations if needed and also converts hasOne object names
     *
     * @param array $data
     */
    private function processAllWith (&$data, $shouldReset = true) {
        if (count ($this->_with) == 0)
            return;

        foreach ($this->_with as $name => $opts) {
            $relationType = strtolower ($opts[0]);
            $modelName = $opts[1];
            if ($relationType == 'hasone') {
                $obj = new $modelName;
                $table = $obj->dbTable;
                $primaryKey = $obj->primaryKey;

                if (!isset ($data[$table])) {
                    $data[$name] = $this->$name;
                    continue;
                }
                if ($data[$table][$primaryKey] === null) {
                    $data[$name] = null;
                } else {
                    if ($this->returnType == 'Object') {
                        $item = new $modelName ($data[$table]);
                        $item->returnType = $this->returnType;
                        $item->isNew = false;
                        $data[$name] = $item;
                    } else {
                        $data[$name] = $data[$table];
                    }
                }
                unset ($data[$table]);
            }
            else
                $data[$name] = $this->$name;
        }
        if ($shouldReset)
            $this->_with = Array();
    }

    /*
     * Function building hasOne joins for get/getOne method
     */
    private function processHasOneWith () {
        if (count ($this->_with) == 0)
            return;
        foreach ($this->_with as $name => $opts) {
            $relationType = strtolower ($opts[0]);
            $modelName = $opts[1];
            $key = null;
            if (isset ($opts[2]))
                $key = $opts[2];
            if ($relationType == 'hasone') {
                self::$db->setQueryOption ("MYSQLI_NESTJOIN");
                $this->join ($modelName, $key);
            }
        }
    }

    /**
     * @param array $data
     */
    private function processArrays (&$data) {
        if (isset ($this->jsonFields) && is_array ($this->jsonFields)) {
            foreach ($this->jsonFields as $key)
                $data[$key] = json_decode ($data[$key]);
        }

        if (isset ($this->arrayFields) && is_array($this->arrayFields)) {
            foreach ($this->arrayFields as $key)
                $data[$key] = explode ("|", $data[$key]);
        }
    }

    /**
     * @param array $data
     */
    private function validate ($data) {
        if (!static::$dbFields)
            return true;

        if (count($data) < 2) {
            $this->errors[] = 'Invalid fields count:' . var_export($data, true);
            return false;
        }

        foreach (static::$dbFields as $key => $desc) {
            $type = null;
            $required = false;
            if (isset ($data[$key]))
                $value = $data[$key];
            else
                $value = null;

            if (is_array ($value))
                continue;

            if (isset ($desc[0]))
                $type = $desc[0];
            if (isset ($desc[1]) && ($desc[1] == 'required'))
                $required = true;

//            if ($required && strlen ($value) == 0) {
//                $this->errors[] = Array (static::$dbTable . "." . $key => "is required");
//                continue;
//            }

            if ($value == null)
                continue;

            switch ($type) {
            case "text";
                $regexp = null;
                break;
            case "int":
                $regexp = "/^[0-9]*$/";
                break;
            case "double":
                $regexp = "/^[0-9\.]*$/";
                break;
            case "bool":
                $regexp = '/^[yes|no|0|1|true|false]$/i';
                break;
            case "datetime":
                $regexp = "/^[0-9a-zA-Z -:]*$/";
                break;
            default:
                $regexp = $type;
                break;
            }
            if (!$regexp)
                continue;

            if (!preg_match ($regexp, $value)) {
                $this->errors[] = Array (static::$dbTable . "." . $key => "$type validation failed");
                continue;
            }
        }
        return !count ($this->errors) > 0;
    }

    private function prepareData () {
        $this->errors = Array ();
        $sqlData = Array();
        if (count ($this->data) == 0)
            return Array();

        if (method_exists ($this, "preLoad"))
            $this->preLoad ($this->data);

        if (!static::$dbFields)
            return $this->data;

        $this->_changeFlags[static::$primaryKey] = 1;
        
        foreach ($this->data as $key => &$value) {
            if ($value === null || !isset($this->_changeFlags[$key]) || !$this->_changeFlags[$key])
                continue;
            $this->_changeFlags[$key] -= 1;
            if ($value instanceof dbObject && $value->isNew == true) {
                $id = $value->save();
                if ($id)
                    $value = $id;
                else
                    $this->errors = array_merge ($this->errors, $value->errors);
            }

            if (!in_array ($key, array_keys (static::$dbFields)))
                continue;

            if (!is_array($value)) {
                $sqlData[$key] = $value;
                continue;
            }

            if (isset ($this->jsonFields) && in_array ($key, $this->jsonFields))
                $sqlData[$key] = json_encode($value);
            else if (isset ($this->arrayFields) && in_array ($key, $this->arrayFields))
                $sqlData[$key] = implode ("|", $value);
            else
                $sqlData[$key] = $value;
        }
        return $sqlData;
    }

    private static function dbObjectAutoload ($classname) {
        $filename = static::$modelPath . $classname .".php";
        if (file_exists ($filename))
            include ($filename);
    }

    /*
     * Enable models autoload from a specified path
     *
     * Calling autoload() without path will set path to dbObjectPath/models/ directory
     *
     * @param string $path
     */
    public static function autoload ($path = null) {
        if ($path)
            static::$modelPath = $path . "/";
        else
            static::$modelPath = __DIR__ . "/models/";
        spl_autoload_register ("dbObject::dbObjectAutoload");
    }

    public function getLastError() {
        $err = empty($this->errors) ? '' : json_encode($this->errors, JSON_UNESCAPED_UNICODE);
        $err .= self::$db->getLastError() ?: '';
        return $err;
    }

    public function getLastQuery() {
        return self::$db->getLastQuery();
    }
}
