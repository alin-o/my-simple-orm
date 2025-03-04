# MyOrm
## A Simple ORM for MySQL and MariaDB

AlinO\MyOrm is a lightweight Object-Relational Mapping (ORM) library for PHP 7.4 and above. It simplifies database interactions with MySQL and MariaDB while offering powerful features such as relationship management, lifecycle hooks, AES encryption, and utility methods for modern web applications.

## Features

* Automatic Table Detection: Derives table names from class names if not explicitly set.
* Flexible Database Connections: Supports multiple database connections per model.
* Lifecycle Hooks: Provides hooks like `beforeCreate`, `afterCreate`, etc., for custom logic.
* Relationships: Supports `BELONGS_TO`, `HAS_ONE`, `HAS_MANY` and `HAS_MANY_THROUGH`.
* AES Encryption: Automatically encrypts/decrypts specified fields.
* Search Indexing: Updates search indexes based on defined fields.
* Utility Methods: `assureUnique`, `list`, `toArray`, etc.

## Requirements

    PHP 7.4 or higher
    Composer
    MySQL or MariaDB
    AlinO\Db\MysqliDb (included as a dependency)

## Installation

Install the library using Composer. Ensure Composer is installed on your system, then run:
```bash
composer require alin-o/my-simple-orm
```

This adds the library to your composer.json file and installs it in the vendor directory.
## Configuration

Set up the default database connection using the MysqliDb class provided by the library:
```php
global $mdb;
$mdb = new \AlinO\Db\MysqliDb('localhost', 'username', 'password', 'database_name');
```

Models can override the default connection by defining static::$database:
```php
class User extends \AlinO\MyOrm\Model {
    protected static $database = 'custom_db';
}
```

Then, configure the custom connection:
```php
\AlinO\MyOrm\Model::setConnection(new \AlinO\Db\MysqliDb('host', 'user', 'pass', 'custom_database_name'), 'custom_db');
```

## Defining Models

Extend the Model class and customize properties as needed:
```php
class User extends \AlinO\MyOrm\Model {
    protected static $table = 'users';               // Table name
    protected static $idField = 'id';                // Primary key
    protected static $select = 'id, username, email'; // Fields to select, default *
    protected static $emptyFields = ['status'];      // Fields initialized to 0
    protected static $extraFields = ['temp_data'];   // Non-persisted fields
    protected static $aes_fields = ['email'];        // Fields to encrypt
    protected static $relations = [                  // Relationships
        'addresses' => [Model::HAS_MANY, Address::class, 'user_id'],
        'profile' => [Model::HAS_ONE, Profile::class, 'user_id'],
    ];
}
```

If `$table` is not set, it’s automatically derived from the class name (e.g., User becomes user).

## Usage
### Basic CRUD Operations
#### Creating Records

Create a new record by instantiating a model and saving it:
```php
$user = new User();
$user->username = 'johndoe';
$user->email = 'johndoe@example.com';
$user->save();
```

Or use the create method:
```php
$user = User::create(['username' => 'janedoe', 'email' => 'janedoe@example.com']);
```

#### Retrieving Records

Find a record by ID:
```php
$user = User::find(1);
```

Find a record by field:
```php
$user = User::find('janedoe@example.com', 'email');
```

Find multiple records by IDs:
```php
$users = User::findAll([1, 2, 3]);
```

Find multiple records by field:
```php
$user = User::find(['johndoe@example.com', 'janedoe@example.com'], 'email');
```

List all records:
```php
$users = User::list(); // Returns an array keyed by ID
```

List specific fields:
```php
$usernames = User::list('username'); // Returns username values keyed by ID
```

List specific fields by key:
```php
$usernames = User::list('email', 'username'); // Returns email values keyed by username
```

#### Updating Records

Update a record and save:
```php
$user->username = 'johnsmith';
$user->save();
```

Or update directly:
```php
$user->update(['username' => 'johnsmith']);
```

#### Deleting Records

Delete a record:
```php
$user->delete();
```

### Relationships

Define relationships using the $relations array:
#### HAS_MANY
```php
class User extends \AlinO\MyOrm\Model {
    protected static $relations = [
        'addresses' => [Model::HAS_MANY, Address::class, 'user_id'],
    ];
}

$addresses = $user->addresses; // Returns an array of Address instances
foreach ($addresses as $address) {
    echo $address->street;
}
```

#### HAS_ONE
```php
class User extends \AlinO\MyOrm\Model {
    protected static $relations = [
        'profile' => [Model::HAS_ONE, Profile::class, 'user_id'],
    ];
}

$profile = $user->profile; // Returns a Profile instance or null
echo $profile->bio;
```

#### BELONGS_TO
```php
class Address extends \AlinO\MyOrm\Model {
    protected static $relations = [
        'user' => [Model::BELONGS_TO, User::class, 'user_id'],
    ];
}

$address = Address::find(1);
$user = $address->user; // Returns the User instance
echo $user->username;
```
#### HAS_MANY_THROUGH

For many-to-many relationships:
```php
class User extends \AlinO\MyOrm\Model {
    protected static $relations = [
        'roles' => [Model::HAS_MANY_THROUGH, Role::class, 'role_id', 'user_roles', 'user_id'],
    ];
}

$roles = $user->roles; // Returns an array of Role instances
foreach ($roles as $role) {
    echo $role->name;
}
```

### Advanced Querying

Use the db() method to access the underlying MysqliDb instance for complex queries:
```php
$usersData = User::db()
    ->where('active', 1)
    ->orderBy('created_at', 'ASC')
    ->get(User::getTable());
$users = array_map(fn($data) => new User($data), $usersData);
```

Note: the underlying MysqliDb provides a built-in query builder for chaining like where()->orderBy().

### Serialization

Convert a model to an array:
```php
$data = $user->toArray(); // Includes database fields
$dataWithExtra = $user->toArray(true); // Includes extra fields
```

Include related data:
```php
$user = User::find(1)->with('addresses');
$data = $user->toArray(); // Includes related addresses
```

Get a subset of fields:
```php
$partial = $user->only('username, email');
```

Convert to JSON:
```php
$json = (string) $user; // Uses __toString()
```

### Lifecycle Hooks

Override protected methods to hook into lifecycle events:
```php
class User extends \AlinO\MyOrm\Model {
    protected function beforeCreate(): bool {
        if (empty($this->username)) {
            return false; // Prevents creation
        }
        return true;
    }

    protected function afterCreate() {
        // Example: Log creation
    }
}
```

Available hooks: `beforeCreate`, `afterCreate`, `beforeUpdate`, `afterUpdate`, `beforeDelete`, `afterDelete`.

### AES Encryption

#### Set AES Key
the `aes_key` variable is required at database level
set it like this
```php
$mdb = new \AlinO\Db\MysqliDb('localhost', 'username', 'password', 'database_name');
$aes = getenv('DB_AES');
if (!empty($aes)) {
    $mdb->rawQuery("SET @aes_key = SHA2('$aes', 512)");
}
```

Fields in `$aes_fields` are automatically encrypted when saved and decrypted when retrieved:
```php
class User extends \AlinO\MyOrm\Model {
    protected static $aes_fields = ['email'];
}

$user->email = 'secret@example.com';
$user->save();
echo $user->email; // Outputs: secret@example.com
```

### Search Indexing

Define fields for search indexing:
```php
class Product extends \AlinO\MyOrm\Model {
    protected static $searchField = 'search_index';
    protected static $searchIndex = ['name', 'description'];
}
```

The `search_index` field is updated automatically when the model is saved, containing terms from name and description.

### Utility Methods

#### assureUnique: Check if a field value is unique.

```php
$existingId = User::assureUnique('username', 'johndoe');
if ($existingId) {
    echo "Username taken by ID: $existingId";
}
```

### Database errors are thrown as DbException:
```php
try {
    $user = User::create(['username' => 'newuser']);
} catch (\AlinO\Db\DbException $e) {
    echo "Error: " . $e->getMessage();
}
```

## Testing
configure database connection in `.env.testing`
```
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=test
DB_USER=test
DB_PASS=secret
DB_AES=aestest
```
then run `phpunit`

## Contributing

Contributions are welcome! Fork the repository on GitHub and submit a pull request. For bug reports or feature requests, please open an issue.
