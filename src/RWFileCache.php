<?php

namespace Raorsa\RWFileCache;

use Exception;

class RWFileCache
{
    /**
     * Cache configurations.
     *
     * @var string[]
     */
    protected $config = [
        'gzipCompression' => true,
        'cacheDirectory' => '/tmp/rwFileCacheStorage/',
        'fileExtension' => 'cache',
    ];

    /**
     * Change the configuration values.
     *
     * @param array $configArray
     *
     * @return bool
     */
    public function changeConfig($config)
    {
        if (!is_array($config)) {
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
    public function set($key, $content, $expiry = 0)
    {
        $cacheObj = new \stdClass();

        if (!is_string($content)) {
            $content = serialize($content);
        }

        $cacheObj->content = $content;

        $cacheObj->expiryTimestamp = $this->set_expiry($expiry);

        if ($cacheObj->expiryTimestamp < time()) {
            $result = false;
        } else {
            $cacheFileData = json_encode($cacheObj);

            if ($this->config['gzipCompression']) {
                $cacheFileData = gzencode($cacheFileData, 9);
            }

            $filePath = $this->getFilePathFromKey($key);
            $result = file_put_contents($filePath, $cacheFileData);
        }

        return $result ? true : false;
    }

    private function is_gzip($data)
    {
        return 0 === mb_strpos($data, "\x1f" . "\x8b" . "\x08", 0, "US-ASCII");
    }


    public function getObject($key, $absolute = false)
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

        return json_decode($cacheFileData);
    }


    /**
     * Returns a value from the cache.
     *
     * @param string $key
     *
     * @return mixed
     */
    public function get($key)
    {
        $cacheObj = $this->getObject($key);

        // Unable to decode JSON (could happen if compression was turned off while compressed caches still exist)
        if ($cacheObj === null) {
            return false;
        }


        if (isset($cacheObj->expiryTimestamp) && $cacheObj->expiryTimestamp > time()) {
            // Cache item has not yet expired or system load is too high
            $content = $cacheObj->content;

            if (($unserializedContent = @unserialize($content)) !== false) {
                // Normal unserialization
                $content = $unserializedContent;
            } elseif ($content == serialize(false)) {
                // Edge case to handle boolean false being stored
                $content = false;
            }

            return $content;
        } else {
            // Cache item has expired
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
    public function getLast($key)
    {
        $cacheObj = $this->getObject($key);

        // Unable to decode JSON (could happen if compression was turned off while compressed caches still exist)
        if ($cacheObj === null || !isset($cacheObj->content)) {
            return false;
        } else {
            return $cacheObj->content;
        }

    }

    /**
     * Remove a value from the cache.
     *
     * @param string $key
     *
     * @return bool
     */
    public function delete($key)
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
    public function flush()
    {
        return $this->deleteDirectoryTree($this->config['cacheDirectory']);
    }

    public function clean()
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
    private function deleteDirectoryTree($directory)
    {
        $filePaths = scandir($directory);

        foreach ($filePaths as $filePath) {
            if ($filePath == '.' || $filePath == '..') {
                continue;
            }

            $fullFilePath = $directory . '/' . $filePath;
            if (is_dir($fullFilePath)) {
                $result = $this->deleteDirectoryTree($fullFilePath);
                if ($result) {
                    $result = rmdir($fullFilePath);
                }
            } else {
                if (basename($fullFilePath) == '.keep') {
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

    private function directoryTree($directory)
    {
        $files = [];
        $filePaths = scandir($directory);

        foreach ($filePaths as $filePath) {
            if ($filePath == '.' || $filePath == '..') {
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
    public function replace($key, $content, $expiry = 0)
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
     * @return string
     */
    protected function getFilePathFromKey($key)
    {
        $key = basename($key);
        $badChars = ['-', '.', '_', '\\', '*', '\"', '?', '[', ']', ':', ';', '|', '=', ','];
        $key = str_replace($badChars, '/', $key);
        while (strpos($key, '//') !== false) {
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

        $filePath = $this->config['cacheDirectory'] . $key . '.' . $this->config['fileExtension'];

        return $filePath;
    }

    /**
     * @param $expiry
     * @return void
     */
    private function set_expiry($expiry)
    {
        if (!$expiry) {
            // If no expiry specified, set to 'Never' expire timestamp (+10 years)
            return (time() + 315360000);
        } elseif ($expiry > 2592000) {
            // For value greater than 30 days, interpret as timestamp
            return $expiry;
        } else {
            // Else, interpret as number of seconds
            return (time() + $expiry);
        }
    }

    public static function store($key, $data, $expire = 0, $config = null)
    {
        $self = new self();
        if (!is_null($config)) {
            $self->changeConfig($config);
        }
        return $self->set($key, $data, $expire);
    }

    public static function read($key, $config = null)
    {
        $self = new self();
        if (!is_null($config)) {
            $self->changeConfig($config);
        }

        return $self->get($key);
    }
}
