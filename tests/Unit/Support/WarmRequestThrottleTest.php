<?php

namespace Kazaminosuke\ModManager\Tests\Unit\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository as LaravelCacheRepository;
use Illuminate\Config\Repository as LaravelConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use Kazaminosuke\ModManager\Support\WarmRequestThrottle;
use PHPUnit\Framework\TestCase;

class WarmRequestThrottleTest extends TestCase
{
    private ?Container $previousContainer = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_try_acquire_allows_up_to_the_configured_limit_then_blocks(): void
    {
        $throttle = $this->throttle(['modrinth' => 3]);

        self::assertTrue($throttle->tryAcquire('modrinth'));
        self::assertTrue($throttle->tryAcquire('modrinth'));
        self::assertTrue($throttle->tryAcquire('modrinth'));
        self::assertFalse($throttle->tryAcquire('modrinth'));
        self::assertFalse($throttle->tryAcquire('modrinth'));
    }

    public function test_try_acquire_allows_again_once_the_one_minute_window_has_passed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));
        $throttle = $this->throttle(['modrinth' => 1]);

        self::assertTrue($throttle->tryAcquire('modrinth'));
        self::assertFalse($throttle->tryAcquire('modrinth'));

        Carbon::setTestNow(Carbon::parse('2026-01-01 00:01:01'));

        self::assertTrue($throttle->tryAcquire('modrinth'));
    }

    public function test_each_source_has_its_own_independent_budget(): void
    {
        $throttle = $this->throttle(['modrinth' => 1, 'curseforge' => 1]);

        self::assertTrue($throttle->tryAcquire('modrinth'));
        self::assertFalse($throttle->tryAcquire('modrinth'));

        // A different source's budget is untouched by modrinth's being spent.
        self::assertTrue($throttle->tryAcquire('curseforge'));
    }

    public function test_an_unconfigured_source_is_never_throttled(): void
    {
        $throttle = $this->throttle([]);

        for ($i = 0; $i < 500; $i++) {
            self::assertTrue($throttle->tryAcquire('modrinth'));
        }
    }

    public function test_a_limit_of_zero_is_an_explicit_per_source_kill_switch(): void
    {
        $throttle = $this->throttle(['modrinth' => 0]);

        self::assertFalse($throttle->tryAcquire('modrinth'));
    }

    public function test_remaining_reflects_consumed_slots_without_consuming_one_itself(): void
    {
        $throttle = $this->throttle(['modrinth' => 2]);

        self::assertSame(2, $throttle->remaining('modrinth'));
        $throttle->tryAcquire('modrinth');
        self::assertSame(1, $throttle->remaining('modrinth'));
        self::assertSame(1, $throttle->remaining('modrinth'));
    }

    /** @param array<string, int> $limits */
    protected function throttle(array $limits): WarmRequestThrottle
    {
        $container = new Container();
        $container->instance('config', new LaravelConfigRepository([
            'pelican-mod-manager' => ['warm_rate_limit' => $limits],
        ]));
        Container::setInstance($container);

        $cache = new LaravelCacheRepository(new ArrayStore());

        return new WarmRequestThrottle(new RateLimiter($cache));
    }
}
