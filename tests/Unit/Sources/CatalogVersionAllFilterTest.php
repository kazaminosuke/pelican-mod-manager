<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Sources;

use App\Models\Egg;
use App\Models\Server;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Providers\ModManagerServiceProvider;
use Kazaminosuke\ModManager\Sources\CurseForgeSource;
use Kazaminosuke\ModManager\Sources\HangarSource;
use Kazaminosuke\ModManager\Sources\ModrinthSource;
use Kazaminosuke\ModManager\Sources\SpigotSource;
use Kazaminosuke\ModManager\Support\CatalogFields;
use Kazaminosuke\ModManager\Support\MinecraftVersionResolver;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
final class CatalogVersionAllFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
            'pelican-mod-manager.curseforge_api_key' => 'test-curseforge-credential',
        ]);
        DB::purge('sqlite');
        $this->app->register(ModManagerServiceProvider::class);
        Http::preventStrayRequests();

        Schema::create('egg_variables', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('egg_id');
            $table->string('env_variable');
            $table->string('default_value')->default('');
        });
        Schema::create('server_variables', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('server_id');
            $table->unsignedInteger('variable_id');
            $table->string('variable_value')->default('');
        });
    }

    public function test_explicit_all_disables_version_filtering_on_modrinth(): void
    {
        $server = $this->fakeServer('fabric', '1.21.1');
        self::assertSame('1.21.1', MinecraftVersionResolver::resolve($server));
        Http::fake([
            'api.modrinth.com/v2/search*' => Http::response(['hits' => [], 'total_hits' => 0]),
        ]);

        app(ModrinthSource::class)->search($server, ProjectType::Mod, 1, null, [
            'versions' => [CatalogFields::VERSION_ALL],
        ]);

        Http::assertSent(static function ($request): bool {
            $facets = $request['facets'] ?? '';

            return str_contains($request->url(), 'api.modrinth.com/v2/search')
                && is_string($facets)
                && !str_contains($facets, 'versions:');
        });
    }

    public function test_explicit_all_disables_version_filtering_on_curseforge(): void
    {
        $server = $this->fakeServer('fabric', '1.21.1');
        self::assertSame('1.21.1', MinecraftVersionResolver::resolve($server));
        Http::fake([
            'api.curseforge.com/v1/mods/search*' => Http::response(['data' => [], 'pagination' => ['totalCount' => 0]]),
        ]);

        app(CurseForgeSource::class)->search($server, ProjectType::Mod, 1, null, [
            'versions' => [CatalogFields::VERSION_ALL],
        ]);

        Http::assertSent(static function ($request): bool {
            return str_contains($request->url(), 'api.curseforge.com/v1/mods/search')
                && !isset($request['gameVersion'])
                && !isset($request['gameVersions']);
        });
    }

    public function test_explicit_all_disables_version_filtering_on_hangar(): void
    {
        $server = $this->fakeServer('paper', '1.21.1');
        self::assertSame('1.21.1', MinecraftVersionResolver::resolve($server));
        Http::fake([
            'hangar.papermc.io/api/v1/projects*' => Http::response(['result' => [], 'pagination' => ['count' => 0]]),
        ]);

        app(HangarSource::class)->search($server, ProjectType::Plugin, 1, null, [
            'versions' => [CatalogFields::VERSION_ALL],
        ]);

        Http::assertSent(static function ($request): bool {
            return str_contains($request->url(), 'hangar.papermc.io/api/v1/projects')
                && !isset($request['version']);
        });
    }

    public function test_explicit_all_disables_version_filtering_on_spigot(): void
    {
        $server = $this->fakeServer('paper', '1.21.1');
        self::assertSame('1.21.1', MinecraftVersionResolver::resolve($server));
        Http::fake([
            'api.spiget.org/v2/resources*' => Http::response([]),
        ]);

        app(SpigotSource::class)->search($server, ProjectType::Plugin, 1, null, [
            'versions' => [CatalogFields::VERSION_ALL],
        ]);

        Http::assertSent(static function ($request): bool {
            return str_contains($request->url(), 'api.spiget.org/v2/resources')
                && !str_contains($request->url(), '/for/1.21.1');
        });
    }

    private function fakeServer(string $loader, string $minecraftVersion): Server
    {
        $varId = DB::table('egg_variables')->insertGetId([
            'egg_id' => 1,
            'env_variable' => 'MINECRAFT_VERSION',
            'default_value' => $minecraftVersion,
        ]);
        DB::table('server_variables')->insert([
            'server_id' => 1,
            'variable_id' => $varId,
            'variable_value' => $minecraftVersion,
        ]);

        $egg = (new Egg())->forceFill([
            'id' => 1,
            'name' => ucfirst($loader),
            'tags' => ['minecraft', $loader],
            'features' => [],
        ]);
        $egg->setRelation('variables', collect());
        $server = (new Server())->forceFill([
            'id' => 1,
            'egg_id' => 1,
        ]);
        $server->setRelation('egg', $egg);

        return $server;
    }
}
