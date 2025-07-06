<?php

namespace Tests\Models;

use AlinO\MyOrm\Model;

class Role extends Model
{
    protected static $table = 'roles';

    protected static $relations = [
        'users' => [Model::HAS_MANY_THROUGH, User::class, 'user_id', 'user_roles', 'role_id'],
    ];

    // Eloquent-style relationship method for testing
    public function eloquentUsers()
    {
        return $this->belongsToMany(User::class, 'user_roles', 'role_id', 'user_id');
    }
}
