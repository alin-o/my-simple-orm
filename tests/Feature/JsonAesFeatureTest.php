<?php

namespace Tests\Feature;

use AlinO\MyOrm\Model;
use Tests\Models\JsonAesModel;
use Tests\TestCase;

class JsonAesFeatureTest extends TestCase
{
    protected static $createdModels = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Create the test table for this feature test
        // using static::$defaultDb which is set in TestCase::setUpBeforeClass
        $db = JsonAesModel::db();
        $db->rawQuery("CREATE TABLE IF NOT EXISTS `json_aes_test` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `data` TEXT DEFAULT NULL,
            `secret_data` BLOB DEFAULT NULL,
            `secret_json` BLOB DEFAULT NULL,
            PRIMARY KEY (`id`)
        )");
    }

    protected function tearDown(): void
    {
        // Drop the table after tests
        $db = JsonAesModel::db();
        $db->rawQuery("DROP TABLE IF EXISTS `json_aes_test`");

        parent::tearDown();
    }

    public function testCreateAndRetrieveJsonField()
    {
        $data = ['key' => 'value', 'list' => [1, 2, 3]];

        $model = JsonAesModel::create(['data' => $data]);
        $this->assertNotNull($model, 'Model should be created');
        $this->assertEquals(1, $model->id);

        // Check if data is accessible as object/array
        $this->assertIsObject($model->data);
        $this->assertEquals('value', $model->data->key);
        $this->assertEquals([1, 2, 3], $model->data->list);

        // Verify raw data in DB is JSON string
        $raw = JsonAesModel::db()->where('id', $model->id)->getOne('json_aes_test', 'data');
        $this->assertIsString($raw['data']);
        $this->assertEquals(json_encode($data), $raw['data']);
    }

    public function testCreateAndRetrieveAesField()
    {
        $secret = "Super Secret String";

        $model = JsonAesModel::create(['secret_data' => $secret]);
        $this->assertNotNull($model);

        // Check if accessible decrypted
        $this->assertEquals($secret, $model->secret_data);

        // Verify raw data in DB is NOT the plain string (it's encrypted)
        // We select the column directly without AES_DECRYPT
        $raw = JsonAesModel::db()->where('id', $model->id)->getOne('json_aes_test', 'secret_data');
        $this->assertNotEquals($secret, $raw['secret_data']);
        $this->assertNotEmpty($raw['secret_data']);
    }

    public function testCreateAndRetrieveJsonAesField()
    {
        $secretData = ['apiKey' => '12345-SECRET', 'settings' => ['enable' => true]];

        $model = JsonAesModel::create(['secret_json' => $secretData]);
        $this->assertNotNull($model);

        // Check if accessible as decrypted object
        $this->assertIsObject($model->secret_json);
        $this->assertEquals('12345-SECRET', $model->secret_json->apiKey);
        $this->assertTrue($model->secret_json->settings->enable);

        // Verify raw data in DB is encrypted (not a valid json string of our data)
        $raw = JsonAesModel::db()->where('id', $model->id)->getOne('json_aes_test', 'secret_json');

        // It should NOT be the json string
        $jsonString = json_encode($secretData);
        $this->assertNotEquals($jsonString, $raw['secret_json']);

        // It should be encrypted binary/blob data
        $this->assertNotEmpty($raw['secret_json']);

        // Verify via manual decryption query to be 100% sure
        $decryptedRow = JsonAesModel::db()
            ->where('id', $model->id)
            ->getOne('json_aes_test', "AES_DECRYPT(secret_json, @aes_key) as decrypted");

        $this->assertEquals($jsonString, $decryptedRow['decrypted']);
    }

    public function testUpdateJsonAesField()
    {
        $initialData = ['status' => 'initial'];
        $model = JsonAesModel::create(['secret_json' => $initialData]);

        $this->assertEquals('initial', $model->secret_json->status);

        $updatedData = ['status' => 'updated', 'ts' => 123456];
        $model->secret_json = $updatedData;
        $model->save();

        // Reload from DB to verify persistence
        // We use a fresh model instance to ensure no internal caching hides issues
        $loaded = JsonAesModel::find($model->id);

        $this->assertEquals('updated', $loaded->secret_json->status);
        $this->assertEquals(123456, $loaded->secret_json->ts);

        // Verify raw updated data is encrypted
        $raw = JsonAesModel::db()->where('id', $model->id)->getOne('json_aes_test', 'secret_json');
        $this->assertNotEquals(json_encode($updatedData), $raw['secret_json']);
    }
}
