<?php

use PHPUnit\Framework\TestCase;
use Raorsa\RWFileCache\RWFileCache;

final class BasicTest extends TestCase
{
    private $cache = null;
    private $config = ['cacheDirectory' => __DIR__ . '/Data/', 'gzipCompression' => false];

    public function setUp(): void
    {
        $this->cache = new RWFileCache();
        $this->cache->changeConfig($this->config);
    }

    public function tearDown(): void
    {
        $this->cache->flush();
        unset($this->cache);
    }

    public function testSetCompressAndRead()
    {
        $stored = 'This info is compress';
        $key = __FUNCTION__;
        $this->cache->changeConfig(['gzipCompression' => true]);
        $this->cache->set($key, $stored);

        $this->assertEquals($stored, $this->cache->get($key));
    }

    public function testSetUncompressAndRead()
    {
        $stored = 'This info is uncompress';
        $key = __FUNCTION__;
        $this->cache->changeConfig(['gzipCompression' => false]);
        $this->cache->set($key, $stored);
        $this->assertEquals($stored, $this->cache->get($key));
    }

    public function testSettingInvalidCacheConfig()
    {
        $this->assertFalse($this->cache->changeConfig('invalid_data'));
    }

    public function testDelete()
    {
        $stored = 'Mary had a little lamb.';

        $key = __FUNCTION__;
        $this->cache->set($key, $stored, strtotime('+ 1 day'));

        $this->assertEquals($stored, $this->cache->get($key));

        $this->cache->delete($key);

        $this->assertFalse($this->cache->get($key));
    }

    public function testReplace()
    {
        $stored = 'Mary had a little lamb.';
        $stored2 = 'Mary had a big dog.';

        $key = __FUNCTION__;

        $this->cache->replace($key, $stored, strtotime('+ 1 day'));

        $this->assertFalse($this->cache->get($key));

        $this->cache->set($key, $stored, strtotime('+ 1 day'));

        $this->assertEquals($stored, $this->cache->get($key));

        $this->cache->replace($key, $stored2, strtotime('+ 1 day'));

        $this->assertEquals($stored2, $this->cache->get($key));
    }

    public function testSetCacheThatHasAlreadyExpired()
    {
        $stored = 'Mary had a little lamb.';

        $key = __FUNCTION__;
        $this->cache->set($key, $stored, strtotime('- 1 day'));

        $this->assertFalse($this->cache->get($key));
    }

    public function testSetCacheWithoutExpiryTime()
    {
        $stored = 'Mary had a little lamb.';

        $key = __FUNCTION__;
        $this->cache->set($key, $stored);

        $this->assertEquals($stored, $this->cache->get($key));
    }

    public function testGetNonexistantCache()
    {
        $key = __FUNCTION__;
        $this->cache->get($key);

        $this->assertFalse($this->cache->get($key));
    }

    public function testSetExpiryInSeconds()
    {
        $key = __FUNCTION__;
        $this->assertTrue($this->cache->set($key, 'test', 1));
    }

    public function testExpiryOfCache()
    {
        $stored = 'expiry_test_value';

        $key = __FUNCTION__;
        $this->cache->set($key, $stored, 2);

        $this->assertEquals($stored, $this->cache->get($key));

        if (getenv('TRAVIS') == 'true') {
            $this->markTestSkipped('Travis CI does not seem to sleep correctly, so cache expiry can not be tested correctly.');
        }

        sleep(3);

        $this->assertFalse($this->cache->get($key));
    }

    public function testGetLast()
    {
        $stored = 'This info stored and expired';
        $key = __FUNCTION__;
        $this->cache->set($key, $stored, 2);
        sleep(3);
        $this->assertFalse($this->cache->get($key));
        $this->assertEquals($stored, $this->cache->getLast($key));
    }

    public function testStaticStore()
    {
        $stored = 'Static store';
        $key = __FUNCTION__;
        RWFileCache::store($key, $stored, 0, $this->config);

        $this->assertEquals($stored, $this->cache->get($key));
    }

    public function testStaticRead()
    {
        $stored = 'Static store';
        $key = __FUNCTION__;

        $this->cache->set($key, $stored);

        $this->assertEquals($stored, RWFileCache::read($key, $this->config));
    }

    public function testFlush()
    {
        $stored = 'Mary had a little lamb.';

        $key = __FUNCTION__;
        $this->cache->set($key, $stored, strtotime('+ 1 day'));

        $this->assertEquals($stored, $this->cache->get($key));

        $this->cache->flush();

        $this->assertFalse($this->cache->get($key));
    }

    public function testClean()
    {
        $stored = 'Mary had a little lamb.';
        $key = __FUNCTION__;
        for ($i = 1; $i < 10; $i++) {
            $this->cache->set($key . $i, $stored . $i, $i < 5 ? 1 : 10);
            $this->cache->set($i . '.' . $key, $stored . $i, $i < 5 ? 1 : 10);
        }
        sleep(3);

        $this->cache->clean();

        for ($i = 1; $i < 10; $i++) {
            if ($i < 5) {
                $this->assertFalse($this->cache->get($key . $i));
                $this->assertFalse($this->cache->get($i . '.' . $key));
            } else {
                $this->assertEquals($stored . $i, $this->cache->getLast($key . $i));
                $this->assertEquals($stored . $i, $this->cache->getLast($i . '.' . $key));
            }

        }
    }
}
