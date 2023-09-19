<?php

namespace Autoklose\DataShipper\Jobs;

use Autoklose\DataShipper\ShipmentRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class ClearPackagesFromShipment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $shipment;

    protected int $length;

    protected bool $limitHit;

    public function __construct(string $shipment, int $length, bool $limitHit)
    {
        $this->shipment = $shipment;
        $this->length = $length;
        $this->limitHit = $limitHit;
    }

    public function handle()
    {
        $repository = new ShipmentRepository(app()->make(Factory::class), config('data-shipper.shipments.max_wait_minutes'), config('data-shipper.shipments.max_size'));

        $canShipAgain = $repository->flushPackagesForShipment($this->shipment, $this->length);

        Cache::lock("data-shipper-{$this->shipment}-active-lock")->forceRelease();

        if ($canShipAgain) {
            NotifySubscriberOfShipment::dispatch($this->shipment, true);
        }
    }
}
