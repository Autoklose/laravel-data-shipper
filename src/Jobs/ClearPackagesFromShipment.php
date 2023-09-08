<?php

namespace Autoklose\DataShipper\Jobs;

use Autoklose\DataShipper\ShipmentRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ClearPackagesFromShipment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $shipment;

    protected int $length;

    public function __construct(string $shipment, int $length)
    {
        $this->shipment = $shipment;
        $this->length = $length;
    }

    public function handle()
    {
        $repository = new ShipmentRepository(app()->make(Factory::class));

        $repository->flushPackagesForShipment($this->shipment, $this->length);
    }
}
