<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\Http;

class BackupInspection
{
    public function refresh(): array
    {
        app(ResticRepository::class)->configured();
        $control = app(BackupRestoreControl::class);
        $snapshots = $control->snapshots();
        $bucket = $this->bucketUsage();
        $result = ['snapshots' => $snapshots, 'bucket' => $bucket, 'at' => now()->timestamp];
        $control->write('inspection', $result);

        return $result;
    }

    public function bucketUsage(): array
    {
        $host = config('backup.account_id').(config('backup.jurisdiction') === 'eu' ? '.eu' : '').'.r2.cloudflarestorage.com';
        $path = '/'.rawurlencode(config('backup.bucket'));
        $bytes = 0;
        $objects = 0;
        $token = null;
        $deadline = microtime(true) + 30;
        for ($page = 0; $page < 10; $page++) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $query = ['list-type' => '2', 'max-keys' => '1000'];
            if ($token !== null) {
                $query['continuation-token'] = $token;
            }
            ksort($query);
            $query = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            $date = gmdate('Ymd\THis\Z');
            $day = substr($date, 0, 8);
            $hash = hash('sha256', '');
            $headers = 'host:'.$host."\n".'x-amz-content-sha256:'.$hash."\n".'x-amz-date:'.$date."\n";
            $signed = 'host;x-amz-content-sha256;x-amz-date';
            $canonical = "GET\n$path\n$query\n$headers\n$signed\n$hash";
            $scope = $day.'/auto/s3/aws4_request';
            $string = "AWS4-HMAC-SHA256\n$date\n$scope\n".hash('sha256', $canonical);
            $key = hash_hmac('sha256', $day, 'AWS4'.config('backup.secret_key'), true);
            foreach (['auto', 's3', 'aws4_request'] as $part) {
                $key = hash_hmac('sha256', $part, $key, true);
            }
            $authorization = 'AWS4-HMAC-SHA256 Credential='.config('backup.access_key').'/'.$scope.', SignedHeaders='.$signed.', Signature='.hash_hmac('sha256', $string, $key);
            $response = Http::connectTimeout(5)->timeout(10)->withoutRedirecting()->withHeaders(['Authorization' => $authorization, 'x-amz-content-sha256' => $hash, 'x-amz-date' => $date])->get('https://'.$host.$path.'?'.$query);
            if (! $response->successful() || strlen($response->body()) > 2097152) {
                throw new \RuntimeException('BACKUP_R2_USAGE_UNAVAILABLE');
            }
            $old = libxml_use_internal_errors(true);
            try {
                if (stripos($response->body(), '<!DOCTYPE') !== false) {
                    throw new \RuntimeException('BACKUP_R2_USAGE_INVALID');
                }
                $xml = simplexml_load_string($response->body(), \SimpleXMLElement::class, LIBXML_NONET);
                if (! $xml || $xml->getName() !== 'ListBucketResult') {
                    throw new \RuntimeException('BACKUP_R2_USAGE_INVALID');
                }
                foreach ($xml->Contents as $object) {
                    if (! ctype_digit((string) $object->Size)) {
                        throw new \RuntimeException('BACKUP_R2_USAGE_INVALID');
                    }
                    $bytes += (int) $object->Size;
                    $objects++;
                }
                if ((string) $xml->IsTruncated === 'false') {
                    return ['bytes' => $bytes, 'objects' => $objects, 'complete' => true];
                }
                $token = (string) $xml->NextContinuationToken;
                if ($token === '') {
                    throw new \RuntimeException('BACKUP_R2_USAGE_INVALID');
                }
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($old);
            }
        }

        return ['bytes' => $bytes, 'objects' => $objects, 'complete' => false];
    }
}
