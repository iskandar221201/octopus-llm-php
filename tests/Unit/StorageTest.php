<?php

namespace OctopusLLM\Gateway\Tests\Unit;

use PHPUnit\Framework\TestCase;
use OctopusLLM\Gateway\Storage\NullStorage;
use OctopusLLM\Gateway\Storage\JsonFileStorage;

class StorageTest extends TestCase
{
    private string $tempDir;
    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/octopus_test_' . uniqid();
        $this->tempFile = $this->tempDir . '/state.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempFile)) {
            @unlink($this->tempFile);
        }
        if (is_dir($this->tempDir)) {
            @rmdir($this->tempDir);
        }
    }

    public function test_null_storage_load_returns_empty_array()
    {
        $storage = new NullStorage();
        $this->assertSame([], $storage->load());
    }

    public function test_null_storage_save_does_nothing()
    {
        $storage = new NullStorage();
        $storage->save(['foo' => 'bar']);
        $this->assertTrue(true);
    }

    public function test_json_storage_returns_empty_when_file_not_exists()
    {
        $storage = new JsonFileStorage($this->tempFile);
        $this->assertSame([], $storage->load());
    }

    public function test_json_storage_save_and_load()
    {
        $storage = new JsonFileStorage($this->tempFile);
        $data = ['keys' => ['test-key' => ['status' => 'active']]];

        $storage->save($data);
        $this->assertTrue(file_exists($this->tempFile));

        $loaded = $storage->load();
        $this->assertSame($data, $loaded);
    }

    public function test_json_storage_auto_creates_directory()
    {
        $this->assertFalse(is_dir($this->tempDir));

        $storage = new JsonFileStorage($this->tempFile);
        $storage->save(['test' => 123]);

        $this->assertTrue(is_dir($this->tempDir));
        $this->assertTrue(file_exists($this->tempFile));
    }

    public function test_json_storage_handles_corrupted_json()
    {
        mkdir($this->tempDir, 0755, true);
        file_put_contents($this->tempFile, '{invalid_json:');

        $storage = new JsonFileStorage($this->tempFile);
        $this->assertSame([], $storage->load());
    }
}
