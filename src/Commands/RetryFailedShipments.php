<?php

namespace Autoklose\DataShipper\Commands;

use Autoklose\DataShipper\Jobs\RetryFailedShipment;
use Autoklose\DataShipper\Models\FailedShipment;
use Illuminate\Console\Command;

class RetryFailedShipments extends Command
{
    public $signature = 'data-shipper:retry';

    public $description = 'Retry any and all failed shipments.';

    public function handle()
    {
        $maxRetries = config('data-shipper.shipments.max_retries');

        FailedShipment::where('retries', '<', $maxRetries)->chunk(250, function($failedShipments) {
                foreach ($failedShipments as $failedShipment) {
                    RetryFailedShipment::dispatch($failedShipment);
                }
            });

        return Command::SUCCESS;
    }
}
