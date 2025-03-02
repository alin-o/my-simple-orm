<?php

namespace Tests\Models;

use AlinO\MyOrm\Model;

class Address extends Model
{
    protected static $table = 'addresses';
    protected static $relations = [
        'user' => [Model::BELONGS_TO, User::class, 'user_id'],
    ];
}
