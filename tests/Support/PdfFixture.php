<?php

namespace Tests\Support;

class PdfFixture
{
    public static function make(int $pages = 1, bool $script = false): string
    {
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R'.($script ? ' /OpenAction << /S /JavaScript /JS (example-only) >>' : '').' >>'];
        $kids = [];
        for ($i = 0; $i < $pages; $i++) {
            $page = 3 + $i * 2;
            $content = $page + 1;
            $kids[] = $page.' 0 R';
            $objects[$page] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 400] /Contents '.$content.' 0 R >>';
            $stream = "q\n0.9 0.85 0.75 rg\n30 30 240 340 re f\nQ\n";
            $objects[$content] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream.'endstream';
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.$pages.' >>';
        ksort($objects);
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "$id 0 obj\n$body\nendobj\n";
        }
        $start = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$i])."\n";
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n$start\n%%EOF\n";
    }
}
