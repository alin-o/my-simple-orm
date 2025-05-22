<?php

namespace Tests\Models;

use AlinO\MyOrm\Model;

class TestModelForConnection extends Model
{
    public static string $database = 'test_db_conn';
    protected static string $table = 'test_model_table';
}
