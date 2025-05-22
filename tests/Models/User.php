<?php

namespace Tests\Models;

use AlinO\MyOrm\Model;

class User extends Model
{
    protected static $table = 'users';

    protected static $relations = [
        'addresses' => [Model::HAS_MANY, Address::class, 'user_id'],
        'saddress' => [Model::HAS_ONE, Address::class, 'shipping_address'],
        'baddress' => [Model::HAS_ONE, Address::class, 'billing_address'],
        'roles' => [Model::HAS_MANY_THROUGH, Role::class, 'role_id', 'user_roles', 'user_id'],
    ];

    // Fields to test AES encryption
    protected static $aes_fields = ['aes_pwd'];

    // Fields to test extra data handling
    protected static $extraFields = ['extra_data'];

    // Hook tracking properties (for testing)
    public $beforeCreateCalled = false;
    public $afterCreateCalled = false;
    public $beforeUpdateCalled = false;
    public $afterUpdateCalled = false;
    public $beforeDeleteCalled = false;
    public $afterDeleteCalled = false;

    // Block flags for testing hook cancellation
    public $blockCreate = false;
    public $blockUpdate = false;
    public $blockDelete = false;
    public $isLoaded = false;

    protected function beforeCreate(): bool
    {
        if ($this->blockCreate) {
            return false;
        }
        $this->beforeCreateCalled = true;
        return true;
    }

    protected function afterCreate()
    {
        $this->afterCreateCalled = true;
    }

    protected function beforeUpdate(): bool
    {
        if ($this->blockUpdate) {
            return false;
        }
        $this->beforeUpdateCalled = true;
        return true;
    }

    protected function afterUpdate()
    {
        $this->afterUpdateCalled = true;
    }

    protected function beforeDelete(): bool
    {
        if ($this->blockDelete) {
            return false;
        }
        $this->beforeDeleteCalled = true;
        return true;
    }

    protected function afterDelete()
    {
        $this->afterDeleteCalled = true;
        $this->isLoaded = false;
    }

    protected function loaded()
    {
        $this->isLoaded = true;
    }

    public function isLoaded(): bool
    {
        return $this->isLoaded;
    }
}
