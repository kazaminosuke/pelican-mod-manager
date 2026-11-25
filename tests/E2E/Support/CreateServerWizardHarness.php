<?php

namespace Kazaminosuke\ModManager\Tests\E2E\Support;

use App\Filament\Admin\Resources\Servers\Pages\CreateServer;
use App\Models\Server;
use Closure;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Support\Facades\View;
use Kazaminosuke\ModManager\Filament\Admin\ModpackCreationStep;
use RuntimeException;

final class CreateServerWizardHarness extends CreateServer
{
    /** @var (Closure(array<string, mixed>): Server)|null */
    public static ?Closure $createServerCallback = null;

    protected ?bool $hasDatabaseTransactions = true;

    /** @return list<Step> */
    protected function getSteps(): array
    {
        return [
            ModpackCreationStep::step(),
            Step::make('E2E startup configuration')->schema([
                Hidden::make('node_id')->default(1),
                Hidden::make('memory')->default(0),
                Hidden::make('egg_id'),
                Hidden::make('startup'),
                Hidden::make('select_startup'),
                Hidden::make('image'),
                Hidden::make('select_image'),
                ToggleButtons::make('skip_scripts')->options([0 => 'Yes', 1 => 'Skip'])->default(0)->required(),
                ToggleButtons::make('start_on_completion')->options([1 => 'Yes', 0 => 'No'])->default(1)->required(),
                Hidden::make('server_variables')->default([]),
                Hidden::make('environment')->default([]),
            ]),
        ];
    }

    public function nextModpackStep(): void
    {
        $wizard = $this->form->getComponents()[0];
        if ($wizard instanceof Wizard) {
            $wizard->nextStep(0);
        }
    }

    /**
     * Covariant return type `Server` satisfies both `: Model` and `: Server` parent signatures.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Server
    {
        if (self::$createServerCallback === null) {
            throw new RuntimeException('CreateServerWizardHarness::$createServerCallback must be set before calling create().');
        }

        return (self::$createServerCallback)($data);
    }

    protected function authorizeAccess(): void {}

    public static function authorizeResourceAccess(): void {}

    protected function getRedirectUrl(): string
    {
        return '/';
    }

    protected function getCreatedNotification(): ?Notification
    {
        return null;
    }

    public function render(): ViewContract
    {
        return View::file(E2ETestCase::pluginRoot().'/tests/Fixtures/modpack-creation-wizard.blade.php');
    }
}
