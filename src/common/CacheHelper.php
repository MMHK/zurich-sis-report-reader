<?php


namespace MMHK\common;


class CacheHelper
{
    protected $saveDir;
    /**
     * CacheHelper constructor.
     */
    public function __construct($saveDir = null)
    {
        if (empty($saveDir)) {
            $saveDir = dirname(dirname(__DIR__)).'/temp';
        }

        $this->saveDir = $saveDir;
        if (!file_exists($saveDir)) {
            mkdir($saveDir, 0777, true);
        }
    }

    public function put($key, $value) {
        $hash = md5($key);
        $json = serialize($value);
        return file_put_contents("{$this->saveDir}/{$hash}.cache", $json);
    }

    public function remove($key) {
        $hash = md5($key);
        return unlink("{$this->saveDir}/{$hash}.cache");
    }

    public function get($key, $default = null) {
        $hash = md5($key);

        if (!file_exists("{$this->saveDir}/{$hash}.cache")) {
            return $default;
        }

        $content = file_get_contents("{$this->saveDir}/{$hash}.cache");

        return unserialize($content) ? : $default;
    }

    public function flush() {
        $files = glob($this->saveDir.'/*');
        array_walk($files, function($file) {
            if (is_file($file)) {
                unlink($file);
            }
        });
    }
}