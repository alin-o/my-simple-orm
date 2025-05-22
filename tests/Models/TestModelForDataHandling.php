<?php

namespace Tests\Models;

use AlinO\MyOrm\Model;

class TestModelForDataHandling extends Model
{
    protected static $table = 'data_handling_table';
    protected static $idField = 'id';
    protected static $emptyFields = ['status', 'counter'];
    protected static $extraFields = ['extra_info', 'temp_notes'];


    public function __construct(mixed $data = [])
    {
        parent::__construct($data);
    }
}
