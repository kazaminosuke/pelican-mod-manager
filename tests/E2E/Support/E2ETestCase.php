<?php

namespace Kazaminosuke\ModManager\Tests\E2E\Support;

use App\Models\Backup;
use App\Models\Egg;
use App\Models\Node;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use BladeUI\Icons\Factory as BladeIconsFactory;
use Closure;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\View\TablesRenderHook;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Kazaminosuke\ModManager\Filament\Components\ModpackCatalogTable;
use Kazaminosuke\ModManager\Filament\Server\Pages\MinecraftDatapackPage;
use Kazaminosuke\ModManager\Filament\Server\Pages\MinecraftResourcePackPage;
use Kazaminosuke\ModManager\Filament\Server\Pages\ModManagerPage;
use Kazaminosuke\ModManager\Providers\ModManagerServiceProvider;
use Kazaminosuke\ModManager\Support\EggProfileRegistry;
use Mockery;
use RuntimeException;

/**
 * Shared Laravel / SQLite / Pelican Panel / Filament E2E base test case.
 *
 * Mounts StrictWingsFake at the HTTP transport layer first in Http::fake()
 * alongside Http::preventStrayRequests(), builds all 20 Pelican core & Spatie RBAC tables
 * and runs the 5 schema plugin migrations in SQLite :memory:, and isolates storage_path().
 */
abstract class E2ETestCase extends TestCase
{
    protected StrictWingsFake $wings;

    protected string $tempStoragePath = '';

    /** @var list<string> */
    private array $trackedTempPaths = [];

    /** @var array<string, array{bytes: string, headers: array<string, string>}> */
    private array $remoteFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Model::preventLazyLoading(false);

        $this->tempStoragePath = sys_get_temp_dir().'/pmm-e2e-storage-'.bin2hex(random_bytes(8));
        foreach ([
            $this->tempStoragePath.'/framework/views',
            $this->tempStoragePath.'/framework/cache/data',
            $this->tempStoragePath.'/framework/sessions',
            $this->tempStoragePath.'/logs',
            $this->tempStoragePath.'/app/private',
        ] as $dir) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new RuntimeException("Unable to create isolated storage dir [{$dir}].");
            }
        }
        $this->app->useStoragePath($this->tempStoragePath);

        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'app.env' => 'testing',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true,
            'cache.default' => 'array',
            'session.driver' => 'array',
            'queue.default' => 'sync',
            'view.compiled' => $this->tempStoragePath.'/framework/views',
            'panel.guzzle.timeout' => 15,
            'panel.guzzle.connect_timeout' => 5,
            'pelican-mod-manager.curseforge_api_key' => 'e2e-test-curseforge-key',
            'pelican-mod-manager.modrinth_token' => '',
            'pelican-mod-manager.hangar_api_key' => '',
            'pelican-mod-manager.github_token' => '',
            'pelican-mod-manager.latest_minecraft_version' => '26.1.2',
        ]);

        DB::purge('sqlite');

        $this->app->register(ModManagerServiceProvider::class);
        View::addNamespace('pelican-mod-manager', self::pluginRoot().'/resources/views');
        $this->app['translator']->addNamespace('pelican-mod-manager', self::pluginRoot().'/lang');

        $this->registerPluginRenderHooks();
        $this->createPelicanCoreTables();
        $this->runPluginMigrations();

        $profilesPath = self::pluginRoot().'/resources/egg-profiles.json';
        if (is_file($profilesPath)) {
            $decoded = json_decode((string) file_get_contents($profilesPath), true);
            if (is_array($decoded) && is_array($decoded['profiles'] ?? null)) {
                EggProfileRegistry::seed($decoded['profiles']);
            }
        }

        $this->remoteFiles = [];
        $this->wings = new StrictWingsFake();

        Http::fake($this->wings->httpHandler());
        Http::fake(function (Request $request): ?PromiseInterface {
            $url = $request->url();
            if (!array_key_exists($url, $this->remoteFiles)) {
                return null;
            }
            $entry = $this->remoteFiles[$url];

            return Http::response($entry['bytes'], 200, $entry['headers']);
        });
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        EggProfileRegistry::clear();

        if (isset($this->wings)) {
            $this->wings->cleanup();
        }

        foreach ($this->trackedTempPaths as $path) {
            $this->removePathRecursively($path);
        }
        $this->trackedTempPaths = [];

        if ($this->tempStoragePath !== '' && is_dir($this->tempStoragePath)) {
            $this->removePathRecursively($this->tempStoragePath);
        }

        Mockery::close();
        URL::defaults([]);

        parent::tearDown();
    }

    public static function pluginRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    protected function trackTempPath(string $path): string
    {
        $this->trackedTempPaths[] = $path;

        return $path;
    }

    /**
     * Registers a remote file in BOTH StrictWingsFake (Wings /files/pull) AND
     * Laravel Http::fake() (Panel-side download) so tests remain 100% opaque-box
     * across both Wings-pull and Panel-bundle architectures.
     *
     * @param  array<string, string>  $headers
     */
    protected function registerRemoteFile(string $url, string $bytes, array $headers = []): void
    {
        $mergedHeaders = array_merge([
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) strlen($bytes),
        ], $headers);

        $this->remoteFiles[$url] = [
            'bytes' => $bytes,
            'headers' => $mergedHeaders,
        ];
        $this->wings->registerPullFixture($url, $bytes);
    }

    protected function unregisterRemoteFile(string $url): void
    {
        unset($this->remoteFiles[$url]);
        $this->wings->unregisterPullFixture($url);
    }

    /**
     * Appends upstream provider HTTP fakes after StrictWingsFake while keeping
     * Http::preventStrayRequests() active.
     *
     * @param  array<string, mixed>|Closure  $stubs
     */
    protected function fakeUpstreamHttp(array|Closure $stubs): void
    {
        Http::fake($stubs);
        Http::preventStrayRequests();
    }

    protected function useAdminPanel(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant(null, true);
        URL::defaults([]);
    }

    protected function useServerPanel(Server $server): void
    {
        $panel = Filament::getPanel('server');
        $panel->pages([
            ModManagerPage::class,
            MinecraftDatapackPage::class,
            MinecraftResourcePackPage::class,
        ]);
        Filament::setCurrentPanel($panel);
        Filament::setTenant($server, true);
        URL::defaults(['tenant' => (string) $server->getRouteKey()]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createNode(array $overrides = []): Node
    {
        $now = now()->toDateTimeString();
        $id = DB::table('nodes')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'public' => true,
            'name' => 'E2E Strict Wings Node',
            'description' => 'Isolated E2E test node',
            'fqdn' => $this->wings->host(),
            'scheme' => $this->wings->scheme(),
            'behind_proxy' => false,
            'maintenance_mode' => false,
            'memory' => 16384,
            'memory_overallocate' => 0,
            'disk' => 102400,
            'disk_overallocate' => 0,
            'upload_size' => 100,
            'daemon_token_id' => $this->wings->daemonTokenId(),
            'daemon_token' => Crypt::encryptString($this->wings->daemonToken()),
            'daemon_listen' => $this->wings->port(),
            'daemon_connect' => $this->wings->port(),
            'daemon_sftp' => 2022,
            'daemon_base' => '/var/lib/pelican/volumes',
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));

        /** @var Node $node */
        $node = Node::query()->findOrFail($id);

        return $node;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createUser(bool $rootAdmin = true, array $overrides = []): User
    {
        $now = now()->toDateTimeString();
        $suffix = bin2hex(random_bytes(4));
        $id = DB::table('users')->insertGetId(array_merge([
            'uuid' => (string) Str::uuid(),
            'username' => 'e2e_user_'.$suffix,
            'email' => "e2e_{$suffix}@example.test",
            'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
            'root_admin' => $rootAdmin,
            'timezone' => 'UTC',
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));

        /** @var User $user */
        $user = User::query()->findOrFail($id);

        if ($rootAdmin) {
            $role = Role::query()->firstOrCreate([
                'name' => Role::ROOT_ADMIN,
                'guard_name' => Role::DEFAULT_GUARD_NAME,
            ]);
            $user->assignRole($role);
            $user->load('roles');
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $variables
     */
    protected function createEgg(array $attributes, array $variables = []): Egg
    {
        $now = now()->toDateTimeString();
        $startupCommands = $attributes['startup_commands'] ?? ['Default' => $attributes['startup'] ?? 'java -jar server.jar'];
        $defaultStartup = is_array($startupCommands)
            ? (string) (reset($startupCommands) ?: 'java -jar server.jar')
            : (string) ($attributes['startup'] ?? 'java -jar server.jar');

        $row = [
            'uuid' => (string) ($attributes['uuid'] ?? Str::uuid()),
            'name' => (string) ($attributes['name'] ?? 'E2E Egg'),
            'author' => (string) ($attributes['author'] ?? 'e2e@pelican.dev'),
            'description' => $attributes['description'] ?? null,
            'update_url' => $attributes['update_url'] ?? null,
            'tags' => json_encode($attributes['tags'] ?? [], JSON_THROW_ON_ERROR),
            'features' => json_encode($attributes['features'] ?? [], JSON_THROW_ON_ERROR),
            'docker_images' => json_encode($attributes['docker_images'] ?? ['Java 21' => 'ghcr.io/pelican-eggs/yolks:java_21'], JSON_THROW_ON_ERROR),
            'startup_commands' => json_encode($startupCommands, JSON_THROW_ON_ERROR),
            'startup' => $defaultStartup,
            'file_denylist' => json_encode($attributes['file_denylist'] ?? [], JSON_THROW_ON_ERROR),
            'config_files' => json_encode($attributes['config_files'] ?? [], JSON_THROW_ON_ERROR),
            'config_startup' => json_encode($attributes['config_startup'] ?? [], JSON_THROW_ON_ERROR),
            'config_logs' => json_encode($attributes['config_logs'] ?? [], JSON_THROW_ON_ERROR),
            'config_stop' => (string) ($attributes['config_stop'] ?? 'stop'),
            'script_install' => (string) ($attributes['script_install'] ?? '#!/bin/bash'),
            'script_container' => (string) ($attributes['script_container'] ?? 'ghcr.io/pelican-eggs/installers:alpine'),
            'script_entry' => (string) ($attributes['script_entry'] ?? 'bash'),
            'script_is_privileged' => (bool) ($attributes['script_is_privileged'] ?? true),
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if (isset($attributes['id'])) {
            $row['id'] = (int) $attributes['id'];
            DB::table('eggs')->insert($row);
            $eggId = (int) $attributes['id'];
        } else {
            $eggId = DB::table('eggs')->insertGetId($row);
        }

        foreach ($variables as $index => $var) {
            $rules = $var['rules'] ?? ['nullable', 'string'];
            if (is_string($rules)) {
                $rules = array_values(array_filter(explode('|', $rules), static fn (string $r): bool => $r !== ''));
            }

            DB::table('egg_variables')->insert([
                'egg_id' => $eggId,
                'sort' => (int) ($var['sort'] ?? $index),
                'name' => (string) ($var['name'] ?? $var['env_variable']),
                'description' => (string) ($var['description'] ?? ''),
                'env_variable' => (string) $var['env_variable'],
                'default_value' => (string) ($var['default_value'] ?? ''),
                'user_viewable' => (bool) ($var['user_viewable'] ?? true),
                'user_editable' => (bool) ($var['user_editable'] ?? true),
                'rules' => json_encode($rules, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        /** @var Egg $egg */
        $egg = Egg::query()->with('variables')->findOrFail($eggId);

        return $egg;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, string>  $variableValues
     */
    protected function createServer(
        Egg $egg,
        ?Node $node = null,
        array $overrides = [],
        array $variableValues = [],
        ?User $owner = null,
    ): Server {
        $node ??= $this->createNode();
        $owner ??= $this->createUser();
        $egg->loadMissing('variables');

        $now = now()->toDateTimeString();
        $uuid = (string) ($overrides['uuid'] ?? Str::uuid());
        $uuidShort = (string) ($overrides['uuidShort'] ?? substr(str_replace('-', '', $uuid), 0, 8));

        $allocationId = DB::table('allocations')->insertGetId([
            'node_id' => $node->getKey(),
            'ip' => '192.0.2.10',
            'ip_alias' => null,
            'port' => 25565 + random_int(0, 1000),
            'notes' => null,
            'server_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $dockerImages = is_array($egg->docker_images) ? $egg->docker_images : [];
        $defaultImage = (string) (reset($dockerImages) ?: 'ghcr.io/pelican-eggs/yolks:java_21');
        $startupCommands = is_array($egg->startup_commands) ? $egg->startup_commands : [];
        $defaultStartup = (string) (reset($startupCommands) ?: ($egg->startup ?? 'java -jar server.jar'));

        $serverId = DB::table('servers')->insertGetId(array_merge([
            'external_id' => null,
            'uuid' => $uuid,
            'uuidShort' => $uuidShort,
            'node_id' => $node->getKey(),
            'name' => 'E2E Server '.$uuidShort,
            'description' => 'E2E test server',
            'status' => null,
            'skip_scripts' => false,
            'owner_id' => $owner->getKey(),
            'memory' => 4096,
            'swap' => 0,
            'disk' => 20480,
            'io' => 500,
            'cpu' => 200,
            'threads' => null,
            'oom_killer' => false,
            'allocation_id' => $allocationId,
            'egg_id' => $egg->getKey(),
            'startup' => $defaultStartup,
            'image' => $defaultImage,
            'allocation_limit' => 1,
            'database_limit' => 0,
            'backup_limit' => 5,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));

        DB::table('allocations')->where('id', $allocationId)->update(['server_id' => $serverId]);

        foreach ($egg->variables as $variable) {
            $envName = (string) $variable->env_variable;
            $val = array_key_exists($envName, $variableValues)
                ? $variableValues[$envName]
                : (string) ($variable->default_value ?? '');

            DB::table('server_variables')->insert([
                'server_id' => $serverId,
                'variable_id' => $variable->getKey(),
                'variable_value' => $val,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        /** @var Server $server */
        $server = Server::query()
            ->with(['node', 'egg.variables', 'serverVariables.variable', 'allocation'])
            ->findOrFail($serverId);

        $this->wings->serverRootPath($server->uuid);

        return $server;
    }

    /**
     * @param  list<string>  $ignoredFiles
     */
    protected function createFullBackup(
        Server $server,
        bool $successful = true,
        bool $locked = false,
        array $ignoredFiles = ['# Full modpack update backup'],
    ): Backup {
        $now = now()->toDateTimeString();
        $id = DB::table('backups')->insertGetId([
            'server_id' => $server->getKey(),
            'uuid' => (string) Str::uuid(),
            'upload_id' => null,
            'is_successful' => $successful,
            'is_locked' => $locked,
            'name' => 'Modpack E2E Backup',
            'ignored_files' => json_encode($ignoredFiles, JSON_THROW_ON_ERROR),
            'disk' => 'wings',
            'checksum' => 'sha1:'.sha1('backup-payload'),
            'bytes' => 1024,
            'completed_at' => $successful ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /** @var Backup $backup */
        $backup = Backup::query()->findOrFail($id);

        return $backup;
    }

    protected function seedServerFile(Server $server, string $path, string $contents, ?int $mtime = null, int $mode = 0644): void
    {
        $this->wings->seedFile($server->uuid, $path, $contents, $mtime, $mode);
    }

    protected function serverFile(Server $server, string $path): string
    {
        return $this->wings->readFile($server->uuid, $path);
    }

    protected function hasServerFile(Server $server, string $path): bool
    {
        return $this->wings->hasFile($server->uuid, $path);
    }

    private function registerPluginRenderHooks(): void
    {
        $this->app->make(BladeIconsFactory::class)->add('mcloader', [
            'path' => self::pluginRoot().'/resources/icons/loaders',
            'prefix' => 'mcloader',
        ]);

        $renderPluginView = static function (string $name): ViewContract {
            if (!View::exists($name)) {
                throw new RuntimeException("Missing plugin view [{$name}].");
            }

            return View::make($name);
        };

        $pageClasses = [
            ModManagerPage::class,
            MinecraftDatapackPage::class,
            MinecraftResourcePackPage::class,
        ];

        FilamentView::registerRenderHook(
            TablesRenderHook::TOOLBAR_SEARCH_AFTER,
            static fn (): ViewContract => $renderPluginView('pelican-mod-manager::components.catalog-sort-select'),
            $pageClasses,
        );

        FilamentView::registerRenderHook(
            TablesRenderHook::TOOLBAR_SEARCH_AFTER,
            static fn (): ViewContract => $renderPluginView('pelican-mod-manager::components.modpack-provider-select'),
            [ModpackCatalogTable::class],
        );

        FilamentView::registerRenderHook(
            TablesRenderHook::TOOLBAR_COLUMN_MANAGER_TRIGGER_BEFORE,
            static fn (): ViewContract => $renderPluginView('pelican-mod-manager::components.catalog-view-toggle'),
            [ModpackCatalogTable::class, ...$pageClasses],
        );
    }

    private function createPelicanCoreTables(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('uuid')->unique();
            $table->string('username')->unique();
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('root_admin')->default(false);
            $table->string('timezone')->nullable();
            $table->string('language', 10)->default('en');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('nodes', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('uuid')->unique();
            $table->boolean('public')->default(true);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('fqdn');
            $table->string('scheme')->default('https');
            $table->boolean('behind_proxy')->default(false);
            $table->boolean('maintenance_mode')->default(false);
            $table->unsignedInteger('memory')->default(8192);
            $table->integer('memory_overallocate')->default(0);
            $table->unsignedInteger('disk')->default(51200);
            $table->integer('disk_overallocate')->default(0);
            $table->unsignedInteger('upload_size')->default(100);
            $table->string('daemon_token_id', 32);
            $table->text('daemon_token');
            $table->unsignedSmallInteger('daemon_listen')->default(8080);
            $table->unsignedSmallInteger('daemon_connect')->default(8080);
            $table->unsignedSmallInteger('daemon_sftp')->default(2022);
            $table->string('daemon_base')->default('/var/lib/pelican/volumes');
            $table->timestamps();
        });

        Schema::create('eggs', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('uuid')->unique();
            $table->string('name');
            $table->string('author')->default('e2e@pelican.dev');
            $table->text('description')->nullable();
            $table->string('update_url')->nullable();
            $table->json('tags')->nullable();
            $table->json('features')->nullable();
            $table->json('docker_images')->nullable();
            $table->json('startup_commands')->nullable();
            $table->text('startup')->nullable();
            $table->json('file_denylist')->nullable();
            $table->json('config_files')->nullable();
            $table->json('config_startup')->nullable();
            $table->json('config_logs')->nullable();
            $table->string('config_stop')->nullable();
            $table->text('script_install')->nullable();
            $table->string('script_container')->nullable();
            $table->string('script_entry')->nullable();
            $table->boolean('script_is_privileged')->default(true);
            $table->timestamps();
        });

        Schema::create('egg_variables', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('egg_id');
            $table->foreign('egg_id')->references('id')->on('eggs')->cascadeOnDelete();
            $table->unsignedInteger('sort')->default(0);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('env_variable');
            $table->text('default_value')->nullable();
            $table->boolean('user_viewable')->default(true);
            $table->boolean('user_editable')->default(true);
            $table->json('rules')->nullable();
            $table->timestamps();
        });

        Schema::create('allocations', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('node_id');
            $table->string('ip');
            $table->string('ip_alias')->nullable();
            $table->unsignedMediumInteger('port');
            $table->unsignedInteger('server_id')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('servers', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('external_id')->nullable();
            $table->string('uuid')->unique();
            $table->string('uuidShort', 16)->unique();
            $table->unsignedInteger('node_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->nullable();
            $table->boolean('skip_scripts')->default(false);
            $table->unsignedInteger('owner_id');
            $table->unsignedInteger('memory')->default(2048);
            $table->integer('swap')->default(0);
            $table->unsignedInteger('disk')->default(10240);
            $table->unsignedInteger('io')->default(500);
            $table->unsignedInteger('cpu')->default(100);
            $table->string('threads')->nullable();
            $table->boolean('oom_killer')->default(false);
            $table->unsignedInteger('allocation_id')->nullable();
            $table->unsignedInteger('egg_id');
            $table->text('startup')->nullable();
            $table->string('image')->nullable();
            $table->unsignedInteger('allocation_limit')->nullable();
            $table->unsignedInteger('database_limit')->nullable();
            $table->unsignedInteger('backup_limit')->default(5);
            $table->timestamps();
        });

        Schema::create('server_variables', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('server_id');
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->unsignedInteger('variable_id');
            $table->foreign('variable_id')->references('id')->on('egg_variables')->cascadeOnDelete();
            $table->text('variable_value')->nullable();
            $table->timestamps();
        });

        Schema::create('subusers', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('server_id');
            $table->json('permissions')->nullable();
            $table->timestamps();
        });

        Schema::create('mounts', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('uuid')->unique();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->string('source');
            $table->string('target');
            $table->boolean('read_only')->default(true);
            $table->boolean('user_mountable')->default(false);
            $table->timestamps();
        });

        Schema::create('mount_server', function (Blueprint $table): void {
            $table->unsignedInteger('mount_id');
            $table->unsignedInteger('server_id');
        });

        Schema::create('egg_mount', function (Blueprint $table): void {
            $table->unsignedInteger('egg_id');
            $table->unsignedInteger('mount_id');
        });

        Schema::create('backups', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('server_id');
            $table->string('uuid')->unique();
            $table->string('upload_id')->nullable();
            $table->boolean('is_successful')->default(false);
            $table->boolean('is_locked')->default(false);
            $table->string('name');
            $table->text('ignored_files')->nullable();
            $table->string('disk')->default('wings');
            $table->string('checksum')->nullable();
            $table->unsignedBigInteger('bytes')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('batch')->nullable();
            $table->string('event');
            $table->string('ip')->nullable();
            $table->text('description')->nullable();
            $table->string('actor_type')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedInteger('api_key_id')->nullable();
            $table->json('properties')->nullable();
            $table->timestamp('timestamp')->nullable();
        });

        Schema::create('activity_log_subjects', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('activity_log_id');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
        });

        Schema::create('webhook_configurations', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->string('type')->default('generic');
            $table->string('scope')->default('global');
            $table->unsignedInteger('server_id')->nullable();
            $table->string('url')->nullable();
            $table->json('events')->nullable();
            $table->json('headers')->nullable();
            $table->text('payload')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('model_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['permission_id', 'model_id', 'model_type'], 'model_has_permissions_primary');
        });

        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['role_id', 'model_id', 'model_type'], 'model_has_roles_primary');
        });

        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['permission_id', 'role_id'], 'role_has_permissions_primary');
        });
    }

    private function runPluginMigrations(): void
    {
        $migrationFiles = glob(self::pluginRoot().'/database/migrations/*.php');
        if (!is_array($migrationFiles)) {
            return;
        }
        sort($migrationFiles);
        foreach ($migrationFiles as $file) {
            if (str_contains(basename($file), 'signal_queue_restart')) {
                continue;
            }
            $migration = require $file;
            if (is_object($migration) && method_exists($migration, 'up')) {
                $migration->up();
            }
        }
    }

    private function removePathRecursively(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $entries = @scandir($path);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $this->removePathRecursively($path.'/'.$entry);
            }
        }
        @rmdir($path);
    }
}
