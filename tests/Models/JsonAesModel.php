<?php

namespace Tests\Models;

use AlinO\MyOrm\Model;

class JsonAesModel extends Model
{
    protected static $table = 'json_aes_test';

    // Test with default select ('*')
    //protected static $select = '*, secret_json, secret_data';

    // Test with fillable fields
    protected static $fillable = ['data', 'secret_data', 'secret_json'];

    protected static $json_fields = ['data', 'secret_json'];
    protected static $aes_fields = ['secret_data', 'secret_json'];
}
