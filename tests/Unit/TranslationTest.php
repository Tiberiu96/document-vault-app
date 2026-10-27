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
        foreach (['en', 'hu', 'bg'] as $locale) {
            $this->assertSame($this->keys($romanian), $this->keys(require $root.$locale.'/site.php'));
        }
        $translator = new Translator(new FileLoader(new Filesystem, $root), 'ro');
        foreach (['ro', 'en', 'hu', 'bg'] as $locale) {
            foreach ($this->keys($romanian) as $key) {
                $this->assertTrue($translator->has('site.'.$key, $locale, false), $locale.': site.'.$key);
            }
        }
        $this->assertDoesNotMatchRegularExpression('/[ăâîșşțţĂÂÎȘŞȚŢ]/u', file_get_contents($root.'ro/site.php'));
        $this->assertStringContainsString('dokumentumok', file_get_contents($root.'hu/site.php'));
    }

    public function test_auth_and_registration_have_complete_bulgarian_translations(): void
    {
        $root = dirname(__DIR__, 2).'/lang/';
        $translator = new Translator(new FileLoader(new Filesystem, $root), 'bg');
        foreach (['auth', 'registration', 'routes'] as $group) {
            $english = require $root.'en/'.$group.'.php';
            $this->assertSame($this->keys($english), $this->keys(require $root.'bg/'.$group.'.php'));
            foreach ($this->keys($english) as $key) {
                $this->assertTrue($translator->has($group.'.'.$key, 'bg', false));
            }
        }
    }
}
