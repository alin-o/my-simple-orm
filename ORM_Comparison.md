# MyOrm vs. Laravel Eloquent Models Comparison

This document outlines a comparison between `AlinO\MyOrm\Model` (referred to as MyOrm) and Laravel's Eloquent ORM, highlighting their similarities and differences in features, design, and usage.

## 1. Core Philosophy and Design

- **MyOrm:** Designed as a lightweight, simple ORM for MySQL/MariaDB, focusing on essential CRUD operations, relationship management, and specific utility features like AES encryption and search indexing. It aims for direct database interaction with minimal overhead, built on `AlinO\Db\MysqliDb`.
- **Laravel Eloquent:** A full-featured, expressive ORM that is a core component of the Laravel framework. It provides a rich set of functionalities, including advanced query building, comprehensive relationship types, mutators, accessors, events, and soft deletes, designed for a wide range of database systems (MySQL, PostgreSQL, SQLite, SQL Server).

## 2. Database Connections

- **MyOrm:**
  - Supports multiple database connections per model via `static::$database` property and `Model::setConnection()`. This allows models to reside in different databases.
  - Relies on `AlinO\Db\MysqliDb` for underlying database interactions.
- **Laravel Eloquent:**
  - Models can specify a `$connection` property to use a named database connection defined in Laravel's database configuration.
  - Integrates seamlessly with Laravel's database abstraction layer, which supports various drivers.

## 3. Model Definition and Configuration

- **MyOrm:**
  - Extends `AlinO\MyOrm\Model`.
  - Uses static properties for configuration:
    - `$table`: Table name (auto-derived if not set).
    - `$idField`: Primary key field (default `id`).
    - `$select`: Fields to select (default `*`).
    - `$emptyFields`: Fields initialized to 0.
    - `$extraFields`: Non-persisted fields.
    - `$aes_fields`: Fields for AES encryption/decryption.
    - `$json_fields`: Fields for JSON casting.
    - `$listSorting`: Default sorting for `list()` method.
    - `$relations`: Array-based relationship definitions.
    - `$searchField`, `$searchIndex`: For full-text search indexing.
- **Laravel Eloquent:**
  - Extends `Illuminate\Database\Eloquent\Model`.
  - Uses protected properties for configuration:
    - `$table`: Table name (auto-derived if not set).
    - `$primaryKey`: Primary key field (default `id`).
    - `$fillable` or `$guarded`: Mass assignment protection.
    - `$casts`: Attribute casting (e.g., `array`, `json`, `datetime`, custom casts).
    - `$hidden`, `$visible`: Attributes to hide/show in array/JSON output.
    - `$appends`: Accessors to append to array/JSON output.
    - `$dates`: Attributes to be mutated to `Carbon` instances.

## 4. CRUD Operations

Both ORMs provide intuitive methods for Create, Read, Update, and Delete operations.

- **MyOrm:**
  - `new Model()`, `save()`: Create/Update.
  - `Model::create()`: Create with mass assignment.
  - `Model::find($id, $field)`, `Model::findAll($ids, $field)`: Retrieve by ID or field.
  - `Model::list()`: Retrieve all records, optionally keyed.
  - `$model->update()`: Update with mass assignment.
  - `$model->delete()`: Delete a record.
- **Laravel Eloquent:**
  - `new Model()`, `save()`: Create/Update.
  - `Model::create()`: Create with mass assignment.
  - `Model::find($id)`, `Model::findOrFail($id)`: Retrieve by primary key.
  - `Model::where(...)->get()`, `Model::all()`: Retrieve collections.
  - `$model->update()`: Update with mass assignment.
  - `$model->delete()`, `Model::destroy($ids)`: Delete records.
  - Supports soft deletes (`$dates` property and `SoftDeletes` trait).

## 5. Relationships

Both support common relationship types, with Eloquent offering more advanced features.

- **MyOrm:**
  - **Array-based `$relations`:** `BELONGS_TO`, `HAS_ONE`, `HAS_MANY`, `HAS_MANY_THROUGH`, `BELONGS_TO_MANY` (functionally identical to `HAS_MANY_THROUGH`).
  - **Eloquent-Style Methods:** `hasOne()`, `hasMany()`, `belongsTo()`, `belongsToMany()`, `hasManyThrough()`. These return a `MysqliDb` query builder instance.
  - Lazy loading is the primary mechanism for relationships defined in methods.
- **Laravel Eloquent:**
  - **Method-based:** `hasOne`, `hasMany`, `belongsTo`, `belongsToMany`, `hasManyThrough`, `morphOne`, `morphMany`, `morphTo`, `morphedByMany`, `hasOneThrough`.
  - Relationships are defined as methods that return a relationship type instance (e.g., `HasMany`).
  - Supports both lazy loading (default) and eager loading (`with()`, `load()`) to prevent N+1 query problems.
  - Provides methods for attaching, detaching, syncing many-to-many relationships.

## 6. Query Building and Advanced Features

- **MyOrm:**
  - `Model::db()`: Provides access to the underlying `MysqliDb` instance for direct query building (e.g., `where()->orderBy()->get()`).
  - `Model::where()`: Static method for starting a query chain.
  - `getRelatedColumns()`, `getRelatedIds()`: Efficiently fetch specific columns or IDs from related models without full hydration.
  - `assureUnique()`: Utility for checking field uniqueness.
  - `of()`: Scopes a query to models belonging to a specific owner.
  - `with()`: Specifies relations to include in `toArray()` output, with optional column selection.
- **Laravel Eloquent:**
  - Extensive fluent query builder methods (e.g., `where`, `orWhere`, `orderBy`, `groupBy`, `having`, `join`, `select`, `limit`, `offset`).
  - Scopes (local and global) for reusable query constraints.
  - Mutators and Accessors for custom attribute formatting.
  - Events (creating, created, updating, updated, etc.) for lifecycle hooks.
  - Collections with rich methods for data manipulation.
  - Aggregates (count, sum, avg, max, min).
  - Raw expressions, subqueries.

## 7. Lifecycle Hooks/Events

- **MyOrm:**
  - Protected methods: `beforeCreate`, `afterCreate`, `beforeUpdate`, `afterUpdate`, `beforeDelete`, `afterDelete`.
  - Return `bool` in `before*` methods to control operation flow.
- **Laravel Eloquent:**
  - Model events (e.g., `creating`, `created`, `updating`, `updated`, `deleting`, `deleted`, `retrieved`).
  - Can be observed via static methods on the model or dedicated observer classes.

## 8. Serialization

- **MyOrm:**
  - `toArray()`: Converts model to an array, optionally including `extraFields`.
  - `only()`: Returns a subset of data.
  - `__toString()`: Converts to JSON string.
  - `with()` method influences `toArray()` output for related data.
- **Laravel Eloquent:**
  - `toArray()`: Converts model and its loaded relationships to an array.
  - `toJson()`: Converts to JSON string.
  - `makeHidden()`, `makeVisible()`: Control which attributes are included.
  - `append()`: Add accessors to array/JSON output.

## 9. Error Handling

- **MyOrm:** Throws `AlinO\Db\DbException` for database errors.
- **Laravel Eloquent:** Throws various exceptions, often related to query builder or model not found (`ModelNotFoundException`). Integrates with Laravel's exception handling.

## 10. Unique Features

- **MyOrm:**
  - **AES Encryption:** Automatic encryption/decryption of specified fields (`$aes_fields`) using database-level AES functions.
  - **Search Indexing:** Automatic update of a `search_index` field based on other model fields.
  - Direct access to `MysqliDb` for fine-grained control.
- **Laravel Eloquent:**
  - **Soft Deletes:** Models are not permanently removed but marked as deleted.
  - **Global Scopes:** Apply constraints to all queries for a model.
  - **Observers:** Centralized event handling for models.
  - **Polymorphic Relationships:** Relationships that can belong to more than one other model on a single association.
  - **Custom Casts:** Define custom logic for attribute serialization/deserialization.

## Conclusion

MyOrm is a pragmatic, lightweight ORM suitable for projects requiring direct MySQL/MariaDB interaction with core ORM features and specific utilities like AES encryption and search indexing. Its design prioritizes simplicity and directness, making it potentially easier to integrate into existing non-Laravel PHP applications or for developers who prefer a more hands-on approach to database interactions.

Laravel Eloquent, on the other hand, is a comprehensive and highly abstracted ORM, offering a vast array of features, a fluent API, and deep integration within the Laravel ecosystem. It is designed for rapid development, maintainability, and scalability in complex applications, providing a more opinionated and feature-rich experience. The choice between them depends heavily on project requirements, existing technology stack, and developer preference for a lightweight solution versus a full-featured framework-integrated ORM.
