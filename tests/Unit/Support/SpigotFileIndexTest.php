<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use Illuminate\Database\Capsule\Manager as Capsule;
use Kazaminosuke\ModManager\Support\SpigotFileIndex;
use PHPUnit\Framework\TestCase;

class SpigotFileIndexTest extends TestCase
{
    private static ?Capsule $capsule = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$capsule === null) {
            self::$capsule = new Capsule();
            self::$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
            self::$capsule->setAsGlobal();
            self::$capsule->bootEloquent();
        }

        Capsule::schema()->dropIfExists('mod_manager_spigot_file_index');
        Capsule::schema()->create('mod_manager_spigot_file_index', function ($table): void {
            $table->id();
            $table->string('sha256', 64)->unique();
            $table->string('resource_id', 32);
            $table->string('version_id', 32);
            $table->string('version_number', 128);
            $table->string('plugin_name', 191)->nullable();
            $table->timestamps();
        });
    }

    public function test_remembers_and_finds_a_high_confidence_hash_mapping(): void
    {
        $index = new SpigotFileIndex();
        $hash = str_repeat('ab', 32);

        $index->remember($hash, '11431', '99', '7.0.9', 'WorldGuard');

        self::assertSame([
            'resource_id' => '11431',
            'version_id' => '99',
            'version_number' => '7.0.9',
            'plugin_name' => 'WorldGuard',
        ], $index->findBySha256($hash));
    }

    public function test_rejects_invalid_hashes_instead_of_guessing(): void
    {
        $index = new SpigotFileIndex();

        self::assertNull($index->findBySha256('not-a-hash'));
        $index->remember('short', '1', '2', '1.0.0');
        self::assertNull($index->findBySha256('short'));
    }
}
