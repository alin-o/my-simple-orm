<?php

namespace Tests\Models;

use AlinO\MyOrm\Model;

class Country extends Model
{
    protected static $table = 'countries';
    // protected static $idField = 'id'; // 'id' is default

    // Eloquent-style relationships
    public function users()
    {
        return $this->hasMany(User::class, 'country_id', 'id');
    }

    public function posts()
    {
        // A Country has many Posts through Users.
        // $relatedClass, $throughClass, $firstForeignKey (on throughClass), $secondForeignKey (on relatedClass),
        // $localKey (on this model), $throughKey (on throughClass)
        return $this->hasManyThrough(Post::class, User::class, 'country_id', 'user_id', 'id', 'id');
    }
}
