<?php

namespace AlinO\MyOrm;

use AlinO\Db\DbException;
use AlinO\Db\MysqliDb;
use Exception;

/**
 * Abstract base class for database models, providing CRUD operations,
 * relationship handling, and hooks for lifecycle events.
 * the models can be stored in different databases, but relations 
 * will only work for models stored in same database
 * Uses an instance of \AlinO\Db\MysqliDb for database interactions.
 */
abstract class Model
{
    /**
     * @var string The database name to use for this model (empty for default)
     * override this in your model
     * if not set it will use the default connection
     */
    protected static $database = '';

    /**
     * @var string|null The table name for this model, auto-generated from class name if not set
     * override this in your model, you are responible for sanitation and validation
     */
    protected static $table;

    /**
     * @var string The primary key field name (default: 'id')
     * override this in your model, you are responible for sanitation and validation
     */
    protected static $idField = 'id';

    /**
     * @var string The default fields to select from the table (default: '*')
     * override this in your model, you are responible for sanitation and validation 
     */
    protected static $select = '*';

    /**
     * @var array<string> Fields that should be initialized with 0 if not provided
     */
    protected static $emptyFields = [];

    /**
     * @var array<string> Fields that are stored in _extra instead of the database
     */
    protected static $extraFields = [];

    /**
     * @var array<string, array> Relationship definitions (type, class, foreign key, etc.)
     */
    protected static $relations = [];

    /**
     * @var array<string, mixed> Data loaded from the database
     */
    protected $_data = [];

    /**
     * @var array<string> List of field names loaded from the database
     */
    protected $_fields = [];

    /**
     * @var array<string> Fields to be encrypted with AES in the database
     * the model handles encryption/decryption automatically.
     */
    protected static $aes_fields = [];

    /**
     * @var array<string, mixed> Temporary data not stored in the database
     * This is useful for passing around additional data with the model object.
     * Keys will not overlap with $_data.
     */
    protected $_extra = [];

    /**
     * @var array<string, mixed> Fields that have been modified and need saving
     */
    protected $_changed = [];

    /**
     * @var int|string|null  The primary identifier of this instance
     */
    protected mixed $id;

    /**
     * @var array<string> Relations to include in toArray() output
     */
    protected $_withRelated = [];

    /**
     * @var array<string, \AlinO\Db\MysqliDb> Cached database connections by database name
     */
    protected static $_conn;

    /**
     * @var \DateTimeZone|null Timezone for date operations
     */
    protected static $_tz;

    /**
     * @var string The field used for full-text search indexing (default: 'search')
     */
    protected static $searchField = 'search';

    /**
     * @var array<string, string|array> Fields or subfields to index for search
     */
    protected static $searchIndex = [];

    /**
     * @var array|null Sorting configuration for list() method [field, direction]
     */
    protected static $listSorting = null;

    /**
     * @var array<string, mixed> Cache for lazy-loaded related data
     */
    protected $relatedCache = [];

    /** @var int Relationship type: belongs to another model */
    const BELONGS_TO = 0;
    /** @var int Relationship type: has one related model */
    const HAS_ONE = 1;
    /** @var int Relationship type: has many related models */
    const HAS_MANY = 2;
    /** @var int Relationship type: has many related models through a join table */
    const HAS_MANY_THROUGH = 3;
    /** @var int Relationship type: many-to-many relationship (functionally similar to HAS_MANY_THROUGH) */
    const BELONGS_TO_MANY = 4;

    /**
     * Constructs a model instance, either empty, from an ID, or from an array of data.
     *
     * @param mixed|null $id The ID to load or an array of data to initialize with
     */
    public function __construct($id = null)
    {
        if (empty(static::$table)) {
            $cls = explode("\\", get_class($this));
            $class = array_pop($cls);
            static::$table = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $class));
        }
        if (is_array($id)) {
            static::fillData($id);
            $this->setData($id);
            $this->loaded();
        } elseif (!empty($id)) {
            $data = static::db()->where(static::$idField, $id)->getOne(static::$table, static::getSelect());
            if ($data) {
                $this->setData($data);
                $this->loaded();
            } else {
                $this->id = $id;
            }
        }
    }

    /**
     * Sets a database connection for a named instance.
     *
     * @param \AlinO\Db\MysqliDb $db The database connection instance to set
     * @param string $name The name of the connection (default: 'default')
     */
    public static function setConnection($db, $name = 'default')
    {
        if (empty($db)) {
            unset(static::$_conn[$name]);
        } else {
            static::$_conn[$name] = $db;
        }
    }

    /**
     * Gets a database connection by name.
     *
     * @param string $name The name of the connection (default: 'default')
     * @return \AlinO\Db\MysqliDb|null The database connection instance or null if not found.
     */
    public static function getConnection($name = 'default')
    {
        return isset(static::$_conn[$name]) ? static::$_conn[$name] : null;
    }

    /**
     * Resets all database connections.
     */
    public static function resetConnections()
    {
        static::$_conn = [];
    }

    /**
     * Sets the model's data from an array, separating database fields and extra fields.
     *
     * @param array<string, mixed> $data Key-value pairs to set
     */
    public function setData($data)
    {
        $this->_fields = [];
        $this->_extra = [];
        $this->_data = [];
        $this->_changed = [];
        foreach ($data as $k => $v) {
            if (in_array($k, static::$extraFields)) {
                $this->_extra[$k] = $v;
            } else {
                $this->_data[$k] = $v;
                $this->_fields[] = $k;
            }
        }
        $this->id = isset($this->_data[static::$idField]) ? $this->_data[static::$idField] : null;
    }

    /**
     * Gets the database connection for this model.
     * Each model can be stored in a different database, identified by static::$database.
     *
     * @return \AlinO\Db\MysqliDb The database connection instance
     */
    public static function db()
    {
        $dbName = static::$database ?: 'default';
        if (isset(static::$_conn[$dbName])) {
            $mdb = static::$_conn[$dbName];
        } elseif (empty(static::$database)) {
            static::$_conn[$dbName] = MysqliDb::getInstance();
            $mdb = static::$_conn[$dbName];
        }
        if (@$mdb) {
            $mdb->setModel(static::class, static::getTable(), static::getSelect());
            return $mdb;
        }
        throw new DbException("Database connection `$dbName` not found");
    }

    /**
     * Gets the database builder with a where condition
     *
     * @return \AlinO\Db\MysqliDb The database connection instance
     */
    public static function where($whereProp, $whereValue = 'DBNULL', $operator = '=', $cond = 'AND')
    {
        $mdb = static::db();
        $mdb->resetQuery();
        $mdb->setModel(static::class, static::getTable(), static::getSelect());
        $mdb->where($whereProp, $whereValue, $operator, $cond);
        return $mdb;
    }

    /**
     * Finds a model instance by its ID.
     *
     * @param mixed $id The ID to search for
     * @param string|null $field The field to search in (default: null - use ID field)
     * @return static|null The model instance or null if not found
     */
    public static function find($id, $field = null)
    {
        if (!$id) {
            return null;
        }
        if (!$field) {
            $i = new static($id);
            if ($i->_data) {
                return $i;
            }
            return null;
        }
        $data = static::db()->where($field, $id)->getOne(static::$table, static::getSelect());
        if ($data) {
            return new static($data);
        }
        return null;
    }

    /**
     * Finds multiple model instances by their IDs in a single query.
     *
     * @param array<mixed> $ids Array of IDs to search for
     * @param string|null $field The field to search in (default: null - use ID field)
     * @return array<static> Array of model instances
     */
    public static function findAll(array $ids, $field = null)
    {
        if (empty($ids)) return [];
        $rows = static::db()->where($field ?: static::$idField, $ids, 'IN')->get(static::$table);
        return array_map(fn($data) => new static($data), $rows);
    }

    /**
     * Hook called after the model is loaded from the database.
     */
    protected function loaded() {}

    /**
     * Hook for before creating action (before insert in db).
     * The data can be accessed in $this->_data.
     *
     * @return bool True to continue, false to stop the operation
     */
    protected function beforeCreate(): bool
    {
        return true;
    }

    /**
     * Hook for after a model is created (aka inserted in the db).
     * The data can be accessed in $this->_data.
     */
    protected function afterCreate() {}

    /**
     * Hook before data is updated in the db.
     * The changed fields can be accessed in $this->_changed.
     *
     * @return bool True to continue, false to stop the operation
     */
    protected function beforeUpdate(): bool
    {
        return true;
    }

    /**
     * Hook after data is updated in the db.
     * The changed fields can be accessed in $this->_changed.
     */
    protected function afterUpdate() {}

    /**
     * Hook before the data is deleted in the db.
     * The deleted item can be accessed in $this->_data.
     *
     * @return bool True to continue, false to stop the operation
     */
    protected function beforeDelete(): bool
    {
        return true;
    }

    /**
     * Hook for after the data has been deleted in the db.
     * The deleted item can be accessed in $this->_data.
     */
    protected function afterDelete() {}

    /**
     * Creates an item by inserting the data in the db.
     *
     * @param array<string, mixed> $data Data to insert
     * @return static|null The created model instance or null on failure
     * @throws DbException If the data array is empty or save fails
     */
    public static function create(array $data)
    {
        if (empty($data)) {
            throw new DbException("Data array cannot be empty");
        }
        $i = new static($data);
        $i->id = null;
        $related = [];
        foreach ($data as $f => $v) {
            if (static::hasRelation($f)) {
                $related[$f] = $v;
                unset($data[$f]);
            }
        }
        if ($i->save()) {
            $i = new static($i->id);
            $i->afterCreate();
            foreach ($related as $f => $v) {
                $i->$f = $v;
            }
            return $i;
        }
        throw new DbException("Could not create " . static::class);
    }

    /**
     * Gets the model's ID.
     *
     * @return mixed The ID value
     */
    public function id()
    {
        return $this->id;
    }

    /**
     * Saves the model to the database, either by inserting or updating.
     *
     * @return bool True on success, false on failure
     * @throws DbException If a database error occurs
     */
    public function save(): bool
    {
        try {
            if ($this->id) {
                if (!$this->beforeUpdate()) {
                    return false;
                }
                foreach ($this->_changed as $property => $value) {
                    if (in_array($property, static::$aes_fields) && !is_array($value)) {
                        $this->_changed[$property] = ['AES' => $value];
                    }
                }
                $saved = empty($this->_changed) || static::db()
                    ->where(static::$idField, $this->id)
                    ->update(static::$table, $this->_changed);
                if ($saved) {
                    $this->afterUpdate();
                    if (static::$searchIndex) {
                        $this->updateSearchIndex();
                    }
                    $this->_changed = [];
                    return true;
                }
            } else {
                if (!$this->beforeCreate()) {
                    return false;
                }
                if (static::$idField == 'uid' || static::$idField == 'guid') {
                    $this->_data[static::$idField] = \uniqid();
                }
                $this->_changed = $this->_data;
                foreach ($this->_changed as $property => $value) {
                    if (in_array($property, static::$aes_fields) && !is_array($value)) {
                        $this->_changed[$property] = ['AES' => $value];
                    }
                }
                $this->id = static::db()
                    ->insert(static::$table, $this->_changed);
                if ($this->id) {
                    if (!is_numeric($this->id)) {
                        $this->id = empty($this->_data[static::$idField]) ? 0 : $this->_data[static::$idField];
                    }
                    if (static::$searchIndex) {
                        $this->updateSearchIndex();
                    }
                    $this->_changed = [];
                    return true;
                }
                if (strstr(static::db()->getLastQuery(), "IGNORE")) {
                    return true;
                }
            }
            $ex = new DbException(static::db()->getLastError());
            static::db()->resetQuery();
            throw $ex;
        } catch (\Exception $e) {
            // Handle other exceptions
            $ex = new DbException($e->getMessage());
            static::db()->resetQuery();
            throw $ex;
        }
    }

    /**
     * Updates the model with new data and saves it.
     *
     * @param array<string, mixed> $data Key-value pairs to update
     * @return bool True on success, false on failure
     * @throws DbException If save fails
     */
    public function update(array $data): bool
    {
        foreach ($data as $property => $value) {
            if (in_array($property, $this->_fields)) {
                if ($this->_data[$property] != $value) {
                    $this->_data[$property] = $value;
                    if (in_array($property, static::$aes_fields)) {
                        $this->_changed[$property] = ['AES' => $value];
                    } else {
                        $this->_changed[$property] = $value;
                    }
                }
            } else {
                $this->$property = $value;
            }
        }
        if (empty($this->_changed)) {
            return true;
        }
        return $this->save();
    }

    /**
     * Checks if a property is set on the model.
     *
     * @param string $property The property name to check
     * @return bool True if the property exists or is accessible
     */
    public function __isset($property)
    {
        if (property_exists($this, $property)) {
            return true;
        }
        if (in_array($property, $this->_fields)) {
            return true;
        }
        if (method_exists($this, "get_" . $property)) {
            return true;
        }
        if ($this->id) {
            if (!empty(static::$relations[$property])) {
                return true;
            }
            if ($p = strpos($property, '_count')) {
                if ($p == strlen($property) - 6) {
                    $prop = substr($property, 0, $p);
                    if (!empty(static::$relations[$prop])) {
                        $rel = static::$relations[$prop];
                        if ($rel[0] == Model::HAS_MANY || $rel[0] == Model::HAS_MANY_THROUGH) {
                            return true;
                        }
                    }
                }
            }
        }
        return isset($this->_extra[$property]);
    }

    /**
     * gets the id of the model instance
     *
     * @return int|string|null The ID of the model or null if not set
     */
    public function getId(): int|string|null
    {
        return $this->id;
    }

    /**
     * Gets a property value dynamically.
     *
     * @param string $property The property name to get
     * @return mixed|null The property value or null if not found
     */
    public function __get($property)
    {
        if (property_exists($this, $property)) {
            return $this->$property;
        }
        if (method_exists($this, "get_" . $property)) {
            $m = "get_" . $property;
            return $this->$m();
        }
        if (in_array($property, $this->_fields)) {
            if (in_array($property, static::$aes_fields) && empty($this->_data[$property])) {
                $d = static::db()
                    ->where(static::$idField, $this->id)
                    ->getOne(static::$table, "AES_DECRYPT(`$property`, @aes_key) as `$property`");
                $this->_data[$property] = $d[$property];
            }
            return $this->_data[$property];
        }
        if ($this->id) {
            if (!empty(static::$relations[$property])) {
                // Standard behavior: fetch from statically defined relations
                return $this->getRelated($property);
            }

            // New behavior: check for an Eloquent-style relationship method
            if (method_exists($this, $property)) {
                // Check cache first to prevent recursion if the method itself uses magic __get
                // or if the relationship has already been loaded.
                if (array_key_exists($property, $this->relatedCache)) {
                    return $this->relatedCache[$property];
                }
                // Call the method (e.g., $this->roles())
                // This method is expected to call $this->belongsToMany() or similar,
                // which should return the collection of related models.
                $relatedData = $this->$property();
                $this->relatedCache[$property] = $relatedData;
                return $relatedData;
            }

            if ($p = strpos($property, '_count')) {
                if ($p == strlen($property) - 6) {
                    $prop = substr($property, 0, $p);
                    // Note: countRelated currently only works with static::$relations.
                    // For method-defined relations, you'd typically do count($this->$prop()).
                    return $this->countRelated($prop);
                }
            }
        }
        return $this->_extra[$property] ?? null;
    }

    /**
     * Sets a property value dynamically.
     *
     * @param string $property The property name to set
     * @param mixed $value The value to assign
     */
    public function __set($property, $value)
    {
        if (method_exists($this, "set_" . $property)) {
            $m = "set_" . $property;
            $this->$m($value);
        } elseif (in_array($property, $this->_fields)) {
            if (($this->_data[$property] ?? null) != $value) {
                $this->_data[$property] = $value;
                if (in_array($property, static::$aes_fields)) {
                    $this->_changed[$property] = ['AES' => $value];
                } else {
                    $this->_changed[$property] = $value;
                }
            }
        } elseif ($this->id && !empty(static::$relations[$property])) {
            $this->setRelated($property, $value);
        } else {
            $this->_extra[$property] = $value;
        }
    }

    /**
     * Handles dynamic method calls, e.g., fromXxx() for HAS_MANY relations.
     *
     * @param string $name The method name
     * @param array<mixed> $arguments The arguments passed
     * @return mixed The result of the dynamic call
     * @throws Exception If the method is not defined or arguments are invalid
     */
    public function __call($name, $arguments)
    {
        if (preg_match('/^from(.+)/', $name, $matches)) {
            if (count($arguments) != 1) {
                throw new Exception("Method $name accepts 1 parameter");
            }
            $related = strtolower($matches[1]);
            if (!empty(static::$relations[$related]) && static::$relations[$related][0] == Model::HAS_MANY) {
                return $this->findRelated($related, $arguments[0]);
            }
        }
        throw new Exception("Method $name not defined in " . static::class);
    }

    /**
     * Checks if a specific field or any fields have changed.
     *
     * @param string|null $elem The field to check (optional)
     * @return bool True if changed, false otherwise
     */
    public function isChanged($elem = null)
    {
        if ($elem) {
            return isset($this->_changed[$elem]);
        }
        return !empty($this->_changed);
    }

    /**
     * Resets the changed fields tracker.
     */
    public function resetChanges()
    {
        $this->_changed = [];
    }

    /**
     * Converts the model to an array representation.
     *
     * @param bool $extra Whether to include extra fields in the output
     * @return array<string, mixed> The model data as an array
     */
    public function toArray($extra = false): array
    {
        if (empty($this->_data)) {
            return [];
        }
        if ($extra && !empty($this->_extra)) {
            $r = $this->_data + $this->_extra;
        } else {
            $r = $this->_data;
        }
        foreach ($this->_withRelated as $rel) {
            $r[$rel] = $this->getRelatedIds($rel);
        }
        return $r;
    }

    /**
     * Returns a subset of the model data with only specified fields.
     *
     * @param string|array<string> $fields Comma-separated string or array of field names
     * @return array<string, mixed> Filtered data array
     */
    public function only($fields): array
    {
        if (!is_array($fields)) {
            $fields = explode(',', str_replace(' ', '', $fields));
        }
        return array_filter(
            $this->_data,
            function ($key) use ($fields) {
                return in_array($key, $fields);
            },
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Converts the model data to a JSON string.
     *
     * @return string JSON-encoded data
     */
    public function __toString(): string
    {
        return json_encode($this->_data);
    }

    /**
     * Gets the table name for this model.
     *
     * @return string|null The table name
     */
    public static function getTable()
    {
        return static::$table;
    }

    /**
     * Gets the ID field name for this model.
     *
     * @return string The ID field name
     */
    public static function getIdField()
    {
        return static::$idField;
    }

    /**
     * Gets the default select fields for this model.
     *
     * @return string The select fields
     */
    public static function getSelect()
    {
        $select = static::$select;
        if ($select == '*' && !empty(static::$aes_fields)) {
            foreach (static::$aes_fields as $f) {
                $select .= ", AES_DECRYPT(`$f`, @aes_key) as `$f`";
            }
        }
        return $select;
    }

    /**
     * Checks if a field value is unique, excluding a specific ID if provided.
     *
     * @param string $field The field to check
     * @param mixed $value The value to check for uniqueness
     * @param mixed|null $id ID to exclude from the check (optional)
     * @return mixed|null The ID of an existing record or null if unique
     */
    public static function assureUnique($field, $value, $id = null)
    {
        if ($id) {
            static::db()->where(static::$idField, $id, '!=');
        }
        return static::db()->where($field, $value)->getValue(static::$table, static::$idField);
    }

    /**
     * Deletes the model instance from the database.
     *
     * @return bool True on success, false on failure
     */
    public function delete()
    {
        if ($this->id) {
            if ($this->beforeDelete()) {
                $deleted = static::db()->where(static::$idField, $this->id)->delete(static::$table);
                if ($deleted) {
                    $this->afterDelete();
                }
                return $deleted;
            }
        }
        return false;
    }

    /**
     * Retrieves a list of records, optionally keyed by ID or a specific field.
     *
     * @param string|null $field The field to select (optional)
     * @param string|null $key The field to use as array key (optional, defaults to ID)
     * @return array<mixed> Array of records or field values
     */
    public static function list($field = null, $key = null)
    {
        if (static::$listSorting) {
            static::db()->orderBy(...static::$listSorting);
        } elseif ($field) {
            static::db()->orderBy($field, 'ASC');
        }
        if (!$key) {
            $key = static::$idField;
        }
        $rows = static::db()->get(static::$table, null, $field ? $key . ', ' . $field : static::getSelect());
        $data = [];
        foreach ($rows as $r) {
            $data[$r[$key]] = $field ? $r[$field] : $r;
        }
        return $data;
    }

    /**
     * Updates the search index for this model instance.
     *
     * @return bool True on success, false on failure
     */
    public function updateSearchIndex()
    {
        $searchTerms = [];
        foreach (static::$searchIndex as $key => $col) {
            if (is_array($col)) {
                if (in_array($key, $this->_fields)) {
                    if (!empty($this->_data[$key])) {
                        $data = json_decode($this->_data[$key], true);
                        foreach ($col as $c) {
                            if (!empty($data[$c])) {
                                $terms = explode(" ", preg_replace("/\W|_/", ' ', strtolower($data[$c])));
                                foreach ($terms as $t) {
                                    if (!in_array($t, $searchTerms)) {
                                        $searchTerms[] = $t;
                                    }
                                }
                            }
                        }
                    }
                }
            } elseif (in_array($col, $this->_fields)) {
                if (!empty($this->_data[$col])) {
                    $terms = explode(" ", preg_replace("/\W|_/", ' ', strtolower($this->_data[$col])));
                    foreach ($terms as $t) {
                        if (!in_array($t, $searchTerms)) {
                            $searchTerms[] = $t;
                        }
                    }
                }
            }
        }
        return static::db()
            ->where(static::$idField, $this->id)
            ->update(static::$table, [static::$searchField => " " . implode(' ', $searchTerms) . " "]);
    }

    /**
     * Fills missing fields in the data array with default values (0).
     *
     * @param array<string, mixed> &$data The data array to fill (passed by reference)
     */
    public static function fillData(&$data): array
    {
        foreach (static::$emptyFields as $f) {
            if (!isset($data[$f])) {
                $data[$f] = 0;
            }
        }
        return $data;
    }

    /**
     * Checks if a property is a defined relation.
     *
     * @param string $property The property name to check
     * @return bool True if it’s a relation, false otherwise
     */
    public static function hasRelation($property)
    {
        return !empty(static::$relations[$property]);
    }

    /**
     * Gets related data for a specified relation (lazy-loaded).
     *
     * @param string $related The relation name
     * @return mixed|null The related data or null if not found
     */
    public function getRelated($related)
    {
        if (empty(static::$relations[$related])) {
            return null;
        }
        if (!isset($this->relatedCache[$related])) {
            $this->relatedCache[$related] = $this->fetchRelated($related);
        }
        return $this->relatedCache[$related];
    }

    /**
     * Fetches related data for a specified relation.
     *
     * @param string $related The relation name
     * @return mixed|null The related data or null if invalid
     */
    protected function fetchRelated($related)
    {
        $r = static::$relations[$related];
        if (!is_array($r)) {
            return null;
        }
        list($type, $class, $fk) = $r;
        switch ($type) {
            case Model::BELONGS_TO:
            case Model::HAS_ONE:
                return $class::find($this->$fk);

            case Model::HAS_MANY:
                $cid = $class::getIdField();
                $ids = $class::db()->where($fk, $this->id)
                    ->get($class::getTable(), null, $cid);
                $ret = [];
                foreach ($ids as $i) {
                    $ret[$i[$cid]] = $class::find($i[$cid]);
                }
                return $ret;

            case Model::BELONGS_TO_MANY: // Fall through to HAS_MANY_THROUGH logic
            case Model::HAS_MANY_THROUGH:
                $cid = $class::getIdField();
                $join = $r[3];
                $jfk = $r[4];
                $ids = static::db()->where($jfk, $this->id)
                    ->get($join, null, $fk);
                $ret = [];
                foreach ($ids as $i) {
                    $ret[$i[$fk]] = $class::find($i[$fk]);
                }
                return $ret;
        }
        return null;
    }

    /**
     * Counts the number of related items for a specified relation.
     *
     * @param string $related The relation name
     * @return int|null The count or null if invalid
     */
    public function countRelated($related)
    {
        if (empty(static::$relations[$related])) {
            return 0;
        }
        $r = static::$relations[$related];
        if (!is_array($r)) {
            return 0;
        }
        list($type, $class, $fk) = $r;
        switch ($type) {
            case Model::BELONGS_TO:
            case Model::HAS_ONE:
                return 1;

            case Model::HAS_MANY:
                $cid = $class::getIdField();
                return $class::db()->where($fk, $this->id)
                    ->getValue($class::getTable(), "COUNT($cid)");

            case Model::HAS_MANY_THROUGH:
            case Model::BELONGS_TO_MANY: // Fall through
                $cid = $class::getIdField();
                $join = $r[3];
                $jfk = $r[4];
                return static::db()->where($jfk, $this->id)
                    ->getValue($join, "COUNT($fk)");
        }
        return null;
    }

    /**
     * Gets the IDs of related items for a specified relation.
     *
     * @param string $related The relation name
     * @return mixed|null The related IDs or null if invalid
     */
    public function getRelatedIds($related)
    {
        if (empty(static::$relations[$related])) {
            return null;
        }
        $r = static::$relations[$related];
        if (!is_array($r)) {
            return null;
        }
        list($type, $class, $fk) = $r;
        switch ($type) {
            case Model::BELONGS_TO:
            case Model::HAS_ONE:
                return $this->$fk;

            case Model::HAS_MANY:
                $cid = $class::getIdField();
                $ids = $class::db()->where($fk, $this->id)
                    ->get($class::getTable(), null, $cid);
                $ret = [];
                foreach ($ids as $i) {
                    $ret[] = $i[$cid];
                }
                return $ret;

            case Model::BELONGS_TO_MANY: // Fall through
            case Model::HAS_MANY_THROUGH:
                $cid = $class::getIdField();
                $join = $r[3];
                $jfk = $r[4];
                $ids = static::db()->where($jfk, $this->id)
                    ->get($join, null, $fk);
                $ret = [];
                foreach ($ids as $i) {
                    $ret[] = $i[$fk];
                }
                return $ret;
        }
        return null;
    }

    /**
     * Sets related data for a specified relation.
     *
     * @param string $related The relation name
     * @param mixed $value The value to set (model instance, array, or ID)
     */
    public function setRelated($related, $value)
    {
        if (empty(static::$relations[$related])) {
            return;
        }
        $r = static::$relations[$related];
        if (!is_array($r)) {
            return;
        }
        list($type, $class, $fk) = $r;
        switch ($type) {
            case Model::BELONGS_TO:
            case Model::HAS_ONE:
                if (is_a($value, $class)) {
                    $this->update([$fk => $value->id]);
                } elseif ($value == null) {
                    $this->update([$fk => null]);
                }
                break;

            case Model::BELONGS_TO_MANY: // Fall through
            case Model::HAS_MANY_THROUGH:
                $join = $r[3];
                $jfk = $r[4];
                if (is_a($value, $class)) {
                    static::db()->ignore()->insert($join, [$fk => $value->id, $jfk => $this->id]);
                } elseif (is_array($value)) {
                    if (is_a(current($value), $class)) {
                        $ids = [];
                        foreach ($value as $v) {
                            $ids[] = $v->id;
                        }
                        $value = $ids;
                    }
                    static::db()->where($jfk, $this->id)
                        ->where($fk, $value, 'NOT IN')
                        ->delete($join);
                    foreach ($value as $a) {
                        static::db()->ignore()->insert($join, [$fk => $a, $jfk => $this->id]);
                    }
                } elseif ($value == null) {
                    static::db()->where($jfk, $this->id)->delete($join);
                } else {
                    static::db()->ignore()->insert($join, [$fk => $value, $jfk => $this->id]);
                }
                break;
        }
    }

    /**
     * Finds a specific related item in a HAS_MANY relation by ID.
     *
     * @param string $related The relation name
     * @param mixed $id The ID to find
     * @return static|null The related model instance or null if not found
     */
    public function findRelated($related, $id)
    {
        if (empty(static::$relations[$related])) {
            return null;
        }
        $r = static::$relations[$related];
        if (!is_array($r)) {
            return null;
        }
        list($type, $class, $fk) = $r;
        if ($type != Model::HAS_MANY) {
            return null;
        }
        $class::db()->where($fk, $this->id);
        return $class::find($id);
    }

    /**
     * Sets up a query for models owned by a specific object (BELONGS_TO).
     *
     * @param object $owner The owning model instance
     * @return string The class name for chaining
     */
    public static function of(object $owner)
    {
        foreach (static::$relations as $relation) {
            if ($relation[0] == static::BELONGS_TO && is_a($owner, $relation[1])) {
                static::db()->where($relation[2], $owner->id);
            }
        }
        return get_called_class();
    }

    /**
     * Specifies relations to include in toArray() output.
     *
     * @param string ...$related Variable number of relation names
     * @return $this The current instance for chaining
     */
    public function with(...$related)
    {
        foreach ($related as $r) {
            if (!empty(static::$relations[$r])) {
                $this->_withRelated[] = $r;
            }
        }
        return $this;
    }

    /**
     * Loads a model instance by class name and ID.
     *
     * @param string $class The class name (with or without namespace)
     * @param mixed $id The ID to load
     * @return static|null The model instance or null if not found
     */
    public static function load($class, $id)
    {
        if (class_exists($class)) {
            return $class::find($id);
        }
        $class = "App\\Models\\$class";
        if (class_exists($class)) {
            return $class::find($id);
        }
        return null;
    }

    public function getChanges()
    {
        return $this->_changed;
    }

    /**
     * Define an inverse one-to-one or many-to-one relationship.
     *
     * @param string $relatedClass The fully qualified class name of the related model.
     * @param string $foreignKey The foreign key on the current model's table.
     * @param string|null $ownerKey The primary key on the related model's table. Defaults to related model's ID field.
     * @return ?Model An instance of the related model or null if not found.
     * @throws Exception If the related class does not exist or current model not saved.
     */
    public function belongsTo(string $relatedClass, string $foreignKey, ?string $ownerKey = null): ?Model
    {
        if (!class_exists($relatedClass)) {
            throw new Exception("Related class {$relatedClass} not found.");
        }

        $foreignKeyValue = $this->{$foreignKey} ?? null;

        if ($foreignKeyValue === null) {
            return null; // Foreign key not set or null, so no related model.
        }

        $actualOwnerKey = $ownerKey ?: $relatedClass::getIdField();

        // Use the related model's find method, which handles its own table and ID field.
        // $relatedClass::find($valueToSearch, $columnToSearchIn)
        return $relatedClass::find($foreignKeyValue, $actualOwnerKey);
    }

    /**
     * Define a one-to-one relationship.
     *
     * @param string $relatedClass The fully qualified class name of the related model.
     * @param string $foreignKey The foreign key on the related model's table.
     * @param string|null $localKey The local key on the current model's table. Defaults to current model's ID field.
     * @return ?Model An instance of the related model or null if not found.
     * @throws Exception If the related class does not exist or current model not saved.
     */
    public function hasOne(string $relatedClass, string $foreignKey, ?string $localKey = null): ?Model
    {
        if (!class_exists($relatedClass)) {
            throw new Exception("Related class {$relatedClass} not found.");
        }

        $actualLocalKey = $localKey ?: static::getIdField();
        $localKeyValue = $this->{$actualLocalKey} ?? null;

        if ($localKeyValue === null) {
            // Current model's local key is not set (e.g., model not saved or key is null)
            return null;
        }

        // Query the related table
        $data = $relatedClass::db()->where($foreignKey, $localKeyValue)->getOne($relatedClass::getTable());

        return $data ? new $relatedClass($data) : null;
    }

    /**
     * Define a one-to-many relationship.
     *
     * @param string $relatedClass The fully qualified class name of the related model.
     * @param string $foreignKey The foreign key on the related model's table.
     * @param string|null $localKey The local key on the current model's table. Defaults to current model's ID field.
     * @return array An array of related model instances.
     * @throws Exception If the related class does not exist or current model not saved.
     */
    public function hasMany(string $relatedClass, string $foreignKey, ?string $localKey = null): array
    {
        if (!class_exists($relatedClass)) {
            throw new Exception("Related class {$relatedClass} not found.");
        }

        $actualLocalKey = $localKey ?: static::getIdField();
        $localKeyValue = $this->{$actualLocalKey} ?? null;

        if ($localKeyValue === null) {
            // Current model's local key is not set
            return [];
        }

        $results = $relatedClass::db()->where($foreignKey, $localKeyValue)->get($relatedClass::getTable());

        return array_map(fn($data) => new $relatedClass($data), $results);
    }

    /**
     * Define a has-many-through relationship.
     *
     * @param string $relatedClass The fully qualified class name of the final related model.
     * @param string $throughClass The fully qualified class name of the intermediate model.
     * @param string $firstForeignKey Foreign key on the intermediate table pointing to this model.
     * @param string $secondForeignKey Foreign key on the final related table pointing to the intermediate model.
     * @param string|null $localKey Local key on this model's table. Defaults to current model's ID field.
     * @param string|null $throughKey Local key on the intermediate model's table. Defaults to intermediate model's ID field.
     * @return array An array of final related model instances.
     * @throws Exception If any class does not exist or current model not saved.
     */
    public function hasManyThrough(
        string $relatedClass,
        string $throughClass,
        string $firstForeignKey,
        string $secondForeignKey,
        ?string $localKey = null,
        ?string $throughKey = null
    ): array {
        if (!class_exists($relatedClass)) {
            throw new Exception("Related class {$relatedClass} not found.");
        }
        if (!class_exists($throughClass)) {
            throw new Exception("Through class {$throughClass} not found.");
        }

        $actualLocalKey = $localKey ?: static::getIdField();
        $currentModelId = $this->{$actualLocalKey} ?? null;

        if ($currentModelId === null) {
            return [];
        }

        $actualThroughKey = $throughKey ?: $throughClass::getIdField();

        // 1. Get IDs from the intermediate ($throughClass) table
        $intermediateRows = $throughClass::db()
            ->where($firstForeignKey, $currentModelId)
            ->get($throughClass::getTable(), null, $actualThroughKey);

        if (empty($intermediateRows)) return [];
        $intermediateModelIds = array_map(fn($row) => $row[$actualThroughKey], $intermediateRows);
        if (empty($intermediateModelIds)) return [];

        // 2. Get related ($relatedClass) models using these intermediate IDs
        $results = $relatedClass::db()
            ->where($secondForeignKey, $intermediateModelIds, 'IN')
            ->get($relatedClass::getTable());

        return array_map(fn($data) => new $relatedClass($data), $results);
    }

    /**
     * Defines and retrieves a many-to-many relationship.
     * This method is typically called from a model's relationship method (e.g., roles()).
     *
     * @param string $relatedClass The fully qualified class name of the related model.
     * @param string $pivotTable The name of the intermediate pivot table.
     * @param string $foreignPivotKey The foreign key on the pivot table that references the current model's ID.
     * @param string $relatedPivotKey The foreign key on the pivot table that references the related model's ID.
     * @return array An array of related model instances.
     * @throws Exception If the related class does not exist or if the current model is not saved.
     */
    public function belongsToMany(
        string $relatedClass,
        string $pivotTable,
        string $foreignPivotKey,
        string $relatedPivotKey
    ): array {
        if (!class_exists($relatedClass)) {
            throw new Exception("Related class {$relatedClass} not found.");
        }

        $currentModelId = $this->id();
        if ($currentModelId === null) {
            // Cannot fetch relations for a model that hasn't been saved or doesn't have an ID
            return [];
        }

        // Use the current model's database connection.
        // This assumes the pivot table is in the same database as the current model.
        $db = static::db();

        // Fetch the IDs/keys of the related models from the pivot table.
        // SQL: SELECT $relatedPivotKey FROM $pivotTable WHERE $foreignPivotKey = $currentModelId
        $relatedKeyValuesInPivot = $db
            ->where($foreignPivotKey, $currentModelId)
            ->get($pivotTable, null, $relatedPivotKey);

        if (empty($relatedKeyValuesInPivot)) {
            return [];
        }

        $relatedModelIds = array_map(fn($row) => $row[$relatedPivotKey], $relatedKeyValuesInPivot);

        if (empty($relatedModelIds)) {
            return [];
        }

        // Fetch the actual related model instances using their primary keys.
        // $relatedClass::findAll() uses the related model's ID field and database connection.
        $instances = $relatedClass::findAll($relatedModelIds); // Returns an array keyed by ID
        return array_values($instances); // Return a simple numerically indexed array
    }
}
