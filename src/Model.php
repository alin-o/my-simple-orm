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
     * @var array<string> List of fillable fields. when defined, only there fields can be changed
     */
    protected static $fillable;

    /**
     * @var array<string> Fields to be encrypted with AES in the database
     * the model handles encryption/decryption automatically.
     */
    protected static $aes_fields = [];

    /**
     * @var array<string> Fields to be automatically stored as json string
     */
    protected static $json_fields = [];

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
    protected static $_conn = [];

    /**
     * @var \DateTimeZone|null Timezone for date operations
     */
    protected static $_tz = null;

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
        // Only set static::$table if it is not set for this exact class
        $cls = get_called_class();
        if (!isset(static::$table) || empty(static::$table)) {
            $clsParts = explode("\\", $cls);
            $class = array_pop($clsParts);
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
            if (!empty(static::$fillable) && !in_array($k, static::$fillable)) {
                continue; // skip fields not in fillable
            }
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
            $mdb->resetQuery();
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
                    if (in_array($property, static::$json_fields) && !is_string($value)) {
                        $value = $this->_changed[$property] = json_encode($value);
                    }
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
                    if (in_array($property, static::$json_fields) && !is_string($value)) {
                        $value = $this->_changed[$property] = json_encode($value);
                    }
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
            throw $ex;
        } finally {
            static::db()->resetQuery();
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
                    $this->_changed[$property] = $value;
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
            // Bug 3: Use substr to check for _count suffix
            if (substr($property, -6) === '_count') {
                $prop = substr($property, 0, -6);
                if (!empty(static::$relations[$prop])) {
                    $rel = static::$relations[$prop];
                    if ($rel[0] == Model::HAS_MANY || $rel[0] == Model::HAS_MANY_THROUGH) {
                        return true;
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
            if (in_array($property, static::$json_fields) && is_string($this->_data[$property])) {
                try {
                    $this->_data[$property] = json_decode($this->_data[$property]);
                } catch (\Exception $ex) {
                }
            }
            return $this->_data[$property];
        }
        if ($this->id) {
            // Eloquent-style relationship method access (e.g., $user->posts)
            if (method_exists($this, $property)) {
                // Check cache first to prevent recursion if the method itself uses magic __get
                // or if the relationship has already been loaded.
                if (array_key_exists($property, $this->relatedCache)) {
                    return $this->relatedCache[$property];
                }
                // Call the method (e.g., $this->posts())
                // This method is expected to return a MysqliDb query builder.
                $queryBuilder = $this->$property();

                $relatedModel = null;
                // Determine if it's a single model or a collection based on relation type
                $relationType = null;
                if (isset(static::$relations[$property])) {
                    $relationType = static::$relations[$property][0];
                } else {
                    if (str_ends_with(strtolower($property), 's')) { // Plural implies hasMany
                        $relationType = Model::HAS_MANY;
                    } else { // Singular implies hasOne or belongsTo
                        $relationType = Model::HAS_ONE; // Default to single
                    }
                }

                if ($relationType === Model::BELONGS_TO || $relationType === Model::HAS_ONE) {
                    $relatedModel = $queryBuilder->first();
                } elseif ($relationType === Model::HAS_MANY || $relationType === Model::BELONGS_TO_MANY || $relationType === Model::HAS_MANY_THROUGH) {
                    $relatedModel = $queryBuilder->all();
                }

                $this->relatedCache[$property] = $relatedModel;
                return $relatedModel;
            }

            // Standard behavior: fetch from statically defined relations (fallback if no method exists)
            if (!empty(static::$relations[$property])) {
                return $this->getRelated($property);
            }

            // Bug 3: Use substr to check for _count suffix
            if (substr($property, -6) === '_count') {
                $prop = substr($property, 0, -6);
                return $this->countRelated($prop);
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
        if (!empty(static::$fillable) && !in_array($property, static::$fillable)) {
            // Only allow fillable fields to be set via __set
            $this->_extra[$property] = $value;
            return;
        }
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
        // Eloquent-style dynamic relation method calls (e.g., $user->posts())
        if (isset(static::$relations[$name])) {
            $relation = static::$relations[$name];
            list($type, $class, $fk) = $relation;

            switch ($type) {
                case Model::BELONGS_TO:
                    return $this->belongsTo($class, $fk);
                case Model::HAS_ONE:
                    return $this->hasOne($class, $fk);
                case Model::HAS_MANY:
                    return $this->hasMany($class, $fk);
                case Model::BELONGS_TO_MANY:
                case Model::HAS_MANY_THROUGH:
                    if (count($relation) >= 5) {
                        return $this->belongsToMany($class, $relation[3], $relation[4], $fk);
                    }
                    break;
            }
        }

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
        foreach ($this->_withRelated as $relString) {
            // Parse relation string, e.g., "relationName" or "relationName:col1,col2"
            list($rel, $colsSpec) = array_pad(explode(':', $relString, 2), 2, null);
            if (!empty($this->relatedCache[$rel])) {
                $cached = $this->relatedCache[$rel];
                if ($cached instanceof self) {
                    $r[$rel] = $cached->toArray();
                    continue;
                }
                // If $cached is an array of hydrated objects, dehydrate each
                if (is_array($cached) && !empty($cached) && is_object($cached[0]) && is_a($cached[0], static::class)) {
                    $r[$rel] = array_map(fn($obj) => $obj->toArray(), $cached);
                    continue;
                }
            }
            if (!empty(static::$relations[$rel])) {
                $relatedClass = static::$relations[$rel][1];
                // Default to ID field if no columns specified, otherwise parse comma-separated columns
                $columnsToFetch = $colsSpec ? explode(',', $colsSpec) : explode(',', $relatedClass::getSelect());
                $r[$rel] = $this->getRelatedColumns($rel, $columnsToFetch);
            }
        }
        $this->_withRelated = []; // Clear after use
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
        if ($select != '*' && !empty(static::$aes_fields)) {
            // Replace AES fields in select with AES_DECRYPT
            $fields = array_map('trim', explode(',', $select));
            foreach ($fields as &$field) {
                if (!stristr($field, 'AES_DECRYPT')) {
                    $fieldName = trim($field, "` ");
                    if (in_array($fieldName, static::$aes_fields)) {
                        $field = "AES_DECRYPT(`$fieldName`, @aes_key) as `$fieldName`";
                    } else {
                        $field = $fieldName == '*' ? '*' : "`$fieldName`";
                    }
                }
            }
            return implode(', ', $fields);
        }
        return $select;
    }

    /**
     * Gets the AES encrypted fields for the model.
     *
     * @return array<string>
     */
    public static function getStaticAesFields(): array
    {
        return static::$aes_fields;
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
                $relatedItems = $class::db()->where($fk, $this->id)->get($class::getTable());
                return array_map(fn($data) => new $class($data), $relatedItems);

            case Model::BELONGS_TO_MANY:
            case Model::HAS_MANY_THROUGH:
                $joinTable = $r[3];
                $relatedFk = $r[4];

                $relatedIds = static::db()->where($relatedFk, $this->id)->get($joinTable, null, $fk);
                if (empty($relatedIds)) {
                    return [];
                }
                $ids = array_column($relatedIds, $fk);
                $relatedItems = $class::findAll($ids);
                return array_values($relatedItems);
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
     * Gets specific columns of related items for a specified relation without instantiating full models.
     *
     * @param string $related The relation name.
     * @param array<string> $columns The columns to retrieve from the related table.
     * @return array<array<string, mixed>> An array of associative arrays, where each inner array contains the requested columns for a related item.
     *                      Returns an empty array if the relation is invalid or no related items are found.
     */
    public function getRelatedColumns(string $related, array $columns): array
    {
        if (empty(static::$relations[$related])) {
            return [];
        }
        $r = static::$relations[$related];
        if (!is_array($r) || count($r) < 3) { // Basic validation for relation structure
            return [];
        }

        list($type, $class, $fk) = $r;

        /** @var Model $class */
        // Ensure the ID field of the related class is always selected.
        $relatedIdField = $class::getIdField();
        $selectColumns = array_unique(array_merge($columns, [$relatedIdField]));

        $relatedAesFields = $class::getStaticAesFields();
        $selectParts = [];
        foreach ($selectColumns as $col) {
            $trimmedCol = trim($col);
            if (in_array($trimmedCol, $relatedAesFields)) {
                $selectParts[] = "AES_DECRYPT(`{$trimmedCol}`, @aes_key) AS `{$trimmedCol}`";
            } else {
                $selectParts[] = "`{$trimmedCol}`";
            }
        }
        $selectString = null;
        // If the only column requested is '*', then we want to select all columns.
        if (count($columns) === 1 && $columns[0] === '*') {
            $selectString = null; // MysqliDb will select all columns
        } else {
            // Ensure the ID field of the related class is always selected.
            $relatedIdField = $class::getIdField();
            $selectColumns = array_unique(array_merge($columns, [$relatedIdField]));

            $relatedAesFields = $class::getStaticAesFields();
            $parts = [];
            foreach ($selectColumns as $col) {
                $trimmedCol = trim($col);
                if (in_array($trimmedCol, $relatedAesFields)) {
                    $parts[] = "AES_DECRYPT(`{$trimmedCol}`, @aes_key) AS `{$trimmedCol}`";
                } else {
                    $parts[] = "`{$trimmedCol}`";
                }
            }
            $selectString = implode(', ', $parts);
        }

        switch ($type) {
            case Model::BELONGS_TO:
            case Model::HAS_ONE: // Assumes $fk is a property on $this model holding the related ID, as per existing ORM convention
                $foreignKeyValue = $this->{$fk} ?? null;
                if ($foreignKeyValue === null) {
                    return [];
                }
                $data = $class::db()->where($class::getIdField(), $foreignKeyValue)->getOne($class::getTable(), $selectString);
                return $data ? [$data] : [];
            case Model::HAS_MANY:
                if ($this->id === null) return [];
                $results = $class::db()->where($fk, $this->id)
                    ->get($class::getTable(), null, $selectString);
                return $results ?: [];

            case Model::BELONGS_TO_MANY:
            case Model::HAS_MANY_THROUGH:
                if (count($r) < 5) return []; // Expects pivot table, current model FK on pivot, related model FK on pivot
                $pivotTable = $r[3];
                $currentModelFkOnPivot = $r[4];
                $relatedModelFkOnPivot = $fk;

                if ($this->id === null) return [];
                $relatedIdsData = static::db()->where($currentModelFkOnPivot, $this->id)
                    ->get($pivotTable, null, $relatedModelFkOnPivot);

                if (empty($relatedIdsData)) return [];
                $idsToFetch = array_column($relatedIdsData, $relatedModelFkOnPivot);
                if (empty($idsToFetch)) return [];

                $results = $class::db()->where($class::getIdField(), $idsToFetch, 'IN')
                    ->get($class::getTable(), null, $selectString);
                return $results ?: [];
        }
        return [];
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
            return [];
        }
        $r = static::$relations[$related];
        if (!is_array($r) || count($r) < 3) {
            return [];
        }
        list($type, $class, $fk) = $r;

        // For BELONGS_TO/HAS_ONE, return the single foreign key value directly, wrapped in an array for consistency.
        // This assumes $this->$fk holds the ID of the related model, as per ORM convention.
        switch ($type) {
            case Model::BELONGS_TO:
            case Model::HAS_ONE:
                $id = $this->$fk ?? null;
                return $id !== null ? [$id] : [];
            case Model::HAS_MANY:
            case Model::BELONGS_TO_MANY: // Fall through
            case Model::HAS_MANY_THROUGH:
                $idField = $class::getIdField();
                $results = $this->getRelatedColumns($related, [$idField]); // Fetch only the ID column
                return array_column($results, $idField); // Pluck the ID field into a flat array
        }
        return [];
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
                } elseif (is_array($value) && !empty($value)) {
                    if (is_a(current($value), $class)) {
                        $ids = [];
                        foreach ($value as $v) {
                            $ids[] = $v->id;
                        }
                        $value = $ids;
                    }
                    static::db()->where($jfk, $this->id)
                        ->delete($join);
                    static::db()->where($jfk, $this->id)
                        ->where($fk, $value, 'NOT IN')
                        ->delete($join);
                    $data = [];
                    foreach ($value as $a) {
                        $data[] = [$fk => $a, $jfk => $this->id];
                    }
                    static::db()->ignore()->insertMulti($join, $data);
                } elseif (empty($value)) {
                    static::db()->where($jfk, $this->id)->delete($join);
                } else {
                    static::db()->ignore()->insert($join, [$fk => $value, $jfk => $this->id]);
                }
                break;
        }
        unset($this->relatedCache[$related]);
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
     * @param Model $owner The owning model instance
     * @return string The class name for chaining
     */
    public static function of(Model $owner)
    {
        foreach (static::$relations as $relation) {
            if ($relation[0] == static::BELONGS_TO && is_a($owner, $relation[1])) {
                static::where($relation[2], $owner->id);
                return get_called_class();
            }
        }
        return get_called_class();
    }

    /**
     * Specifies relations to include in toArray() output.
     * Can specify columns to fetch for related models (e.g., 'relationName:column1,column2').
     *
     * @param string ...$related Variable number of relation names, optionally with columns.
     * @return $this The current instance for chaining
     */
    public function with(...$related)
    {
        $this->_withRelated = []; // Reset if called multiple times, or append based on desired behavior
        foreach ($related as $relString) {
            list($relName) = explode(':', $relString, 2);
            if (!empty(static::$relations[$relName]) || method_exists($this, $relName)) {
                $this->_withRelated[] = $relString; // Store the full string 'relationName:column1,column2'
            }
        }
        // After loading, we can immediately process them to populate the cache
        $this->loadEagerRelations();
        return $this;
    }

    /**
     * Eager loads relationships for a collection of models.
     *
     * @param array<Model> $models The models to eager load relationships for.
     * @param array<string> $relations The relations to eager load.
     * @return void
     */
    public static function eagerLoad(array $models, array $relations): void
    {
        if (empty($models) || empty($relations)) {
            return;
        }

        foreach ($relations as $relationName) {
            $relation = static::$relations[$relationName] ?? null;
            if (!$relation) continue;

            list($type, $relatedClass, $foreignKey) = $relation;

            switch ($type) {
                case self::BELONGS_TO:
                    $foreignKeyValues = array_filter(array_map(fn($m) => $m->$foreignKey, $models));
                    if (empty($foreignKeyValues)) continue 2;

                    $relatedModels = $relatedClass::findAll(array_unique($foreignKeyValues), $relatedClass::getIdField());
                    $relatedModelsById = [];
                    foreach ($relatedModels as $relatedModel) {
                        $relatedModelsById[$relatedModel->getId()] = $relatedModel;
                    }

                    foreach ($models as $model) {
                        $model->setRelation($relationName, $relatedModelsById[$model->$foreignKey] ?? null);
                    }
                    break;

                case self::HAS_MANY:
                    $modelIds = array_map(fn($m) => $m->id, $models);
                    if (empty($modelIds)) {
                        break;
                    }
                    $relatedModels = $relatedClass::db()->where($foreignKey, $modelIds, 'IN')->get($relatedClass::getTable());

                    $relatedModelsByFk = [];
                    foreach ($relatedModels as $relatedData) {
                        $relatedModelsByFk[$relatedData[$foreignKey]][] = new $relatedClass($relatedData);
                    }

                    foreach ($models as $model) {
                        $model->setRelation($relationName, $relatedModelsByFk[$model->id] ?? []);
                    }
                    break;
            }
        }
    }

    /**
     * Loads the relations specified in _withRelated into the relatedCache.
     *
     * @return void
     */
    protected function loadEagerRelations(): void
    {
        foreach ($this->_withRelated as $relationName) {
            if (!array_key_exists($relationName, $this->relatedCache)) {
                $this->getRelated($relationName);
            }
        }
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
     * Sets a value in the relationship cache.
     *
     * @param string $relation The name of the relation.
     * @param mixed $value The value to cache.
     * @return void
     */
    public function setRelation(string $relation, $value): void
    {
        $this->relatedCache[$relation] = $value;
    }


    /**
     * Returns the query builder for a hasOne relationship.
     *
     * @param string $relatedClass
     * @param string $foreignKey
     * @param string|null $localKey
     * @return MysqliDb
     * @throws Exception
     */
    public function hasOne(string $relatedClass, string $foreignKey, ?string $localKey = null): MysqliDb
    {
        if (!class_exists($relatedClass)) {
            throw new Exception("Related class {$relatedClass} not found.");
        }
        $actualLocalKey = $localKey ?: static::getIdField();
        $localKeyValue = $this->{$actualLocalKey} ?? null;

        return $relatedClass::db()->where($foreignKey, $localKeyValue);
    }

    /**
     * Returns the query builder for a hasMany relationship.
     *
     * @param string $relatedClass
     * @param string $foreignKey
     * @param string|null $localKey
     * @return MysqliDb
     * @throws Exception
     */
    public function hasMany(string $relatedClass, string $foreignKey, ?string $localKey = null): MysqliDb
    {
        if (!class_exists($relatedClass)) {
            throw new Exception("Related class {$relatedClass} not found.");
        }
        $actualLocalKey = $localKey ?: static::getIdField();
        $localKeyValue = $this->{$actualLocalKey} ?? $this->id();

        return $relatedClass::db()->setModel($relatedClass, $relatedClass::getTable(), $relatedClass::getSelect())->where($foreignKey, $localKeyValue);
    }

    /**
     * Returns the query builder for a belongsTo relationship.
     *
     * @param string $relatedClass
     * @param string $foreignKey
     * @param string|null $ownerKey
     * @return MysqliDb
     * @throws Exception
     */
    public function belongsTo(string $relatedClass, string $foreignKey, ?string $ownerKey = null): MysqliDb
    {
        if (!class_exists($relatedClass)) {
            throw new Exception("Related class {$relatedClass} not found.");
        }
        $foreignKeyValue = $this->{$foreignKey} ?? $this->id();
        $actualOwnerKey = $ownerKey ?: $relatedClass::getIdField();

        return $relatedClass::where($actualOwnerKey, $foreignKeyValue);
    }

    /**
     * Returns the query builder for a belongsToMany relationship.
     *
     * @param string $relatedClass
     * @param string $pivotTable
     * @param string $foreignPivotKey
     * @param string $relatedPivotKey
     * @return MysqliDb
     * @throws Exception
     */
    public function belongsToMany(string $relatedClass, string $pivotTable, string $foreignPivotKey, string $relatedPivotKey): MysqliDb
    {
        if (!class_exists($relatedClass)) {
            throw new Exception("Related class {$relatedClass} not found.");
        }

        $currentModelId = $this->id();
        $relatedKeyValuesInPivot = static::db()
            ->where($foreignPivotKey, $currentModelId)
            ->get($pivotTable, null, $relatedPivotKey);

        $relatedModelIds = array_map(fn($row) => $row[$relatedPivotKey], $relatedKeyValuesInPivot);

        if (empty($relatedModelIds)) {
            return $relatedClass::where($relatedClass::getIdField(), null, 'IS');
        }
        return $relatedClass::where($relatedClass::getIdField(), $relatedModelIds, 'IN');
    }

    /**
     * Returns the query builder for a hasManyThrough relationship.
     *
     * @param string $relatedClass The final related model class (e.g., Post)
     * @param string $throughClass The intermediate model class (e.g., User)
     * @param string $firstForeignKey The foreign key on the intermediate model (e.g., 'country_id' on User)
     * @param string $secondForeignKey The foreign key on the final related model (e.g., 'user_id' on Post)
     * @param string|null $localKey The local key on the current model (e.g., 'id' on Country)
     * @param string|null $throughLocalKey The local key on the intermediate model (e.g., 'id' on User)
     * @return MysqliDb
     * @throws Exception
     */
    public function hasManyThrough(
        string $relatedClass,
        string $throughClass,
        string $firstForeignKey,
        string $secondForeignKey,
        ?string $localKey = null,
        ?string $throughLocalKey = null
    ): MysqliDb {
        if (!class_exists($relatedClass)) {
            throw new Exception("Related class {$relatedClass} not found.");
        }
        if (!class_exists($throughClass)) {
            throw new Exception("Through class {$throughClass} not found.");
        }

        $actualLocalKey = $localKey ?: static::getIdField();
        $actualThroughLocalKey = $throughLocalKey ?: $throughClass::getIdField();

        $intermediateIds = $throughClass::db()
            ->where($firstForeignKey, $this->{$actualLocalKey})
            ->get($throughClass::getTable(), null, $actualThroughLocalKey);

        $ids = array_column($intermediateIds, $actualThroughLocalKey);
        if (empty($ids)) {
            return $relatedClass::where($secondForeignKey, null, 'IS');
        }
        return $relatedClass::where($secondForeignKey, $ids, 'IN');
    }
}
