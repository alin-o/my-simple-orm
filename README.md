"""# MyOrm
## A Simple ORM for MySQL and MariaDB

AlinO\MyOrm is a lightweight Object-Relational Mapping (ORM) library for PHP 7.4 and above. It simplifies database interactions with MySQL and MariaDB while offering powerful features such as relationship management, lifecycle hooks, AES encryption, and utility methods for modern web applications.

## Features

* Automatic Table Detection: Derives table names from class names if not explicitly set.
* Flexible Database Connections: Supports multiple database connections per model.
* Lifecycle Hooks: Provides hooks like `beforeCreate`, `afterCreate`, etc., for custom logic.
* Relationships: Supports `BELONGS_TO`, `HAS_ONE`, `HAS_MANY`, `HAS_MANY_THROUGH` and `BELONGS_TO_MANY`.
* Eloquent-Style Relationships: Define relationships using expressive, chainable methods (`hasOne`, `hasMany`, `belongsTo`, `belongsToMany`).
* AES Encryption: Automatically encrypts/decrypts specified fields on the current model instance.
* JSON Field Casting: Automatically encode and decode attributes to and from JSON.
* Search Indexing: Updates search indexes based on defined fields.
* Utility Methods: `assureUnique`, `list`, `toArray`, etc.
* Efficient Related Data Retrieval: Methods like `getRelatedColumns` and `getRelatedIds` for fetching specific related data without full model hydration.

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
    protected static $json_fields = ['settings'];    // Fields to cast to/from JSON
    protected static $listSorting = ['username', 'ASC']; // Default sorting for list()
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

#### BELONGS_TO_MANY

For many-to-many relationships (functionally identical to `HAS_MANY_THROUGH`):

```php
class Role extends \AlinO\MyOrm\Model {
    protected static $relations = [
        'users' => [Model::BELONGS_TO_MANY, User::class, 'user_id', 'user_roles', 'role_id'],
    ];
}

$users = $role->users; // Returns an array of User instances
foreach ($users as $user) {
    echo $user->username;
}
```

### Eloquent-Style Relationships

In addition to the `$relations` array, you can define relationships as methods on your model for a more expressive and flexible approach. This allows for lazy loading of related models.

#### hasOne

Define a one-to-one relationship.

```php
class User extends \AlinO\MyOrm\Model {
    public function profile() {
        return $this->hasOne(Profile::class, 'user_id');
    }
}

$profile = $user->profile(); // Returns the Profile instance
```

#### hasMany

Define a one-to-many relationship.

```php
class User extends \AlinO\MyOrm\Model {
    public function posts() {
        return $this->hasMany(Post::class, 'user_id');
    }
}

$posts = $user->posts(); // Returns an array of Post instances
```

#### belongsTo

Define the inverse of a one-to-one or one-to-many relationship.

```php
class Post extends \AlinO\MyOrm\Model {
    public function user() {
        return $this->belongsTo(User::class, 'user_id');
    }
}

$user = $post->user(); // Returns the User instance
```

#### belongsToMany

Define a many-to-many relationship.

```php
class User extends \AlinO\MyOrm\Model {
    public function roles() {
        return $this->belongsToMany(Role::class, 'user_roles', 'user_id', 'role_id');
    }
}

$roles = $user->roles(); // Returns an array of Role instances
```

#### hasManyThrough

Define a "has-many-through" relationship.

```php
class Project extends \AlinO\MyOrm\Model {
    public function deployments() {
        return $this->hasManyThrough(Deployment::class, Environment::class, 'project_id', 'environment_id');
    }
}

$deployments = $project->deployments(); // Returns an array of Deployment instances
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

Include related data using `with()`:

The `with()` method specifies relations to be included when `toArray()` is called.

*   By default (e.g., `with('addresses')`), it includes an array of associative arrays for the related models. Each associative array will contain the primary ID of the related model.
*   You can specify which columns from the related models to include using the format `'relationName:column1,column2'` (e.g., `with('addresses:street,city')`). The primary ID of the related model will always be included in addition to your specified columns.

```php
// Default: Fetching IDs of related addresses
$user = User::find(1)->with('addresses');
$data = $user->toArray();
// $data['addresses'] will be like:
// [
//   ['id' => 101], // Assuming Address ID is 101 and 'id' is its primary key
//   ['id' => 102]  // Assuming Address ID is 102
// ]

// Specify columns for the related 'addresses'
$user = User::find(1);
$data = $user->with('addresses:street,city')->toArray();
// $data['addresses'] will be like:
// [
//   ['id' => 101, 'street' => '123 Main St', 'city' => 'Anytown'],
//   ['id' => 102, 'street' => '456 Oak Ave', 'city' => 'Otherville']
// ]

// For BELONGS_TO or HAS_ONE relations, the structure is an array containing zero or one associative array:
$address = Address::find(101); 
$addressData = $address->with('user:username')->toArray(); // Assuming Address BELONGS_TO User
// $addressData['user'] could be: [['id' => 1, 'username' => 'johndoe']]
// or [] if no related user is found or the foreign key is null.
```

Get a subset of fields:
```php
$partial = $user->only('username, email');
```

Convert to JSON:
```php
$json = (string) $user; // Uses __toString()
```

### Advanced Relationship Data Retrieval

For more direct and potentially performant ways to fetch data from related models without full model hydration, you can use the following methods:

#### `getRelatedColumns(string $relationName, array $columns): array`

This method fetches specific columns from related models.

*   **Parameters**:
    *   `$relationName`: The name of the relation (e.g., `'posts'`).
    *   `$columns`: An array of column names to retrieve from the related model (e.g., `['title', 'slug']`).
*   **Returns**: An array of associative arrays. Each inner array represents a related record and contains the requested columns. The primary ID field of the related model is always included in the results, even if not explicitly specified in the `$columns` array.

```php
class User extends \AlinO\MyOrm\Model {
    protected static $relations = [
        'posts' => [Model::HAS_MANY, Post::class, 'user_id'],
    ];
}

$user = User::find(1);

// Get 'title' and 'slug' for all posts by this user
$postDetails = $user->getRelatedColumns('posts', ['title', 'slug']);
// $postDetails might look like:
// [
//   ['id' => 10, 'title' => 'My First Post', 'slug' => 'my-first-post'], // 'id' is Post's primary key
//   ['id' => 15, 'title' => 'Another Update', 'slug' => 'another-update']
// ]
```

#### `getRelatedIds(string $relationName): array`

This method efficiently retrieves a flat array of primary key IDs for the related models. It utilizes `getRelatedColumns` internally to fetch only the ID column.
```php
$user = User::find(1);
$postIds = $user->getRelatedIds('posts'); // Returns, for example: [10, 15]
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
The `@aes_key` session variable is required at the database level for AES encryption and decryption to work.
Set it after establishing your database connection:
```php
$mdb = new \AlinO\Db\MysqliDb('localhost', 'username', 'password', 'database_name');
$aesKey = getenv('DB_AES'); // Or your preferred way to get the key
if (!empty($aesKey)) {
    $mdb->rawQuery("SET @aes_key = SHA2(?, 512)", [$aesKey]);
}
```

Fields listed in a model's `static $aes_fields` array are automatically encrypted when saved and decrypted when retrieved directly on *that model instance* (e.g., when accessing `$user->email`).
```php
class User extends \AlinO\MyOrm\Model {
    protected static $aes_fields = ['email'];
}

$user->email = 'secret@example.com';
$user->save();
echo $user->email; // Outputs: secret@example.com (decrypted)
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

#### of: Scope a query to only include models belonging to a specific owner.

```php
// Get all posts for a specific user
$posts = Post::of($user)->get();
```

#### load: Load a model instance by class name and ID.

```php
$user = Model::load('User', 1);
// or
$user = Model::load(\App\Models\User::class, 1);
```

#### getChanges: Get an array of the model's changed attributes.

```php
$user->username = 'new-username';
$changes = $user->getChanges(); // ['username' => 'new-username']
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
""
