<?php

namespace Autoklose\DataShipper\Commands;

use Autoklose\DataShipper\Facades\DataShipper;
use Autoklose\DataShipper\Jobs\DispatchShipmentToSubscriber;
use Autoklose\DataShipper\Jobs\NotifySubscriberOfShipment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

class ShipIt extends Command
{
    public $signature = 'data-shipper:ship-it';

    public $description = 'Notify all data subscribers of payloads that are ready to ship.';

    public function handle()
    {
        $shipments =  DataShipper::pendingShipments();

        foreach ($shipments as $shipment) {
            NotifySubscriberOfShipment::dispatch($shipment);
        }

        return Command::SUCCESS;
    }
}
