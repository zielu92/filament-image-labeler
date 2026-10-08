<?php

namespace Zielu92\FilamentImageLabeler\Tests;

class FieldViewTest extends TestCase
{
    public function test_field_blade_compiles_without_syntax_errors(): void
    {
        // The x-filament:: anonymous components ship with filament/support; register
        // their view namespace so the tag compiler can resolve them outside a panel app.
        $this->app['view']->getFinder()->addNamespace(
            'filament',
            __DIR__ . '/../vendor/filament/support/resources/views'
        );

        $source = file_get_contents(__DIR__ . '/../resources/views/image-labeler.blade.php');

        $compiled = $this->app->make('blade.compiler')->compileString($source);

        // token_get_all throws on invalid PHP; also assert our new hooks survived compilation.
        $tokens = @token_get_all($compiled);
        $this->assertIsArray($tokens);
        $this->assertStringContainsString('searchEntities', $compiled);
        $this->assertStringContainsString('ensureEntities', $compiled);
        $this->assertStringContainsString('activeEntity', $compiled);
    }

    public function test_entity_language_files_have_matching_keys(): void
    {
        $en = array_keys(require __DIR__ . '/../resources/lang/en/image-labeler.php');
        $de = array_keys(require __DIR__ . '/../resources/lang/de/image-labeler.php');
        $pl = array_keys(require __DIR__ . '/../resources/lang/pl/image-labeler.php');

        $this->assertEqualsCanonicalizing($en, $de);
        $this->assertEqualsCanonicalizing($en, $pl);

        foreach (['en', 'de', 'pl'] as $locale) {
            $strings = require __DIR__ . "/../resources/lang/{$locale}/image-labeler.php";
            $this->assertArrayHasKey('entity', $strings);
            foreach (['title', 'search_placeholder', 'create', 'unlink', 'no_results'] as $key) {
                $this->assertNotEmpty($strings['entity'][$key], "{$locale}.entity.{$key}");
            }
        }
    }
}
