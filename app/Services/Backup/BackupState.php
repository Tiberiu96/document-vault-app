<?php

namespace App\Services\Backup;

class BackupState
{
    public function directory(): string
    {
        $path = config('backup.directory');
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new \RuntimeException('BACKUP_DIRECTORY_UNAVAILABLE');
        }

        return $path;
    }

    public function read(): array
    {
        $file = $this->directory().'/state.json';

        return is_file($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
    }

    public function update(array $values): array
    {
        $lock = fopen($this->directory().'/state.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX)) {
            throw new \RuntimeException('BACKUP_STATE_UNAVAILABLE');
        }
        try {
            $state = array_replace($this->read(), $values);
            $file = $this->directory().'/state.json';
            $temp = $file.'.'.bin2hex(random_bytes(4));
            file_put_contents($temp, json_encode($state, JSON_THROW_ON_ERROR));
            chmod($temp, 0600);
            rename($temp, $file);

            return $state;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function runLock()
    {
        $lock = fopen($this->directory().'/run.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            return null;
        }

        return $lock;
    }
}
