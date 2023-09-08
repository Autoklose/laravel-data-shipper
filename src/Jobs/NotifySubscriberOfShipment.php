<?php

namespace Autoklose\DataShipper\Jobs;

use Autoklose\DataShipper\Facades\DataShipper;
use Autoklose\DataShipper\ShipmentRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

class NotifySubscriberOfShipment implements ShouldQueue {

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $shipment;

    public function __construct(string $shipment)
    {
        $this->shipment = $shipment;
    }

    public function handle()
    {
        $subscribers = DataShipper::getSubscribers();
        $packageIds = DataShipper::getPackagesForShipment($this->shipment, true);

        $jobs = [];
        foreach ($subscribers as $subscriberName => $subscriber) {
            $jobs[] = new DispatchShipmentToSubscriber($this->shipment, $packageIds, $subscriberName);
        }

        $jobs[] = new ClearPackagesFromShipment($this->shipment, count($packageIds));

        Bus::chain($jobs)->dispatch();
    }
}
