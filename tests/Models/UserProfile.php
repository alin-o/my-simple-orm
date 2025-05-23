<?php

namespace Tests\Models;

use AlinO\MyOrm\Model;

class UserProfile extends Model
{
    protected static $table = 'user_profiles';
    // protected static $idField = 'id'; // 'id' is default

    // Eloquent-style relationships
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
