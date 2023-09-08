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
        $threshold = now()->subMinutes(15);

        FailedShipment::where(fn($query) => $query->whereNull('last_retried_at')->orWhere('last_retried_at', '<=', $threshold))
            ->where('retries', '<', 3)->chunk(250, function($failedShipments) {
                foreach ($failedShipments as $failedShipment) {
                    RetryFailedShipment::dispatch($failedShipment);
                }
            });

        return Command::SUCCESS;
    }
}
