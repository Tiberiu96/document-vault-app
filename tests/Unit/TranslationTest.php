<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Translation\FileLoader;
use Illuminate\Translation\Translator;
use PHPUnit\Framework\TestCase;

class TranslationTest extends TestCase
{
    private function keys(array $values, string $prefix = ''): array
    {
        $keys = [];
        foreach ($values as $key => $value) {
            $path = $prefix.$key;
            if (is_array($value)) {
                array_push($keys, ...$this->keys($value, $path.'.'));
            } else {
                $keys[] = $path;
            }
        }

        return $keys;
    }

    public function test_all_interface_languages_have_the_same_content_keys(): void
    {
        $root = dirname(__DIR__, 2).'/lang/';
        $romanian = require $root.'ro/site.php';
        foreach (['en', 'hu'] as $locale) {
            $this->assertSame($this->keys($romanian), $this->keys(require $root.$locale.'/site.php'));
        }
        $translator = new Translator(new FileLoader(new Filesystem, $root), 'ro');
        foreach (['ro', 'en', 'hu'] as $locale) {
            foreach ($this->keys($romanian) as $key) {
                $this->assertTrue($translator->has('site.'.$key, $locale, false), $locale.': site.'.$key);
            }
        }
        $this->assertDoesNotMatchRegularExpression('/[ăâîșşțţĂÂÎȘŞȚŢ]/u', file_get_contents($root.'ro/site.php'));
        $this->assertStringContainsString('dokumentumok', file_get_contents($root.'hu/site.php'));
    }
}
