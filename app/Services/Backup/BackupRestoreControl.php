<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Crypt;

class BackupRestoreControl
{
    public function directory(): string
    {
        $path = app(BackupState::class)->directory().'/restore';
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new \RuntimeException('RESTORE_STORAGE');
        }

        return $path;
    }

    public function snapshots(): array
    {
        $rows = json_decode(app(ResticRepository::class)->run(['snapshots', '--json', '--host', 'vaulty-production', '--tag', 'vaulty-verified']), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($rows)) {
            throw new \RuntimeException('RESTORE_SNAPSHOTS');
        }
        $result = [];
        foreach ($rows as $row) {
            if (! preg_match('/\A[a-f0-9]{64}\z/', $row['id'] ?? '') || ! in_array('vaulty-verified', $row['tags'] ?? [], true)) {
                continue;
            }
            $result[] = ['id' => $row['id'], 'at' => (new \DateTimeImmutable($row['time']))->getTimestamp()];
        }
        usort($result, fn ($a, $b) => $b['at'] <=> $a['at']);

        return $result;
    }

    public function read(string $name): array
    {
        $path = $this->directory().'/'.$name.'.json';

        return is_file($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
    }

    public function write(string $name, array $data): void
    {
        $path = $this->directory().'/'.$name.'.json';
        $temp = $path.'.'.bin2hex(random_bytes(6));
        file_put_contents($temp, json_encode($data, JSON_THROW_ON_ERROR));
        chmod($temp, 0600);
        rename($temp, $path);
    }

    public function request(string $snapshot, string $scope, ?int $actor): string
    {
        if (! config('backup.restore_enabled') || ! config('backup.restore_dedicated_redis') || ! in_array($scope, ['all', 'mysql', 'documents'], true)) {
            throw new \RuntimeException('RESTORE_DISABLED');
        }
        if (! in_array($snapshot, array_column($this->snapshots(), 'id'), true)) {
            throw new \RuntimeException('RESTORE_SNAPSHOT_UNAVAILABLE');
        }
        $lock = fopen($this->directory().'/request.lock', 'c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            throw new \RuntimeException('RESTORE_BUSY');
        }
        try {
            if (is_file($this->directory().'/request.json') || is_file($this->directory().'/incomplete')) {
                throw new \RuntimeException('RESTORE_BUSY');
            }
            $id = bin2hex(random_bytes(16));
            $payload = ['id' => $id, 'snapshot' => $snapshot, 'scope' => $scope, 'actor' => $actor, 'at' => now()->timestamp];
            $this->write('status', ['id' => $id, 'snapshot' => $snapshot, 'scope' => $scope, 'actor' => $actor, 'at' => now()->timestamp, 'phase' => 'queued']);
            $this->write('request', ['encrypted' => Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR))]);

            return $id;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function pending(): array
    {
        $data = json_decode(Crypt::decryptString($this->read('request')['encrypted'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        if (! config('backup.restore_enabled') || ! preg_match('/\A[a-f0-9]{32}\z/', $data['id'] ?? '') || ! preg_match('/\A[a-f0-9]{64}\z/', $data['snapshot'] ?? '') || ! in_array($data['scope'] ?? '', ['all', 'mysql', 'documents'], true) || ($data['at'] ?? 0) < now()->timestamp - 600 || ($data['at'] ?? 0) > now()->timestamp + 30) {
            throw new \RuntimeException('RESTORE_REQUEST_INVALID');
        }

        return $data;
    }
}
