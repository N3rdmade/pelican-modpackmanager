<?php

namespace Cosmii02\ModpackManager;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Section;
use Filament\Notifications\Notification;
use Filament\Panel;

class ModpackManagerPlugin implements Plugin, HasPluginSettings
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'modpack-manager';
    }

    public function register(Panel $panel): void
    {
        $id  = str($panel->getId())->title();
        $dir = plugin_path($this->getId(), "src/Filament/{$id}/Pages");

        // Only the Server panel ships pages; guard against missing directories
        // so page discovery doesn't throw on panels without any pages.
        if (is_dir($dir)) {
            $panel->discoverPages(
                $dir,
                "Cosmii02\\ModpackManager\\Filament\\{$id}\\Pages"
            );
        }
    }

    public function boot(Panel $panel): void {}

    public static function make(): static
    {
        return app(static::class);
    }

    // ─── HasPluginSettings ────────────────────────────────────────────────────

    public function getSettingsForm(): array
    {
        return [
            Section::make('CurseForge')
                ->description('Required for browsing and installing modpacks from CurseForge.')
                ->schema([
                    TextInput::make('curseforge_api_key')
                        ->label('API Key')
                        ->password()
                        ->revealable()
                        ->placeholder('Enter your CurseForge API key')
                        ->helperText('Get your key at https://console.curseforge.com')
                        ->default(fn () => config('modpack-manager.curseforge_api_key')),
                ]),

            Section::make('Modrinth')
                ->description('Optional – only needed for private/authenticated Modrinth access. Public browsing works without a token.')
                ->schema([
                    TextInput::make('modrinth_token')
                        ->label('Personal Access Token (optional)')
                        ->password()
                        ->revealable()
                        ->placeholder('mrp_xxxxxxxx')
                        ->helperText('Create a token at https://modrinth.com/settings/pat')
                        ->default(fn () => config('modpack-manager.modrinth_token')),
                ]),

            Section::make('About')
                ->schema([
                    Placeholder::make('info')
                        ->label('')
                        ->content('API keys are stored in the panel\'s .env file and are never exposed to server users.'),
                ]),
        ];
    }

    public function saveSettings(array $data): void
    {
        $values = [];

        if (isset($data['curseforge_api_key'])) {
            $values['MODPACK_MANAGER_CURSEFORGE_API_KEY'] = $data['curseforge_api_key'];
        }

        if (isset($data['modrinth_token'])) {
            $values['MODPACK_MANAGER_MODRINTH_TOKEN'] = $data['modrinth_token'];
        }

        $this->writeToEnvironment($values);

        Notification::make()
            ->title('Modpack Manager settings saved.')
            ->success()
            ->send();
    }
}
