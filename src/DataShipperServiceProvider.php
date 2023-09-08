<?php

namespace Autoklose\DataShipper;

use Autoklose\DataShipper\Commands\RetryFailedShipments;
use Autoklose\DataShipper\Commands\ShipIt;
use Illuminate\Foundation\Application;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class DataShipperServiceProvider extends PackageServiceProvider
{
    public function registeringPackage() {
        $this->app->bind(DataShipper::class, function(Application $app) {
            return new DataShipper($app);
        });
    }

    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-data-shipper')
            ->hasConfigFile()
            ->hasMigration('create_failed_shipments_table')
            ->hasMigration('create_failed_packages_table')
            ->hasCommand(ShipIt::class)
            ->hasCommand(RetryFailedShipments::class);
    }
}
