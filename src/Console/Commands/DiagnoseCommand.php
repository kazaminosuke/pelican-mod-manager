<?php

namespace Kazaminosuke\ModManager\Console\Commands;

use Illuminate\Console\Command;
use Kazaminosuke\ModManager\Services\ModManagerDiagnostics;

final class DiagnoseCommand extends Command
{
    protected $signature = 'mod-manager:diagnose {server? : Optional server id to include egg classification}';

    protected $description = 'Run non-destructive Mod Manager provider and compatibility checks.';

    public function handle(ModManagerDiagnostics $diagnostics): int
    {
        $server = null;
        $serverId = $this->argument('server');
        if ($serverId !== null && $serverId !== '') {
            $server = \App\Models\Server::query()->with('egg.variables')->find($serverId);
            if ($server === null) {
                $this->error('Server not found.');

                return self::FAILURE;
            }
        }

        $checks = $diagnostics->run($server);
        $this->line((string) json_encode(['checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        foreach ($checks as $check) {
            if (($check['status'] ?? '') === 'fail') {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
