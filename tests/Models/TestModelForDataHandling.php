<?php

namespace Tests\Models;

use AlinO\MyOrm\Model;

class TestModelForDataHandling extends Model
{
    protected static string $table = 'data_handling_table';
    protected static string $idField = 'id';
    protected static array $emptyFields = ['status', 'counter'];
    protected static array $extraFields = ['extra_info', 'temp_notes'];

    // Regular fields are defined by what's passed to constructor
    // and not listed in $extraFields or $emptyFields (unless also passed)
    public string $name;
    public $value; // Can be any type
    public $status; // Will be initialized by $emptyFields if not provided
    public $counter; // Will be initialized by $emptyFields if not provided

    public function __construct(array $data = [])
    {
        parent::__construct($data);
    }
}
