<?php

namespace Zielu92\FilamentImageLabeler;

use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FilamentImageLabelerServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-image-labeler';

    public static string $viewNamespace = 'filament-image-labeler';

    public function configurePackage(Package $package): void
    {
        $package->name(static::$name);

        if (file_exists($package->basePath('/../resources/views'))) {
            $package->hasViews(static::$viewNamespace);
        }

        if (file_exists($package->basePath('/../resources/lang'))) {
            $package->hasTranslations();
        }
    }

    public function packageBooted(): void
    {
        // Load migrations directly (no publish required)
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Asset Registration
        FilamentAsset::register(
            $this->getAssets(),
            $this->getAssetPackageName()
        );
    }

    protected function getAssetPackageName(): ?string
    {
        return 'zielu92/filament-image-labeler';
    }

    /**
     * @return array<Css|Js>
     */
    protected function getAssets(): array
    {
        return [
            Js::make('filament-image-labeler-scripts', __DIR__ . '/../resources/dist/filament-image-labeler.js'),
            Css::make('filament-image-labeler-styles', __DIR__ . '/../resources/dist/filament-image-labeler.css'),
        ];
    }
}
