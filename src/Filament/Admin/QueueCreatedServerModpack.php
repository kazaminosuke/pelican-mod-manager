<?php

namespace Kazaminosuke\ModManager\Filament\Admin;

use App\Filament\Admin\Resources\Servers\Pages\CreateServer;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Model;
use Kazaminosuke\ModManager\Enums\ProjectType;
use Kazaminosuke\ModManager\Filament\Server\Pages\ModManagerPage;
use Kazaminosuke\ModManager\Services\InstalledOperationManager;
use Kazaminosuke\ModManager\Support\Compatibility\ProvisioningMode;
use Throwable;

final class QueueCreatedServerModpack
{
    public function __construct(private readonly InstalledOperationManager $operations) {}

    /** @param array<string, mixed> $data */
    public function __invoke(Model $record, array $data, Page $page): void
    {
        $plan = $data['mm_install_plan'] ?? null;
        if (!$page instanceof CreateServer || !$record instanceof Server
            || !is_array($plan) || ($plan['mode'] ?? null) !== ProvisioningMode::Archive->value) {
            return;
        }

        $queue = function () use ($record, $plan): void {
            try {
                $outcome = $this->operations->dispatchModpackInstall(
                    $record,
                    ProjectType::Mod,
                    $plan['source'],
                    $plan['project_id'],
                    $plan['version_id'],
                    1200,
                    $plan['selection'],
                );
                if ($outcome['dispatched'] || ($outcome['reason'] === 'already_active' && $outcome['state']?->isActive())) {
                    Notification::make()
                        ->title(trans($outcome['dispatched']
                            ? 'pelican-mod-manager::strings.modpacks.provision_queued'
                            : 'pelican-mod-manager::strings.operations.already_active'))
                        ->info()
                        ->actions([
                            Action::make('modpack_progress')
                                ->label(trans('pelican-mod-manager::strings.modpacks.open_progress'))
                                ->url(ModManagerPage::getUrl(panel: 'server', tenant: $record)),
                        ])
                        ->persistent()
                        ->send();

                    return;
                }
            } catch (Throwable $exception) {
                report($exception);
            }

            Notification::make()
                ->title(trans('pelican-mod-manager::strings.modpacks.archive_queue_failed'))
                ->danger()
                ->send();
        };

        // Filament can wrap creation in a transaction. Queue only after the
        // created server and its startup variables are committed.
        $connection = $record->getConnection();
        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit($queue);
        } else {
            $queue();
        }
    }
}
