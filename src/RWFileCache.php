<?php

namespace Raorsa\RWFileCache;

use JsonException;
use stdClass;

class RWFileCache
{
    /**
     * Cache configurations.
     *
     * @var string[]
     */
    protected array $config = [
        'gzipCompression' => true,
        'cacheDirectory' => '/tmp/rwFileCacheStorage/',
        'fileExtension' => 'cache',
    ];

    /**
     * Change the configuration values.
     *
     * @param array $config
     *
     * @return bool
     */
    public function changeConfig(array $config): bool
    {
        if (count(array_diff(array_keys($config), array_keys($this->config))) > 0) {
            return false;
        }

        $this->config = array_merge($this->config, $config);

        return true;
    }

    /**
     * Sets an item in the cache.
     *
     * @param mixed $key
     * @param mixed $content
     * @param int $expiry
     *
     * @return bool
     */
    public function set(string $key, mixed $content, int $expiry = 0): bool
    {
        $cacheObj = new stdClass();

        if (!is_string($content)) {
            $content = serialize($content);
        }

        $cacheObj->content = $content;

        $cacheObj->expiryTimestamp = $this->set_expiry($expiry);

        if ($cacheObj->expiryTimestamp < time()) {
            $result = false;
        } else {
            try {
                $cacheFileData = json_encode($cacheObj, JSON_THROW_ON_ERROR);
            } catch (JSONException) {
                $cacheFileData = false;
            }


            if ($this->config['gzipCompression']) {
                $cacheFileData = gzencode($cacheFileData, 9);
            }

            $filePath = $this->getFilePathFromKey($key);
            $result = file_put_contents($filePath, $cacheFileData);
        }

        return (bool)$result;
    }

    private function is_gzip($data): bool
    {
        return 0 === mb_strpos($data, "\x1f" . "\x8b" . "\x08", 0, "US-ASCII");
    }

    public function getObject(string $key, bool $absolute = false): object|false
    {
        if ($absolute) {
            $filePath = $key;
        } else {
            $filePath = $this->getFilePathFromKey($key);
        }


        if (!file_exists($filePath)) {
            return false;
        }

        if (!is_readable($filePath)) {
            return false;
        }

        $cacheFileData = file_get_contents($filePath);

        if ($this->is_gzip($cacheFileData)) {
            $cacheFileData = gzdecode($cacheFileData);
        }
        try {
            return json_decode($cacheFileData, false, 512, JSON_THROW_ON_ERROR);
        } catch (JSONException) {
            return false;
        }
    }

    /**
     * Returns a value from the cache.
     *
     * @param string $key
     *
     * @return mixed
     */
    public function get(string $key): mixed
    {
        $cacheObj = $this->getObject($key);

        if (!isset($cacheObj->expiryTimestamp) || $cacheObj->expiryTimestamp < time()) {
            return false;
        }

        $content = $cacheObj->content;

        if (($objectContent = @unserialize($content, [true])) !== false) {
            $content = $objectContent;
        } elseif ($content === serialize(false)) {
                $content = false;
            }

        return $content;

    }

    /**
     * Returns a value from the cache.
     *
     * @param string $key
     *
     * @return mixed
     */
    public function getLast(string $key): mixed
    {
        $cacheObj = $this->getObject($key);

        // Unable to decode JSON (could happen if compression was turned off while compressed caches still exist)
        return $cacheObj->content ?? false;

    }

    /**
     * Remove a value from the cache.
     *
     * @param string $key
     *
     * @return bool
     */
    public function delete(string $key): bool
    {
        $filePath = $this->getFilePathFromKey($key);

        if (!file_exists($filePath)) {
            return false;
        }

        return unlink($filePath);
    }

    /**
     * Wipe out all cache values.
     *
     * @return bool
     */
    public function flush(): bool
    {
        return $this->deleteDirectoryTree($this->config['cacheDirectory']);
    }

    public function clean(): void
    {
        foreach ($this->directoryTree($this->config['cacheDirectory']) as $file) {
            if (!is_dir($file)) {
                $object = $this->getObject($file, true);
                if ($object->expiryTimestamp < time()) {
                    unlink($file);
                }
            }
        }
    }

    /**
     * Removes cache files from a given directory.
     *
     * @param string $directory
     *
     * @return bool
     */
    private function deleteDirectoryTree(string $directory): bool
    {
        $filePaths = scandir($directory);

        foreach ($filePaths as $filePath) {
            if ($filePath === '.' || $filePath === '..') {
                continue;
            }

            $fullFilePath = $directory . '/' . $filePath;
            if (is_dir($fullFilePath)) {
                $result = $this->deleteDirectoryTree($fullFilePath);
                if ($result) {
                    $result = rmdir($fullFilePath);
                }
            } else {
                if (basename($fullFilePath) === '.keep') {
                    continue;
                }
                $result = unlink($fullFilePath);
            }

            if (!$result) {
                return false;
            }
        }

        return true;
    }

    private function directoryTree(string $directory): array
    {
        $files = [];
        $filePaths = scandir($directory);

        foreach ($filePaths as $filePath) {
            if ($filePath === '.' || $filePath === '..') {
                continue;
            }

            $fullFilePath = $directory . '/' . $filePath;
            if (is_dir($fullFilePath)) {
                array_merge($files, $this->directoryTree($fullFilePath));
            }
            $files[] = $fullFilePath;
        }

        return $files;
    }

    /**
     * Replaces a value within the cache.
     *
     * @param string $key
     * @param mixed $content
     * @param int $expiry
     *
     * @return bool
     */
    public function replace(string $key, mixed $content, int $expiry = 0): bool
    {
        if (!$this->get($key)) {
            return false;
        }

        return $this->set($key, $content, $expiry);
    }

    /**
     * Returns the file path from a given cache key, creating the relevant directory structure if necessary.
     *
     * @param string $key
     *
     * @return string|bool
     */
    protected function getFilePathFromKey(string $key): string|bool
    {
        $key = basename($key);
        $badChars = ['-', '.', '_', '\\', '*', '\"', '?', '[', ']', ':', ';', '|', '=', ','];
        $key = str_replace($badChars, '/', $key);
        while (str_contains($key, '//')) {
            $key = str_replace('//', '/', $key);
        }

        $directoryToCreate = $this->config['cacheDirectory'];

        $endOfDirectory = strrpos($key, '/');

        if ($endOfDirectory !== false) {
            $directoryToCreate = $this->config['cacheDirectory'] . substr($key, 0, $endOfDirectory);
        }

        if (!file_exists($directoryToCreate)) {
            $result = mkdir($directoryToCreate, 0777, true);
            if (!$result) {
                return false;
            }
        }

        return $this->config['cacheDirectory'] . $key . '.' . $this->config['fileExtension'];
    }

    /**
     * @param int $expiry
     * @return int
     */
    private function set_expiry(int $expiry): int
    {
        if (!$expiry) {
            // If no expiry specified, set to 'Never' expire timestamp (+10 years)
            return (time() + 315360000);
        }

        return ($expiry > 2592000) ? $expiry : time() + $expiry;
    }

    public static function store(string $key, mixed $data, int $expire = 0, array $config = null): bool
    {
        $self = new self();
        if (!is_null($config)) {
            $self->changeConfig($config);
        }
        return $self->set($key, $data, $expire);
    }

    public static function read(string $key, array $config = null): mixed
    {
        $self = new self();
        if (!is_null($config)) {
            $self->changeConfig($config);
        }

        return $self->get($key);
    }
}
