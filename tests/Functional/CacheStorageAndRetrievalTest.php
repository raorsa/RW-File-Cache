<?php

use PHPUnit\Framework\TestCase;
use Raorsa\RWFileCache\RWFileCache;

final class CacheStorageAndRetrievalTest extends TestCase
{
    private $cache = null;

    public function setUp(): void
    {
        $this->cache = new RWFileCache();
        $this->cache->changeConfig(['cacheDirectory' => __DIR__ . '/Data/']);
    }

    public function tearDown(): void
    {
        $this->cache->flush();
        unset($this->cache);
    }

    public function testBasicString()
    {
        $stored = 'Mary had a little lamb.';

        $key = __FUNCTION__;
        $this->cache->set($key, $stored, strtotime('+ 1 day'));

        $retrieved = $this->cache->get($key);

        $this->assertEquals($stored, $retrieved);
    }

    public function testEmptyArray()
    {
        $stored = [];

        $key = __FUNCTION__;
        $this->cache->set($key, $stored, strtotime('+ 1 day'));

        $retrieved = $this->cache->get($key);

        $this->assertEquals($stored, $retrieved);
    }

    public function testNumericZero()
    {
        $stored = 0;

        $key = __FUNCTION__;
        $this->cache->set($key, $stored, strtotime('+ 1 day'));

        $retrieved = $this->cache->get($key);

        $this->assertEquals($stored, $retrieved);
    }

    public function testBooleanFalse()
    {
        $stored = false;

        $key = __FUNCTION__;
        $this->cache->set($key, $stored, strtotime('+ 1 day'));

        $retrieved = $this->cache->get($key);

        $this->assertEquals($stored, $retrieved);
    }

    public function testBooleanTrue()
    {
        $stored = false;

        $key = __FUNCTION__;
        $this->cache->set($key, $stored, strtotime('+ 1 day'));

        $retrieved = $this->cache->get($key);

        $this->assertEquals($stored, $retrieved);
    }

    public function testDeepDirectoryCreation()
    {
        $stored = 'Deep directory creation test.';

        $key = 'deep.directory.creation.test';
        $this->cache->set($key, $stored, strtotime('+ 1 day'));

        $retrieved = $this->cache->get($key);

        $this->assertEquals($stored, $retrieved);
    }

    public function testDeepDirectoryCreationTwo()
    {
        $stored = 'Deep directory creation test 2.';

        $key = 'deep.directory.creation.test2';
        $this->cache->set($key, $stored, strtotime('+ 1 day'));

        $retrieved = $this->cache->get($key);

        $this->assertEquals($stored, $retrieved);
    }

    public function testDeepDirectoryCreationWithMultipleSlashes()
    {
        $stored = 'Deep directory creation test 2.';

        $key = 'deep//directory/creation////test';
        $this->cache->set($key, $stored, strtotime('+ 1 day'));

        $retrieved = $this->cache->get($key);

        $this->assertEquals($stored, $retrieved);
    }
}
