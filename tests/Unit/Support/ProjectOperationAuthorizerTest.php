<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Models\User;
use Illuminate\Config\Repository as LaravelConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Kazaminosuke\ModManager\Enums\ProjectOperation;
use Kazaminosuke\ModManager\Models\ModManagerServerSetting;
use Kazaminosuke\ModManager\Support\ProjectOperationAuthorizer;
use Mockery;
use PHPUnit\Framework\TestCase;

class ProjectOperationAuthorizerTest extends TestCase
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

        Capsule::schema()->dropIfExists('mod_manager_server_settings');
        Capsule::schema()->create('mod_manager_server_settings', function ($table): void {
            $table->id();
            $table->unsignedInteger('server_id')->unique();
            $table->boolean('allow_user_egg_profile_edit')->nullable();
            $table->boolean('allow_user_project_install')->nullable();
            $table->boolean('allow_user_project_update')->nullable();
            $table->boolean('allow_user_project_delete')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_root_admin_can_manage_every_project_operation(): void
    {
        $user = $this->user(rootAdmin: true);

        foreach (ProjectOperation::cases() as $operation) {
            self::assertTrue($this->allows($user, $operation));
        }
    }

    public function test_explicit_role_permission_is_scoped_to_its_operation(): void
    {
        $user = $this->user(permissions: [
            'update minecraftModManager' => true,
        ]);

        self::assertTrue($this->allows($user, ProjectOperation::Update));
        self::assertFalse($this->allows($user, ProjectOperation::Install));
        self::assertFalse($this->allows($user, ProjectOperation::Delete));
    }

    public function test_enabled_general_user_operation_requires_matching_native_file_permissions(): void
    {
        $installUser = $this->user(permissions: [
            SubuserPermission::FileCreate->value => true,
        ]);
        self::assertTrue($this->allows($installUser, ProjectOperation::Install, [
            'allow_user_project_install' => true,
        ]));

        $incompleteUpdateUser = $this->user(permissions: [
            SubuserPermission::FileCreate->value => true,
        ]);
        self::assertFalse($this->allows($incompleteUpdateUser, ProjectOperation::Update, [
            'allow_user_project_update' => true,
        ]));

        $updateUser = $this->user(permissions: [
            SubuserPermission::FileCreate->value => true,
            SubuserPermission::FileDelete->value => true,
        ]);
        self::assertTrue($this->allows($updateUser, ProjectOperation::Update, [
            'allow_user_project_update' => true,
        ]));

        $deleteUser = $this->user(permissions: [
            SubuserPermission::FileDelete->value => true,
        ]);
        self::assertTrue($this->allows($deleteUser, ProjectOperation::Delete, [
            'allow_user_project_delete' => true,
        ]));
    }

    public function test_scan_requires_file_read_content_and_create_without_operation_toggles(): void
    {
        $complete = $this->user(permissions: [
            SubuserPermission::FileRead->value => true,
            SubuserPermission::FileReadContent->value => true,
            SubuserPermission::FileCreate->value => true,
        ]);
        self::assertTrue($this->allows($complete, ProjectOperation::Scan));

        $missingRead = $this->user(permissions: [
            SubuserPermission::FileReadContent->value => true,
            SubuserPermission::FileCreate->value => true,
        ]);
        self::assertFalse($this->allows($missingRead, ProjectOperation::Scan));

        $roleUser = $this->user(permissions: [
            'create minecraftModManager' => true,
        ]);
        self::assertTrue($this->allows($roleUser, ProjectOperation::Scan));
    }

    public function test_general_user_file_permissions_do_not_bypass_a_disabled_operation(): void
    {
        $user = $this->user(permissions: [
            SubuserPermission::FileCreate->value => true,
            SubuserPermission::FileDelete->value => true,
        ]);

        foreach (ProjectOperation::cases() as $operation) {
            self::assertFalse($this->allows($user, $operation));
        }
    }

    public function test_role_permission_still_precedes_a_server_deny(): void
    {
        $user = $this->user(permissions: [
            'update minecraftModManager' => true,
        ]);
        ModManagerServerSetting::query()->create([
            'server_id' => 1,
            'allow_user_project_update' => false,
        ]);

        self::assertTrue($this->allows($user, ProjectOperation::Update));
    }

    public function test_server_false_override_does_not_fall_back_to_global_true(): void
    {
        $user = $this->user(permissions: [
            SubuserPermission::FileCreate->value => true,
            SubuserPermission::FileDelete->value => true,
        ]);
        ModManagerServerSetting::query()->create([
            'server_id' => 1,
            'allow_user_project_update' => false,
        ]);

        self::assertFalse($this->allows($user, ProjectOperation::Update, [
            'allow_user_project_update' => true,
        ]));
    }

    public function test_server_true_override_allows_file_permission_fallback_when_global_is_false(): void
    {
        $user = $this->user(permissions: [
            SubuserPermission::FileCreate->value => true,
        ]);
        ModManagerServerSetting::query()->create([
            'server_id' => 1,
            'allow_user_project_install' => true,
        ]);

        self::assertTrue($this->allows($user, ProjectOperation::Install));
    }

    /**
     * @param array<string, bool> $config
     */
    private function allows(User $user, ProjectOperation $operation, array $config = []): bool
    {
        $previousContainer = Container::getInstance();
        $container = new Container();
        $container->instance('config', new LaravelConfigRepository([
            'pelican-minecraft-modrinth' => $config,
        ]));
        Container::setInstance($container);

        try {
            $server = new Server();
            $server->id = 1;

            return (new ProjectOperationAuthorizer())->allows($user, $server, $operation);
        } finally {
            Container::setInstance($previousContainer);
        }
    }

    /** @param array<string, bool> $permissions */
    private function user(bool $rootAdmin = false, array $permissions = []): User
    {
        return new class($rootAdmin, $permissions) extends User {
            /** @param array<string, bool> $permissions */
            public function __construct(
                private readonly bool $rootAdmin,
                private readonly array $permissions,
            ) {}

            public function isRootAdmin(): bool
            {
                return $this->rootAdmin;
            }

            public function can($abilities, mixed $arguments = []): bool
            {
                $key = $abilities instanceof \BackedEnum ? $abilities->value : (string) $abilities;

                return $this->permissions[$key] ?? false;
            }
        };
    }
}
