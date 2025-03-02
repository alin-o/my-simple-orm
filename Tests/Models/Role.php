<?php

namespace Tests\Models;

use AlinO\MyOrm\Model;

class Role extends Model
{
    protected static $table = 'roles';

    protected static $relations = [
        'users' => [Model::HAS_MANY_THROUGH, User::class, 'user_id', 'user_roles', 'role_id'],
    ];
}
