<?php

namespace Kazaminosuke\ModManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TranslationKeyParityTest extends TestCase
{
    public function test_supported_translation_files_have_the_same_leaf_keys(): void
    {
        $root = dirname(__DIR__, 2).'/lang';
        $keys = [];

        foreach (['en', 'ja', 'de'] as $locale) {
            $keys[$locale] = $this->leafKeys(require $root.'/'.$locale.'/strings.php');
        }

        self::assertSame($keys['en'], $keys['ja']);
        self::assertSame($keys['en'], $keys['de']);
        self::assertNotContains('server_mod_manager.enabled', $keys['en']);
        self::assertNotContains('server_mod_manager.enabled_helper', $keys['en']);
    }

    /**
     * @param array<string, mixed> $values
     * @return list<string>
     */
    private function leafKeys(array $values, string $prefix = ''): array
    {
        $keys = [];

        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $keys = [...$keys, ...$this->leafKeys($value, $path)];
            } else {
                $keys[] = $path;
            }
        }

        sort($keys);

        return $keys;
    }
}
