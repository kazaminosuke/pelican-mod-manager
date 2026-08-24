<?php

namespace Kazaminosuke\ModManager\Http\Middleware;

use App\Models\Server;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Kazaminosuke\ModManager\Filament\Server\Pages\MinecraftDatapackPage;
use Kazaminosuke\ModManager\Filament\Server\Pages\MinecraftResourcePackPage;
use Kazaminosuke\ModManager\Filament\Server\Pages\ModManagerPage;
use Kazaminosuke\ModManager\Support\NavigationRowShifter;
use Throwable;

/**
 * Pushes CORE sidebar entries down so the Mod Manager pages' configured
 * display-order values land on their literal rows.
 *
 * The admin tab presents "display order" as an absolute position (10 =
 * tenth entry from the top). Pelican's stock server panel already occupies
 * those numbers - Startup at 10, Settings and Webhooks at 11 - so a manager
 * page claiming 10 would otherwise share Startup's slot. This middleware,
 * running before anything renders the navigation, rewrites the core pages'
 * and resources' static sort values item by item. An item that lands on a
 * claim or an earlier moved item keeps cascading until it reaches a free row,
 * as computed by NavigationRowShifter, and shifts
 * panel-registered items the same way. The manager pages themselves keep
 * their configured value untouched.
 *
 * Runs once per request: static properties are per-request in FPM, but a
 * second invocation would read the already-shifted values as originals and
 * double-shift, hence the $applied guard (reset() exists for tests).
 */
class ShiftCoreNavigationRows
{
    protected static bool $applied = false;

    public function handle(Request $request, Closure $next): mixed
    {
        if (!static::$applied) {
            static::$applied = true;

            try {
                $this->shift();
            } catch (Throwable) {
                // An unreadable tenant or settings store must never take the
                // whole server panel down; the sidebar simply falls back to
                // Filament's stock relative ordering.
            }
        }

        return $next($request);
    }

    public static function reset(): void
    {
        static::$applied = false;
    }

    protected function shift(): void
    {
        /** @var Server|null $server */
        $server = Filament::getTenant();

        if (!$server instanceof Server) {
            return;
        }

        $managerPages = [ModManagerPage::class, MinecraftDatapackPage::class, MinecraftResourcePackPage::class];

        // Claim only for pages that will actually register: canAccess()
        // mirrors Filament's own registration gate (enabled switches, egg
        // detection, permissions), so a hidden page never displaces a core
        // row it would not render on.
        $claims = [];

        foreach ($managerPages as $page) {
            if (!$page::canAccess()) {
                continue;
            }

            $sort = $page::getNavigationSort();

            if ($sort !== null) {
                $claims[] = $sort;
            }
        }

        $panel = Filament::getCurrentOrDefaultPanel();

        $coreSorts = $this->coreSorts($panel, $managerPages);

        $finalRows = NavigationRowShifter::finalRowsFor($claims, $coreSorts);

        if ($finalRows === $coreSorts) {
            return;
        }

        $this->apply($panel, $managerPages, $finalRows);
    }

    /**
     * @param  list<class-string> $managerPages
     * @return list<int>
     */
    protected function coreSorts(mixed $panel, array $managerPages): array
    {
        $sorts = [];

        foreach ($panel->getPages() as $page) {
            if (in_array($page, $managerPages, true)) {
                continue;
            }

            $sort = $page::getNavigationSort();

            if ($sort !== null) {
                $sorts[] = $sort;
            }
        }

        foreach ($panel->getResources() as $resource) {
            $sort = $resource::getNavigationSort();

            if ($sort !== null) {
                $sorts[] = $sort;
            }
        }

        foreach ($panel->getNavigationItems() as $item) {
            if (in_array($item->getKey(), $managerPages, true)) {
                continue;
            }

            $sorts[] = $item->getSort();
        }

        return $sorts;
    }

    /**
     * @param  list<class-string>                    $managerPages
     * @param  list<int>                              $finalRows
     */
    protected function apply(mixed $panel, array $managerPages, array $finalRows): void
    {
        $index = 0;

        foreach ($panel->getPages() as $page) {
            if (in_array($page, $managerPages, true)) {
                continue;
            }

            $sort = $page::getNavigationSort();

            if ($sort !== null) {
                $finalSort = $finalRows[$index++];

                if ($finalSort !== $sort) {
                    $page::navigationSort($finalSort);
                }
            }
        }

        foreach ($panel->getResources() as $resource) {
            $sort = $resource::getNavigationSort();

            if ($sort !== null) {
                $finalSort = $finalRows[$index++];

                if ($finalSort !== $sort) {
                    $resource::navigationSort($finalSort);
                }
            }
        }

        foreach ($panel->getNavigationItems() as $item) {
            $sort = $item->getSort();
            $finalSort = $finalRows[$index++];

            if ($finalSort !== $sort) {
                $item->sort($finalSort);
            }
        }
    }
}
