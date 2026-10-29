<?php

// Folosit numai de installer/export, ca utilizatorul runtime. Fara secrete in output.
use App\Jobs\Documents\ExpireDocumentDraft;
use App\Jobs\Documents\GenerateDocumentPreview;
use App\Jobs\Documents\PurgeDocument;
use App\Jobs\Documents\ReleaseDraftSource;
use App\Models\Document;
use App\Services\Backup\BackupBarrier;
use App\Services\Backup\BackupDatabase;
use App\Services\Backup\BackupRestorer;
use App\Services\Backup\BackupRunner;
use App\Services\Backup\BackupState;
use App\Services\Backup\ResticRepository;
use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

umask(0077);
$root = '/var/www/document-vault-app';
try {
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $mode = $argv[1] ?? '';
    $stage = $argv[2] ?? '';
    if (! app()->environment('production')) {
        throw new RuntimeException('PRODUCTION_REQUIRED');
    }
    if ($mode === 'preflight') {
        $connection = config('database.connections.'.config('database.default'));
        $settings = Dotenv::parse(file_get_contents($root.'/.env'));
        if (! config('backup.single_host') || ($connection['driver'] ?? '') !== 'mysql'
            || ! in_array($connection['host'] ?? '', ['127.0.0.1', 'localhost', '::1'], true)
            || ! empty($connection['url']) || ($connection['username'] ?? 'root') === 'root'
            || ! hash_equals((string) config('app.key'), (string) ($settings['APP_KEY'] ?? ''))) {
            throw new RuntimeException('SOURCE_CONFIGURATION_UNSUPPORTED');
        }
        DB::select('SELECT 1');
        Redis::connection()->ping();
        Redis::connection('cache')->ping();
        echo "Sursa productie/single-host si configuratia DB/APP_KEY: verificate.\n";
    } elseif ($mode === 'mail-test') {
        Mail::raw('Email de test dupa migrarea Vaulty.', function ($message) {
            $message->to(config('mail.from.address'))->subject('Vaulty - verificare server nou');
        });
        echo "Email acceptat de provider; verifica Inbox/Spam.\n";
    } elseif ($mode === 'backup-probe') {
        json_decode(app(ResticRepository::class)->run(['snapshots', '--json']), true, 512, JSON_THROW_ON_ERROR);
        echo "Repository Restic accesibil.\n";
    } elseif ($mode === 'export') {
        if (! app()->isDownForMaintenance() || ! is_dir($stage)) {
            throw new RuntimeException('EXPORT_MAINTENANCE_REQUIRED');
        }
        $lock = app(BackupState::class)->runLock();
        if (! $lock) {
            throw new RuntimeException('BACKUP_BUSY');
        }
        $barrier = app(BackupBarrier::class);
        $exclusive = $barrier->exclusive(360);
        try {
            app(BackupRunner::class)->snapshot($stage);
            $settings = Dotenv::parse(file_get_contents($root.'/.env'));
            if (! hash_equals((string) config('app.key'), (string) ($settings['APP_KEY'] ?? ''))) {
                throw new RuntimeException('ENV_CONFIG_KEY_MISMATCH');
            }
            file_put_contents($stage.'/settings.json', json_encode($settings, JSON_THROW_ON_ERROR));
            $pending = 0;
            foreach (['high', 'default', 'ocr', 'ai', 'low'] as $queue) {
                $pending += Queue::connection('redis')->size($queue);
            }
            $manifest = ['format' => 1, 'commit' => $argv[3] ?? '', 'captured_at' => gmdate('c'),
                'pending_jobs' => $pending, 'migrations' => DB::table('migrations')->orderBy('migration')->pluck('migration')->all(),
                'tables' => Schema::getTableListing(null, false), 'files' => []];
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isLink() || ! $file->isFile()) {
                    throw new RuntimeException('EXPORT_UNSAFE_FILE');
                }
                $relative = substr($file->getPathname(), strlen($stage) + 1);
                $manifest['files'][$relative] = ['size' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getPathname())];
            }
            file_put_contents($stage.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            echo "Export DB/documente/configurare pregatit.\n";
        } finally {
            $barrier->release($exclusive);
            $barrier->release($lock);
        }
    } elseif ($mode === 'import') {
        if (! app()->isDownForMaintenance()) {
            throw new RuntimeException('IMPORT_MAINTENANCE_REQUIRED');
        }
        $manifest = json_decode(file_get_contents($stage.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $recovery = json_decode(file_get_contents($stage.'/recovery.json'), true, 512, JSON_THROW_ON_ERROR);
        if (! hash_equals((string) config('app.key'), (string) ($recovery['app_key'] ?? ''))
            || $manifest['migrations'] !== DB::table('migrations')->orderBy('migration')->pluck('migration')->all()) {
            throw new RuntimeException('IMPORT_KEY_OR_SCHEMA_MISMATCH');
        }
        app(BackupDatabase::class)->import($stage.'/database.sql', $stage);
        if ($manifest['migrations'] !== DB::table('migrations')->orderBy('migration')->pluck('migration')->all()) {
            throw new RuntimeException('IMPORT_SCHEMA_MISMATCH');
        }
        $live = storage_path('app/private/workspaces');
        app(BackupRestorer::class)->assertTree($live);
        if (is_dir($stage.'/documents') && ! File::copyDirectory($stage.'/documents', $live)) {
            throw new RuntimeException('IMPORT_COPY_FAILED');
        }
        app(BackupRestorer::class)->verifyDocuments($live);
        app(BackupRestorer::class)->verifyEncryption();
        // Redis/cozile nu sunt copiate. Pastreaza reminderele viitoare nepreluate;
        // livrarile incerte si joburile fara coada necesita reexaminare explicita.
        DB::transaction(function () {
            DB::table('personal_access_tokens')->delete();
            DB::table('users')->update(['remember_token' => null]);
            DB::table('users')->whereNotNull('admin_mfa_confirmed_at')->update([
                'admin_mfa_last_counter' => intdiv(time(), 30), 'admin_mfa_version' => DB::raw('admin_mfa_version + 1'),
            ]);
            DB::table('reminder_deliveries')->where('status', 'processing')->update([
                'status' => 'uncertain', 'failure_code' => 'MIGRATION_REQUIRES_REVIEW', 'finished_at' => now(),
            ]);
            DB::table('processing_runs')->whereIn('status', ['queued', 'running'])->update([
                'status' => 'failed', 'error_code' => 'MIGRATION_REQUIRES_REVIEW', 'completed_at' => now(),
            ]);
            foreach (['ocr_status', 'ai_status'] as $column) {
                DB::table('documents')->whereIn($column, ['queued', 'running'])->update([$column => 'failed']);
            }
            DB::table('processing_usages')->update(['ocr_reserved' => 0, 'ai_reserved' => 0]);
        });
        // Reconstruieste numai lucrari locale idempotente de curatare/preview.
        // Nu retransmite emailuri si nu reia apeluri AI pe baza cozilor vechi.
        foreach (Document::withTrashed()->whereNotNull('deleted_at')->whereNotNull('purge_requested_at')->cursor() as $document) {
            PurgeDocument::dispatch($document->id, $document->workspace_id);
        }
        foreach (Document::where('status', 'draft')->cursor() as $document) {
            ExpireDocumentDraft::dispatch($document->id, $document->workspace_id)->delay($document->created_at->addDay());
            ReleaseDraftSource::dispatch($document->id, $document->workspace_id);
        }
        foreach (Document::where('status', 'stored')->whereIn('preview_status', ['pending', 'running'])->cursor() as $document) {
            GenerateDocumentPreview::dispatch($document->id, $document->workspace_id, $document->source_revision);
        }
        echo "Import verificat: schema, APP_KEY, criptare si hashuri documente.\n";
    } else {
        throw new RuntimeException('UNKNOWN_OPERATION');
    }
} catch (Throwable) {
    fwrite(STDERR, "Operatia de migrare a esuat; detaliile private nu sunt afisate. Mentenanta trebuie pastrata.\n");
    exit(1);
}
