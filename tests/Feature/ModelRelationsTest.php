<?php

namespace Tests\Feature;

use Tests\Models\Address;
use Tests\Models\User;
use Tests\Models\Role;
use AlinO\Db\DbException;
use Tests\TestCase;

class ModelRelationsTest extends TestCase
{
    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::create([
            'username' => 'testuser_' . uniqid(),
            'email' => 'testuser_' . uniqid() . '@example.com',
            'fname' => 'Test',
            'lname' => 'User',
            'status' => 0
        ]);
        $this->assertNotNull($this->user, 'Failed to create test user');
    }

    protected function tearDown(): void
    {
        if ($this->user instanceof User && $this->user->id) {
            $this->user->update(['shipping_address' => null, 'billing_address' => null]);
            // Delete related addresses
            $addresses = $this->user->addresses;
            if ($addresses) {
                foreach ($addresses as $address) {
                    $address->delete();
                }
            }
            // Delete related user_roles
            User::where('user_id', $this->user->id)->delete('user_roles');
            $this->user->delete();
        }
        parent::tearDown();
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        User::where('email', 'testuser_%@example.com', 'LIKE')->update('users', ['shipping_address' => null, 'billing_address' => null]);
        Address::db()
            ->join('users', 'users.id = addresses.user_id', 'LEFT')
            ->where('users.email', 'testuser_%@example.com', 'LIKE')
            ->delete('addresses');
        User::where('email', 'testuser_%@example.com', 'LIKE')->delete();
    }

    // User Tests

    public function testCanCreateUserWithValidData(): void
    {
        $this->assertNotNull($this->user->id, 'User ID should be set after creation');
        $this->assertStringStartsWith('testuser_', $this->user->username, 'Username should match input');
        $this->assertSame('Test', $this->user->fname, 'First name should match input');
    }

    public function testCanUpdateUserStatus(): void
    {
        $ok = $this->user->update(['status' => 1]);
        $this->assertTrue($ok, 'Updating user status should succeed');
        $freshUser = User::find($this->user->id);
        $this->assertSame(1, $freshUser->status, 'User status should be updated to 1');
    }

    // Address Tests

    public function testCanCreateAddressForUser(): void
    {
        $address = Address::create([
            'user_id' => $this->user->id,
            'fname' => $this->user->fname,
            'lname' => $this->user->lname,
            'country_id' => 1,
        ]);
        $this->assertNotNull($address->id, 'Address creation should return a model instance');
        $this->assertSame($this->user->id, $address->user_id, 'Address should be linked to the user');
    }

    public function testCanUpdateAddressDetails(): void
    {
        $address = Address::create([
            'user_id' => $this->user->id,
            'fname' => $this->user->fname,
            'lname' => $this->user->lname,
            'country_id' => 1,
        ]);
        $ok = $address->update(['address' => 'TEST shipping address']);
        $this->assertTrue($ok, 'Updating address should succeed');
        $freshAddress = new Address($address->id);
        $this->assertSame('TEST shipping address', $freshAddress->address, 'Address should be updated');
    }

    public function testCanSetUserShippingAddress(): void
    {
        $address = Address::create([
            'user_id' => $this->user->id,
            'fname' => $this->user->fname,
            'lname' => $this->user->lname,
            'country_id' => 1,
        ]);
        $this->user->saddress = $address;
        $this->assertSame($address->id, $this->user->shipping_address, 'Shipping address ID should be set');
        $fetchedAddress = $this->user->saddress;
        $this->assertNotNull($fetchedAddress, 'Shipping address should be retrievable');
        $this->assertSame($address->id, $fetchedAddress->id, 'Fetched shipping address should match');
    }

    public function testAddressBelongsToUser(): void
    {
        $address = Address::create([
            'user_id' => $this->user->id,
            'fname' => $this->user->fname,
            'lname' => $this->user->lname,
            'country_id' => 1,
        ]);
        $relatedUser = $address->user;
        $this->assertNotNull($relatedUser, 'Address should have a related user');
        $this->assertSame($this->user->id, $relatedUser->id, 'Related user should match the original user');
    }

    public function testUserCanHaveMultipleAddresses(): void
    {
        $address1 = Address::create([
            'user_id' => $this->user->id,
            'fname' => $this->user->fname,
            'lname' => $this->user->lname,
            'country_id' => 1,
            'address' => 'First Address'
        ]);
        $address2 = Address::create([
            'user_id' => $this->user->id,
            'fname' => $this->user->fname,
            'lname' => $this->user->lname,
            'country_id' => 1,
            'address' => 'Second Address'
        ]);
        $addresses = $this->user->addresses;
        $this->assertCount(2, $addresses, 'User should have two addresses');
        $addressIds = array_map(fn($a) => $a->id, $addresses);
        $this->assertContains($address1->id, $addressIds, 'First address should be linked');
        $this->assertContains($address2->id, $addressIds, 'Second address should be linked');
    }

    public function testCanRemoveAddressFromUser(): void
    {
        $address1 = Address::create([
            'user_id' => $this->user->id,
            'fname' => $this->user->fname,
            'lname' => $this->user->lname,
            'country_id' => 1,
        ]);
        $address2 = Address::create([
            'user_id' => $this->user->id,
            'fname' => $this->user->fname,
            'lname' => $this->user->lname,
            'country_id' => 1,
        ]);
        $ok = $address2->delete();
        $this->assertTrue($ok, 'Deleting address should succeed');
        $addresses = $this->user->addresses;
        $this->assertCount(1, $addresses, 'User should have one address after deletion');
        $addressIds = array_map(fn($a) => $a->id, $addresses);
        $this->assertContains($address1->id, $addressIds, 'Remaining address should be linked');
        $this->assertNotContains($address2->id, $addressIds, 'Deleted address should not be linked');
    }

    public function testCanUnsetShippingAddress(): void
    {
        $address = Address::create([
            'user_id' => $this->user->id,
            'fname' => $this->user->fname,
            'lname' => $this->user->lname,
            'country_id' => 1,
        ]);
        $this->user->saddress = $address;
        $this->user->saddress = null;
        $this->assertNull($this->user->shipping_address, 'Shipping address should be unset');
        $this->assertNull($this->user->saddress, 'Shipping address relation should be null');
    }

    // Edge Case Tests

    public function testCannotCreateAddressWithInvalidUserId(): void
    {
        $this->expectException(\mysqli_sql_exception::class);
        Address::create([
            'user_id' => 'invalid-id',
            'fname' => 'Test',
            'lname' => 'User',
            'country_id' => 1,
        ]);
    }

    public function testCannotCreateUserWithDuplicateEmail(): void
    {
        $email = 'duplicate_' . uniqid() . '@example.com';
        $user1 = User::create([
            'username' => 'user1_' . uniqid(),
            'email' => $email,
            'fname' => 'Test',
            'lname' => 'User',
            'status' => 0
        ]);
        $this->assertNotNull($user1, 'First user creation should succeed');
        $this->expectException(\mysqli_sql_exception::class);
        User::create([
            'username' => 'user2_' . uniqid(),
            'email' => $email,
            'fname' => 'Test',
            'lname' => 'User',
            'status' => 0
        ]);
        $user1->delete();
    }

    public function testCanCreateRole(): void
    {
        $a = User::getSelect();
        $b = Role::getSelect();
        $role = Role::create(['name' => 'admin_' . uniqid()]);

        $this->assertNotNull($role->id, 'Role ID should be set after creation');
        $this->assertStringStartsWith('admin_', $role->name, 'Role name should match input');
        $role->delete();
    }

    public function testCanAssignRolesToUser(): void
    {
        $role1 = Role::create(['name' => 'role1_' . uniqid()]);
        $role2 = Role::create(['name' => 'role2_' . uniqid()]);
        $this->user->roles = [$role1->id, $role2->id];
        $roles = $this->user->roles;
        $this->assertCount(2, $roles, 'User should have two roles');
        $roleIds = array_map(fn($r) => $r->id, $roles);
        $this->assertContains($role1->id, $roleIds, 'Role1 should be assigned');
        $this->assertContains($role2->id, $roleIds, 'Role2 should be assigned');
        $role1->delete();
        $role2->delete();
    }

    public function testCanGetUsersForRole(): void
    {
        $role = Role::create(['name' => 'role_' . uniqid()]);
        $user2 = User::create([
            'username' => 'user2_' . uniqid(),
            'email' => 'user2_' . uniqid() . '@example.com',
            'fname' => 'User2',
            'lname' => 'Test',
            'status' => 0
        ]);
        $this->user->roles = [$role->id];
        $user2->roles = [$role->id];
        $users = $role->users;
        $this->assertCount(2, $users, 'Role should have two users');
        $userIds = array_map(fn($u) => $u->id, $users);
        $this->assertContains($this->user->id, $userIds, 'User1 should be assigned');
        $this->assertContains($user2->id, $userIds, 'User2 should be assigned');
        User::db()->where('user_id', $user2->id)->delete('user_roles');
        $user2->delete();
        $role->delete();
    }

    // New Tests for Find Methods

    public function testCanFindUserById(): void
    {
        $user = User::find($this->user->id);
        $this->assertNotNull($user, 'User should be found');
        $this->assertSame($this->user->id, $user->id, 'Found user ID should match');
    }

    public function testFindNonExistentUserReturnsNull(): void
    {
        $user = User::find(999999);
        $this->assertNull($user, 'Should return null for non-existent ID');
    }

    public function testCanFindMultipleUsersByIds(): void
    {
        $user2 = User::create([
            'username' => 'user2_' . uniqid(),
            'email' => 'user2_' . uniqid() . '@example.com',
            'fname' => 'User2',
            'lname' => 'Test',
            'status' => 0
        ]);
        $users = User::findAll([$this->user->id, $user2->id]);
        $this->assertCount(2, $users, 'Should find two users');
        $userIds = array_map(fn($u) => $u->id, $users);
        $this->assertContains($this->user->id, $userIds, 'Should include first user');
        $this->assertContains($user2->id, $userIds, 'Should include second user');
        User::db()->where('user_id', $user2->id)->delete('user_roles');
        $user2->delete();
    }

    // New Tests for List Method

    public function testCanListUserEmailsKeyedById(): void
    {
        $list = User::list('email', 'id');
        $this->assertIsArray($list, 'List should be an array');
        $this->assertArrayHasKey($this->user->id, $list, 'List should include the user');
        $this->assertSame($this->user->email, $list[$this->user->id], 'Email should match');
    }

    // New Tests for AssureUnique Method

    public function testAssureUniqueForNewUser(): void
    {
        $unique = User::assureUnique('email', 'unique_' . uniqid() . '@example.com');
        $this->assertNull($unique, 'Should be unique, return null');
        $existing = User::assureUnique('email', $this->user->email);
        $this->assertSame($this->user->id, $existing, 'Should return existing user ID');
    }

    // New Tests for With Method

    public function testToArrayWithHasManyThroughRelated(): void
    {
        $role1 = Role::create(['name' => 'role1_' . uniqid()]);
        $role2 = Role::create(['name' => 'role2_' . uniqid()]);
        $this->user->roles = [$role1->id, $role2->id];
        $data = $this->user->with('roles')->toArray();
        $this->assertArrayHasKey('roles', $data, 'Should include roles');
        $this->assertIsArray($data['roles'], 'Roles should be an array');
        $roleIds = array_column($data['roles'], 'id');
        $this->assertContains($role1->id, $roleIds, 'Should include role1 ID');
        $this->assertContains($role2->id, $roleIds, 'Should include role2 ID');
        $role1->delete();
        $role2->delete();
    }

    // New Tests for Only Method

    public function testOnlyMethod(): void
    {
        $data = $this->user->only('username, email');
        $this->assertArrayHasKey('username', $data, 'Should include username');
        $this->assertArrayHasKey('email', $data, 'Should include email');
        $this->assertArrayNotHasKey('fname', $data, 'Should not include fname');
    }

    // New Tests for Hooks

    public function testBeforeCreateHookCanBlockSave(): void
    {
        $user = new User([
            'username' => 'blocktest_' . uniqid(),
            'email' => 'blocktest_' . uniqid() . '@example.com',
            'fname' => 'Block',
            'lname' => 'Test',
            'status' => 0
        ]);
        $user->blockCreate = true;
        $saved = $user->save();
        $this->assertFalse($saved, 'Save should fail if beforeCreate returns false');
        $this->assertNull($user->id, 'ID should not be set');
    }

    public function testAfterCreateHook(): void
    {
        $user = User::create([
            'username' => 'hooktest_' . uniqid(),
            'email' => 'hooktest_' . uniqid() . '@example.com',
            'fname' => 'Hook',
            'lname' => 'Test',
            'status' => 0
        ]);
        $this->assertTrue($user->afterCreateCalled, 'afterCreate should be called');
        User::db()->where('user_id', $user->id)->delete('user_roles');
        $user->delete();
    }

    public function testBeforeUpdateHookCanBlockUpdate(): void
    {
        $this->user->blockUpdate = true;
        $updated = $this->user->update(['status' => 1]);
        $this->assertFalse($updated, 'Update should fail if beforeUpdate returns false');
        $freshUser = User::find($this->user->id);
        $this->assertSame(0, $freshUser->status, 'Status should not be updated');
    }

    public function testBeforeDeleteHookCanBlockDelete(): void
    {
        $this->user->blockDelete = true;
        $deleted = $this->user->delete();
        $this->assertFalse($deleted, 'Delete should fail if beforeDelete returns false');
        $freshUser = User::find($this->user->id);
        $this->assertNotNull($freshUser, 'User should still exist');
    }

    // New Tests for AES Encryption and Extra Fields

    public function testAesEncryption(): void
    {
        $key = User::db()->rawQueryValue('SELECT @aes_key');
        $this->assertNotEmpty($key[0], 'AES key should be set');
        $email = 'aes_test_' . uniqid() . '@example.com';
        $pwd = 'testpwd_' . uniqid();
        $user = User::create([
            'username' => 'aesuser_' . uniqid(),
            'email' => $email,
            'aes_pwd' => $pwd,
            'fname' => 'Aes',
            'lname' => 'Test',
            'status' => 0
        ]);

        $rawData = User::db()->where('id', $user->id)->getOne('users', 'aes_pwd');
        $this->assertNotEquals($pwd, $rawData['aes_pwd'], 'Password should be encrypted in database');
        $rawData = User::db()->where('id', $user->id)->getOne('users', 'AES_DECRYPT(aes_pwd, @aes_key) as aes_pwd');
        $this->assertEquals($pwd, $rawData['aes_pwd'], 'Password should be correctly stored encrypted in database');
        $this->assertSame($pwd, $user->aes_pwd, 'Model should decrypt password correctly');
        User::db()->where('user_id', $user->id)->delete('user_roles');
        $user->delete();
    }

    public function testExtraFields(): void
    {
        $this->user->extra_data = 'some value';
        $this->assertSame('some value', $this->user->extra_data, 'Extra field should be set');
        $this->user->save();
        $freshUser = User::find($this->user->id);
        $this->assertNull($freshUser->extra_data, 'Extra field should not be persisted');
    }
}
