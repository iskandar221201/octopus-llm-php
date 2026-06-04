<?php

namespace OctopusLLM\Gateway\Storage;

use OctopusLLM\Gateway\Contracts\StorageInterface;

class JsonFileStorage implements StorageInterface
{
    public function __construct(
        private string $path = 'storage/octopus-llm/state.json'
    ) {
    }

    public function load(): array
    {
        if (!file_exists($this->path)) {
            return [];
        }

        $handle = @fopen($this->path, 'r');
        if (!$handle) {
            return [];
        }

        flock($handle, LOCK_SH);
        $contents = stream_get_contents($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        if (!$contents) {
            return [];
        }

        $data = json_decode($contents, true);

        return is_array($data) ? $data : [];
    }

    public function save(array $state): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $tmpPath = $this->path . '.tmp';

        file_put_contents($tmpPath, $json, LOCK_EX);

        if (!@rename($tmpPath, $this->path)) {
            // Fallback for Windows if rename fails
            $handle = @fopen($this->path, 'c+');
            if ($handle) {
                flock($handle, LOCK_EX);
                ftruncate($handle, 0);
                fwrite($handle, $json);
                flock($handle, LOCK_UN);
                fclose($handle);
            }
            @unlink($tmpPath);
        }
    }
}
