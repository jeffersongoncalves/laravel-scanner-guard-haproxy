<?php

declare(strict_types=1);

namespace JeffersonGoncalves\ScannerGuardHaproxy;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class ScannerGuardHaproxyServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-scanner-guard-haproxy')
            ->hasConfigFile('scanner-guard-haproxy');
    }
}
