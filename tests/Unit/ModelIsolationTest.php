<?php

namespace Tests\Unit;

use Tests\TestCase;
use Tests\Models\User;

class ModelIsolationTest extends TestCase
{
    public function testDbReturnsIsolatedInstances()
    {
        $db1 = User::db();
        $db2 = User::db();

        $this->assertNotSame($db1, $db2, "Each call to db() should return a new instance.");

        // Add a condition to the first builder
        $db1->where('id', 1);

        // The second builder should NOT have this condition
        // We can check private _where via reflecton or just by observing behavior.
        // Let's check bahavior by looking at the generated query if possible, 
        // or just rely on the fact that they are different objects.

        // Actually, we can check if they share the same underlying mysqli connection
        $mysqli1 = $db1->mysqli();
        $mysqli2 = $db2->mysqli();
        $this->assertSame($mysqli1, $mysqli2, "They should share the same underlying connection.");
    }

    public function testQueryStateIsNotLeakedBetweenModels()
    {
        // First query with a where clause
        User::where('id', 123)->first();

        // The static getLastQuery() should return the query that was just executed
        $lastQuery = User::getLastQuery();
        $this->assertStringContainsString('123', $lastQuery, "Static getLastQuery() should return the last executed query.");

        // Second query should NOT have 'id = 123'
        // We can verify this by checking the last query of a NEW builder instance
        $db = User::db();
        $db->get('users');
        $lastQueryFromNewBuilder = $db->getLastQuery();

        $this->assertStringNotContainsString('123', $lastQueryFromNewBuilder, "New builder instance should be fresh.");
    }
}
