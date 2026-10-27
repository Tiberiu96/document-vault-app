<?php

namespace App\Services\Ocr;

use App\Contracts\Ocr\OcrProviderInterface;
use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class LocalOcrProvider implements OcrProviderInterface
{
    private function run(array $arguments, int $timeout): void
    {
        try {
            $process = new Process($arguments);
            $process->setTimeout($timeout)->disableOutput();
            $process->run();
            if (! $process->isSuccessful()) {
                throw new OcrException('OCR_PROCESS_FAILED');
            }
        } catch (OcrException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new OcrException('OCR_RUNTIME_UNAVAILABLE');
        }
    }

    private function text(string $path): string
    {
        if (! is_file($path) || filesize($path) > 500000) {
            throw new OcrException('OCR_OUTPUT_INVALID');
        }
        $text = file_get_contents($path);
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new OcrException('OCR_OUTPUT_INVALID');
        }

        return trim(str_replace("\0", '', $text));
    }

    public function extract(Document $document): array
    {
        $source = Storage::disk('local')->path($document->storage_path);
        $temp = sys_get_temp_dir().'/vaulty-ocr-'.bin2hex(random_bytes(12));
        if (! mkdir($temp, 0700)) {
            throw new OcrException('OCR_TEMPORARY_STORAGE_FAILED');
        }
        try {
            $textPath = $temp.'/result.txt';
            if ($document->mime_type === 'application/pdf') {
                $this->run([config('ocr.pdftotext'), '-enc', 'UTF-8', '-layout', $source, $textPath], 20);
                $native = $this->text($textPath);
                $pages = explode("\f", $native);
                $nonEmpty = count(array_filter($pages, fn ($page) => preg_match('/[\p{L}\p{N}]/u', $page)));
                if ($nonEmpty >= $document->page_count) {
                    return ['text' => $native, 'engine' => 'native_pdf'];
                }
                $output = $temp.'/ocr.pdf';
                $this->run([config('ocr.ocrmypdf'), '--skip-text', '--jobs', '1', '--language', 'ron+eng+hun', '--tesseract-timeout', '15', '--max-image-mpixels', '25', '--output-type', 'pdf', '--optimize', '0', $source, $output], 180);
                $this->run([config('ocr.pdftotext'), '-enc', 'UTF-8', '-layout', $output, $textPath], 20);

                return ['text' => $this->text($textPath), 'engine' => 'ocrmypdf'];
            }
            $this->run([config('ocr.tesseract'), $source, $temp.'/result', '-l', 'ron+eng+hun', '--psm', '3'], 120);

            return ['text' => $this->text($textPath), 'engine' => 'tesseract'];
        } finally {
            foreach (glob($temp.'/*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            @rmdir($temp);
        }
    }
}
