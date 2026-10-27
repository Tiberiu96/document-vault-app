<?php

namespace App\Services\Documents;

use App\Services\PlanLimitsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class ScanPdf
{
    public function withPdf(array $pages, callable $callback): mixed
    {
        $limits = app(PlanLimitsService::class);
        $limits->validateDocument(0, count($pages));
        $directory = sys_get_temp_dir().'/vaulty-scan-'.bin2hex(random_bytes(12));
        if (! mkdir($directory, 0700)) {
            throw ValidationException::withMessages(['pages' => __('scanner.failed')]);
        }
        try {
            $pdf = new \FPDF;
            $pdf->SetAutoPageBreak(false);
            $bytes = 0;
            foreach ($pages as $index => $page) {
                $inspection = app(FileInspector::class)->inspect($page);
                if (! in_array($inspection['mime_type'], ['image/jpeg', 'image/png'], true)) {
                    throw ValidationException::withMessages(['pages' => __('scanner.invalid')]);
                }
                $bytes += $inspection['size_bytes'];
                $limits->validateDocument($bytes, count($pages));
                $image = @imagecreatefromstring(file_get_contents($page->getPathname()));
                if (! $image) {
                    throw ValidationException::withMessages(['pages' => __('scanner.invalid')]);
                }
                $width = imagesx($image);
                $height = imagesy($image);
                $jpeg = imagecreatetruecolor($width, $height);
                imagefill($jpeg, 0, 0, imagecolorallocate($jpeg, 255, 255, 255));
                imagecopy($jpeg, $image, 0, 0, 0, 0, $width, $height);
                $path = $directory.'/page-'.$index.'.jpg';
                imagejpeg($jpeg, $path, 85);
                unset($image, $jpeg);
                $pdf->AddPage($width > $height ? 'L' : 'P');
                $scale = min(($pdf->GetPageWidth() - 12) / $width, ($pdf->GetPageHeight() - 12) / $height);
                $w = $width * $scale;
                $h = $height * $scale;
                $pdf->Image($path, ($pdf->GetPageWidth() - $w) / 2, ($pdf->GetPageHeight() - $h) / 2, $w, $h, 'JPG');
            }
            $output = $directory.'/scan.pdf';
            $pdf->Output('F', $output);
            $limits->validateDocument(filesize($output), count($pages));

            return $callback(new UploadedFile($output, 'scan.pdf', 'application/pdf', null, true));
        } catch (ValidationException|AuthorizationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw ValidationException::withMessages(['pages' => __('scanner.failed')]);
        } finally {
            foreach (glob($directory.'/*') ?: [] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            @rmdir($directory);
        }
    }
}
