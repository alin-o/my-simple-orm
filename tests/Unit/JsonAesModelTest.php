<?php

namespace Tests\Unit;

use AlinO\Db\MysqliDb;
use AlinO\MyOrm\Model;
use Tests\Models\JsonAesModel;
use Tests\TestCase;

class JsonAesModelTest extends TestCase
{
    public function tearDown(): void
    {
        Model::resetConnections();
        parent::tearDown();
    }

    public function testJsonFieldIsEncodedOnCreate()
    {
        $mockDb = $this->createMock(MysqliDb::class);
        $data = ['key' => 'value'];
        $encoded = json_encode($data);

        // Expect insert logic
        $mockDb->expects($this->once())
            ->method('insert')
            ->with(
                'json_aes_test',
                $this->callback(function ($input) use ($encoded) {
                    return isset($input['data']) && $input['data'] === $encoded;
                })
            )
            ->willReturn(1);

        // Expect find(1) logic
        $mockDb->method('where')->willReturnSelf();
        // Return simulated DB data (decrypted if AES, but here 'data' is just JSON)
        $mockDb->method('getOne')->willReturn([
            'id' => 1,
            'data' => $encoded,
            'secret_data' => 'raw_secret',
            'secret_json' => 'raw_secret_json'
        ]);
        $mockDb->method('setModel')->willReturnSelf();

        JsonAesModel::setConnection($mockDb);
        $model = JsonAesModel::create(['data' => $data]);

        $this->assertNotNull($model);
        $this->assertIsObject($model->data);
        $this->assertEquals('value', $model->data->key);
    }

    public function testAesAndJsonFieldIsEncodedAndEncryptedOnCreate()
    {
        $mockDb = $this->createMock(MysqliDb::class);
        $secretJson = ['secret' => 'code'];
        $encoded = json_encode($secretJson);

        $mockDb->expects($this->once())
            ->method('insert')
            ->with(
                'json_aes_test',
                $this->callback(function ($input) use ($encoded) {
                    // check if 'secret_json' is ['AES' => encoded_json_string]
                    return isset($input['secret_json'])
                        && is_array($input['secret_json'])
                        && isset($input['secret_json']['AES'])
                        && $input['secret_json']['AES'] === $encoded;
                })
            )
            ->willReturn(2);

        $mockDb->method('where')->willReturnSelf();
        // Simulate DB returning value for find(2).
        // Since getSelect() handles AES_DECRYPT injection, the DB (mocked) should return the DECRYPTED string.
        $mockDb->method('getOne')->willReturn([
            'id' => 2,
            'data' => null,
            'secret_data' => 'some_secret',
            'secret_json' => $encoded // This represents the result of AES_DECRYPT
        ]);
        $mockDb->method('setModel')->willReturnSelf();

        JsonAesModel::setConnection($mockDb);
        $model = JsonAesModel::create(['secret_json' => $secretJson]);

        $this->assertNotNull($model);
        // Should be decoded
        $this->assertIsObject($model->secret_json);
        $this->assertEquals('code', $model->secret_json->secret);
    }

    public function testLoadDecodesBoth()
    {
        $mockDb = $this->createMock(MysqliDb::class);
        $secretJson = ['foo' => 'bar'];
        $encoded = json_encode($secretJson);

        $mockDb->method('where')->willReturnSelf();
        $mockDb->method('getOne')->willReturn([
            'id' => 3,
            'secret_json' => $encoded // Mocking result of AES_DECRYPT check
        ]);
        $mockDb->method('setModel')->willReturnSelf();

        JsonAesModel::setConnection($mockDb);
        $model = JsonAesModel::find(3);

        $this->assertNotNull($model);
        $this->assertIsObject($model->secret_json);
        $this->assertEquals('bar', $model->secret_json->foo);
    }
}
