<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Filament;

use App\Models\User;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\Guard;
use Kazaminosuke\ModManager\Filament\Admin\ServerModManagerTab;
use Mockery;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ServerModManagerTabTest extends TestCase
{
    private ?Container $previousContainer = null;

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        Mockery::close();

        parent::tearDown();
    }

    public function test_hidden_root_settings_save_is_a_no_op_for_non_root_admins(): void
    {
        $this->previousContainer = Container::getInstance();
        $user = Mockery::mock(User::class);
        $user->shouldReceive('isRootAdmin')->once()->andReturnFalse();
        $guard = Mockery::mock(Guard::class);
        $guard->shouldReceive('user')->once()->andReturn($user);
        $auth = Mockery::mock(AuthFactory::class);
        $auth->shouldReceive('guard')->once()->with('web')->andReturn($guard);
        $container = new Container();
        $container->instance('config', new ConfigRepository([
            'auth' => ['defaults' => ['guard' => 'web']],
        ]));
        $container->instance(AuthFactory::class, $auth);
        Container::setInstance($container);

        $method = new ReflectionMethod(ServerModManagerTab::class, 'saveFromLivewire');
        $method->invoke(null, Tab::make('Hidden root settings'));

        self::assertTrue(true);
    }
}
