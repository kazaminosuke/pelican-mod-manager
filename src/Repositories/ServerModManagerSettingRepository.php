<?php

namespace Kazaminosuke\ModManager\Repositories;

use App\Models\Server;
use Kazaminosuke\ModManager\Models\ModManagerServerSetting;
use Kazaminosuke\ModManager\Support\NavigationSort;

/**
 * Request-local access to the optional per-server settings row.
 *
 * The repository is a singleton in the normal Panel request. Its memo is
 * deliberately cleared between queue jobs by ModManagerServiceProvider; it
 * must never become a cross-request source of settings.
 */
final class ServerModManagerSettingRepository
{
    /** @var array<int, ModManagerServerSetting|null> */
    private array $memo = [];

    public function forServer(Server|int $server): ?ModManagerServerSetting
    {
        $serverId = $this->serverId($server);

        if (!array_key_exists($serverId, $this->memo)) {
            $this->memo[$serverId] = ModManagerServerSetting::query()
                ->where('server_id', $serverId)
                ->first();
        }

        return $this->memo[$serverId];
    }

    /**
     * Prime the request-local memo for a bulk server pass with one query.
     *
     * @param iterable<Server> $servers
     */
    public function preload(iterable $servers): void
    {
        $serverIds = [];
        foreach ($servers as $server) {
            $serverIds[] = $this->serverId($server);
        }

        $serverIds = array_values(array_unique($serverIds));
        if ($serverIds === []) {
            return;
        }

        $settings = ModManagerServerSetting::query()
            ->whereIn('server_id', $serverIds)
            ->get()
            ->keyBy('server_id');

        foreach ($serverIds as $serverId) {
            $this->memo[$serverId] = $settings->get($serverId);
        }
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function save(Server|int $server, array $attributes): ModManagerServerSetting
    {
        $serverId = $this->serverId($server);
        $setting = $this->forServer($serverId) ?? new ModManagerServerSetting();
        $setting->server_id = $serverId;

        foreach (['mod_navigation_sort', 'plugin_navigation_sort', 'datapack_navigation_sort', 'resourcepack_navigation_sort'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $attributes[$field] = NavigationSort::nullable($attributes[$field]);
            }
        }

        $setting->fill($attributes);
        $setting->save();

        // Keep the request-local read coherent with the just-written row.
        return $this->memo[$serverId] = $setting;
    }

    /**
     * Clear all request-local rows, or only one server's row when supplied.
     */
    public function clear(Server|int|null $server = null): void
    {
        if ($server === null) {
            $this->memo = [];

            return;
        }

        unset($this->memo[$this->serverId($server)]);
    }

    private function serverId(Server|int $server): int
    {
        return $server instanceof Server ? (int) $server->getKey() : (int) $server;
    }
}
