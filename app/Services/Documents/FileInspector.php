<?php

namespace App\Services\Documents;

use App\Services\PlanLimitsService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class FileInspector
{
    public function inspect(UploadedFile $file): array
    {
        $invalid = fn () => throw ValidationException::withMessages(['file' => __('documents.invalid_file')]);
        if (! $file->isValid()) {
            $invalid();
        }
        $bytes = filesize($file->getPathname());
        app(PlanLimitsService::class)->validateDocument($bytes, 1);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getPathname());
        $extensions = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
        if (! isset($extensions[$mime])) {
            $invalid();
        }
        $allowedExtensions = ['application/pdf' => ['pdf'], 'image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png']];
        if (! in_array(strtolower($file->getClientOriginalExtension()), $allowedExtensions[$mime], true)) {
            $invalid();
        }
        if ($mime === 'application/pdf') {
            try {
                $process = new Process([config('vault.qpdf_binary'), '--json', '--json-stream-data=none', '--json-key=pages', '--json-key=encrypt', '--json-key=qpdf', $file->getPathname()]);
                $process->setTimeout(15);
                $process->run(function () use ($process) {
                    if (strlen($process->getOutput()) + strlen($process->getErrorOutput()) > 16 * 1024 * 1024) {
                        $process->stop(0);
                    }
                });
                if (! $process->isSuccessful()) {
                    $invalid();
                }
                $json = json_decode($process->getOutput(), true, 64, JSON_THROW_ON_ERROR);
                if (($json['encrypt']['encrypted'] ?? true) || ! isset($json['pages']) || ! isset($json['qpdf'])) {
                    $invalid();
                }
                $blocked = ['/JavaScript', '/JS', '/OpenAction', '/AA', '/Launch', '/EmbeddedFiles', '/EmbeddedFile', '/RichMedia', '/XFA', '/AcroForm', '/SubmitForm', '/ImportData'];
                $scan = function (array $data) use (&$scan, $blocked, $invalid) {
                    foreach ($data as $key => $value) {
                        if (in_array($key, $blocked, true) || (is_string($value) && in_array($value, $blocked, true))) {
                            $invalid();
                        }
                        if (is_array($value)) {
                            $scan($value);
                        }
                    }
                };
                $scan($json['qpdf']);
                $pages = count($json['pages']);
            } catch (ValidationException $exception) {
                throw $exception;
            } catch (\Throwable) {
                $invalid();
            }
        } else {
            $size = @getimagesize($file->getPathname());
            if (! $size || ($size['mime'] ?? null) !== $mime || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > 25000000) {
                $invalid();
            }
            // Decodare reala, nu doar incredere in headerul imaginii.
            $image = @imagecreatefromstring(file_get_contents($file->getPathname()));
            if ($image === false) {
                $invalid();
            }
            unset($image);
            $pages = 1;
        }
        app(PlanLimitsService::class)->validateDocument($bytes, $pages);

        return ['mime_type' => $mime, 'size_bytes' => $bytes, 'page_count' => $pages, 'sha256' => hash_file('sha256', $file->getPathname()), 'extension' => $extensions[$mime]];
    }
}
