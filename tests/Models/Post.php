<?php

namespace Tests\Models;

use AlinO\MyOrm\Model;

class Post extends Model
{
    protected static $table = 'posts';
    // protected static $idField = 'id'; // 'id' is default

    // Eloquent-style relationships
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
