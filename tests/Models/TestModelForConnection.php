<?php

namespace Tests\Models;

use AlinO\MyOrm\Model;

class TestModelForConnection extends Model
{
    protected static $database = 'test_db_conn';
    protected static $table = 'test_model_table';
}
