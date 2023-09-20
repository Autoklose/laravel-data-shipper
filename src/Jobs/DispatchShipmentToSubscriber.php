<?php

namespace Autoklose\DataShipper\Jobs;

use Autoklose\DataShipper\Facades\DataShipper;
use Autoklose\DataShipper\ShipmentRepository;
use Autoklose\DataShipper\Subscribers\Contracts\DataSubscriberInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchShipmentToSubscriber implements ShouldQueue {

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $shipment;
    protected array $packageIds;
    protected string $subscriber;

    public function __construct(string $shipment, array $packageIds, string $subscriber)
    {
        $this->shipment = $shipment;
        $this->packageIds = $packageIds;
        $this->subscriber = $subscriber;
    }

    public function handle()
    {
        $subscribers = DataShipper::getSubscribers();
        $subscriber = $subscribers[$this->subscriber];
        $repository = new ShipmentRepository(app()->make(Factory::class));

        try {
            $packages = $repository->getPackagesByUuids($this->packageIds, $this->shipment);
            $subscriber->ship($packages);
        } catch (\Exception) {
            DataShipper::handleProblematicShipment($this->subscriber, $this->shipment, $this->packageIds);
        }
    }
}
