<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class CheckServices extends Command
{
    protected $signature = 'vault:services:check';

    protected $description = 'Verifica MySQL si Redis dupa configurarea locala, fara afisarea credentialelor.';

    public function handle(): int
    {
        $valid = true;
        try {
            if (DB::connection()->getDriverName() !== 'mysql') {
                $this->error('MySQL: selecteaza DB_CONNECTION=mysql.');
                $valid = false;
            } else {
                DB::connection()->select('SELECT 1');
                $this->info('MySQL: conexiune verificata.');
            }
        } catch (Throwable) {
            $this->error('MySQL: conexiune indisponibila. Verifica serviciul si configuratia locala.');
            $valid = false;
        }

        try {
            $response = Redis::connection()->ping();
            if (! in_array($response, [true, 'PONG', '+PONG'], true)) {
                $this->error('Redis: raspuns neasteptat.');
                $valid = false;
            } else {
                $this->info('Redis: conexiune verificata.');
            }
        } catch (Throwable) {
            $this->error('Redis: conexiune indisponibila. Verifica serviciul si configuratia locala.');
            $valid = false;
        }

        return $valid ? self::SUCCESS : self::FAILURE;
    }
}
