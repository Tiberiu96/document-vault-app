<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

class BackupDatabase
{
    public function assertSchema(string $sql): void
    {
        $stream = fopen($sql, 'rb');
        if (! $stream) {
            throw new \RuntimeException('RESTORE_SQL_MISSING');
        }
        $tables = [];
        try {
            while (($line = fgets($stream)) !== false) {
                if (preg_match('/^CREATE TABLE `([a-zA-Z0-9_]+)` /', $line, $match)) {
                    $tables[] = $match[1];
                }
                if (preg_match('/^(USE |CREATE DATABASE|DROP DATABASE|GRANT |CREATE USER)/i', trim($line))) {
                    throw new \RuntimeException('RESTORE_SQL_UNSAFE');
                }
            }
        } finally {
            fclose($stream);
        }
        $current = Schema::getTableListing(null, false);
        sort($current);
        sort($tables);
        if ($tables === [] || $tables !== $current) {
            throw new \RuntimeException('RESTORE_SCHEMA_MISMATCH');
        }
    }

    public function import(string $sql, string $directory, bool $validateSchema = true): void
    {
        if ($validateSchema) {
            $this->assertSchema($sql);
        }
        $connection = config('database.connections.mysql');
        if (config('database.default') !== 'mysql' || ! empty($connection['url']) || empty($connection['database']) || in_array($connection['username'], ['root', ''], true)) {
            throw new \RuntimeException('RESTORE_DATABASE_CONFIGURATION');
        }
        $quote = fn ($v) => '"'.str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], (string) $v).'"';
        $text = "[client]\n";
        foreach (['user' => 'username', 'password' => 'password', 'host' => 'host', 'port' => 'port', 'socket' => 'unix_socket'] as $key => $field) {
            if (($connection[$field] ?? '') !== '') {
                $text .= $key.'='.$quote($connection[$field])."\n";
            }
        }
        $file = $directory.'/import.cnf';
        file_put_contents($file, $text);
        chmod($file, 0600);
        $stream = fopen($sql, 'rb');
        if (! $stream) {
            @unlink($file);
            throw new \RuntimeException('RESTORE_SQL_MISSING');
        }
        try {
            $process = new Process([config('backup.mysql'), '--defaults-extra-file='.$file, '--binary-mode=1', '--local-infile=0', '--database='.$connection['database']], base_path(), null, $stream, config('backup.process_seconds'));
            $process->disableOutput();
            if ($process->run() !== 0) {
                throw new \RuntimeException('RESTORE_IMPORT_FAILED');
            }
        } finally {
            fclose($stream);
            @unlink($file);
        }
    }
}
