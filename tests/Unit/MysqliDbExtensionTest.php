<?php

namespace Tests\Unit;

use AlinO\Db\MysqliDb;
use Tests\TestCase;

class TestUser
{
    public $id;
    public $name;
    public $email;
    public function __construct($data)
    {
        foreach ($data as $k => $v)
            $this->$k = $v;
    }
}

class MysqliDbExtensionTest extends TestCase
{
    /** @var MysqliDb */
    protected $db;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = \Tests\Models\User::getConnection();

        if (!$this->db) {
            // Fallback if TestCase didn't run or failed
            $this->db = new MysqliDb(
                '127.0.0.1',
                'test',
                'secret',
                'test',
                3306
            );
        }

        $this->db->rawQuery("DROP TABLE IF EXISTS `test_users`");
        $this->db->rawQuery("
            CREATE TABLE `test_users` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `name` varchar(255) NOT NULL,
                `email` varchar(255) NOT NULL,
                `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            )
        ");

        $this->db->insertMulti('test_users', [
            ['name' => 'Alice', 'email' => 'alice@example.com'],
            ['name' => 'Bob', 'email' => 'bob@example.com'],
            ['name' => 'Charlie', 'email' => 'charlie@example.com'],
            ['name' => 'David', 'email' => 'david@example.com'],
            ['name' => 'Eve', 'email' => 'eve@example.com'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->db->rawQuery("DROP TABLE `test_users`");
        parent::tearDown();
    }

    public function testFind()
    {
        $user = $this->db->where('name', 'Bob')->getOne('test_users');
        $id = $user['id'];

        $found = $this->db->from('test_users')->where('id', $id)->first();
        $this->assertNotNull($found);
        $this->assertEquals('Bob', $found['name']);

        // Test helper find() which assumes where('id', $id)
        // Wait, the new find($id) implementation adds where('id', $id).
        // But table needs to be known? No, MysqliDb stateful chain needs table in first() or get().

        // $this->db->find($id) -> this would fail because table is not set?
        // Let's check implementation:
        // public function find($id) { return $this->where('id', $id)->first(); }
        // public function first($columns = null) { ... return $this->getOne($this->modelTable, $cols); }

        // MysqliDb is stateful. If we do $db->get('users'), table is 'users'.
        // For find($id), we set where, then call first.
        // first() calls getOne($this->modelTable, ...).
        // $this->modelTable is set via setModel() or from().
        // If we are using raw MysqliDb without setModel/from, first() might fail if $modelTable is null AND we don't pass table to first()?
        // Actually first() uses $this->modelTable. If it's null, getOne(null) throws error "Table not defined" unless previously set properties?
        // No, getOne($tableName) requires tableName.

        // So `find()` and `first()` are primarily for when `setModel` or `from` is used or if `modelTable` is populated.

        // Let's verify usage WITH from() or setModel().

        $this->db->setModel(TestUser::class, 'test_users');
        $found2 = $this->db->find($id);
        $this->assertInstanceOf(TestUser::class, $found2);
        $this->assertEquals('Bob', $found2->name);

        // Reset state for next parts/tests
        $this->db->setModel('', 'test_users');
        $this->db->from('test_users');

        $found3 = $this->db->from('test_users')->find($id);
        $this->assertEquals('Bob', $found3['name']);
    }

    public function testFirst()
    {
        $first = $this->db->from('test_users')->orderBy('id', 'ASC')->first();
        $this->assertEquals('Alice', $first['name']);
    }

    public function testValue()
    {
        $name = $this->db->from('test_users')->where('email', 'charlie@example.com')->value('name');
        $this->assertEquals('Charlie', $name);
    }

    public function testPluck()
    {
        $names = $this->db->from('test_users')->orderBy('id', 'ASC')->pluck('name');
        $this->assertEquals(['Alice', 'Bob', 'Charlie', 'David', 'Eve'], $names);

        $keyed = $this->db->from('test_users')->orderBy('id', 'ASC')->pluck('name', 'email');
        $this->assertEquals('Alice', $keyed['alice@example.com']);
    }

    public function testExists()
    {
        $exists = $this->db->from('test_users')->where('name', 'David')->exists();
        $this->assertTrue($exists);

        $notExists = $this->db->from('test_users')->where('name', 'Zorro')->exists();
        $this->assertFalse($notExists);
    }

    public function testOrderByDesc()
    {
        // Alice(1), Bob(2)... Eve(5)
        $desc = $this->db->from('test_users')->orderByDesc('id')->first();
        $this->assertEquals('Eve', $desc['name']);
    }

    public function testTakeAndSkip()
    {
        // skip 2 (Alice, Bob), take 2 (Charlie, David)
        $subset = $this->db->from('test_users')
            ->orderBy('id', 'ASC')
            ->skip(2)
            ->take(2)
            ->get();

        $this->assertCount(2, $subset);
        $this->assertEquals('Charlie', $subset[0]['name']);
        $this->assertEquals('David', $subset[1]['name']);
    }
}
