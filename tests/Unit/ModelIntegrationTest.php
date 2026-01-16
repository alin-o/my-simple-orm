<?php

namespace Tests\Unit;

use Tests\TestCase;
use Tests\Models\User;

class IntegrationUser extends User
{
    protected static $table = 'integration_users';
}

class ModelIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $db = User::db();
        $db->rawQuery("DROP TABLE IF EXISTS `integration_users`");
        $db->rawQuery("
            CREATE TABLE `integration_users` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `username` varchar(255) NOT NULL,
                `email` varchar(255) NOT NULL,
                `aes_pwd` varbinary(255) DEFAULT NULL,
                `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            )
        ");

        IntegrationUser::create(['username' => 'alice', 'email' => 'alice@example.com']);
        IntegrationUser::create(['username' => 'bob', 'email' => 'bob@example.com']);
        IntegrationUser::create(['username' => 'charlie', 'email' => 'charlie@example.com']);
    }

    protected function tearDown(): void
    {
        User::db()->rawQuery("DROP TABLE IF EXISTS `integration_users`");
        parent::tearDown();
    }

    public function testFirstReturnsInstance()
    {
        $user = IntegrationUser::where('username', 'alice')->first();
        $this->assertInstanceOf(IntegrationUser::class, $user);
        $this->assertEquals('alice', $user->username);
    }

    public function testFindReturnsInstance()
    {
        $alice = IntegrationUser::where('username', 'alice')->first();
        $user = IntegrationUser::find($alice->id());
        $this->assertInstanceOf(IntegrationUser::class, $user);
        $this->assertEquals('alice', $user->username);
    }

    /**
     * get() should return raw arrays
     */
    public function testGetReturnsRawArray()
    {
        $users = IntegrationUser::orderBy('username', 'ASC')->get();
        $this->assertIsArray($users);
        $this->assertCount(3, $users);
        $this->assertIsArray($users[0]);
        $this->assertEquals('alice', $users[0]['username']);
    }

    /**
     * all() should return model instances
     */
    public function testAllReturnsInstances()
    {
        $users = IntegrationUser::orderBy('username', 'ASC')->all();
        $this->assertIsArray($users);
        $this->assertCount(3, $users);
        $this->assertInstanceOf(IntegrationUser::class, $users[0]);
        $this->assertEquals('alice', $users[0]->username);
    }

    public function testOrderByDescWithTakeAndGet()
    {
        $users = IntegrationUser::orderByDesc('username')->take(2)->get();
        $this->assertCount(2, $users);
        $this->assertEquals('charlie', $users[0]['username']);
        $this->assertEquals('bob', $users[1]['username']);
    }

    public function testOrderByDescWithTakeAndAll()
    {
        $users = IntegrationUser::orderByDesc('username')->take(2)->all();
        $this->assertCount(2, $users);
        $this->assertInstanceOf(IntegrationUser::class, $users[0]);
        $this->assertEquals('charlie', $users[0]->username);
    }

    public function testExistsOnModel()
    {
        $this->assertTrue(IntegrationUser::where('username', 'alice')->exists());
        $this->assertFalse(IntegrationUser::where('username', 'missing')->exists());
    }

    public function testPluckOnModel()
    {
        $names = IntegrationUser::orderBy('username', 'ASC')->pluck('username');
        $this->assertEquals(['alice', 'bob', 'charlie'], $names);
    }

    public function testValueOnModel()
    {
        $email = IntegrationUser::where('username', 'bob')->value('email');
        $this->assertEquals('bob@example.com', $email);
    }
}
