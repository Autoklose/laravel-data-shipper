<?php

namespace Autoklose\DataShipper\Jobs;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Autoklose\DataShipper\Facades\DataShipper;

class NotifySubscriberOfShipment implements ShouldQueue {

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $shipment;

    protected int $maxShipmentsPerMinute;

    public function __construct(string $shipment)
    {
        $this->shipment = $shipment;
        $this->maxShipmentsPerMinute = config('data-shipper.shipments.max_shipments_per_minute');
    }

    public function handle()
    {
        $lock = Cache::lock("data-shipper-{$this->shipment}-active-lock", 50);

        if (!$lock->get()) {
            return;
        }

        $limitHit = false;
        $lastShipment = Redis::connection('data-shipper')->zscore("records", $this->shipment);

        if (!$lastShipment || Carbon::createFromTimestamp($lastShipment)->isBefore(now()->startOfMinute())) {
            // New throttle setup
            $timestamp = now()->timestamp;
            Redis::connection('data-shipper')->pipeline(function ($pipe) use($timestamp) {
                $pipe->zadd("records", $timestamp, $this->shipment);
                $pipe->expire("records", 60);
                $pipe->del("{$this->shipment}-per-minute");
                $pipe->incr("{$this->shipment}-per-minute");
                $pipe->expire("{$this->shipment}-per-minute", 60);
            });
        } else {
            $handledThisMinute = Redis::connection('data-shipper')->incr("{$this->shipment}-per-minute");
            if ($handledThisMinute > $this->maxShipmentsPerMinute) {
                return;
            }

            $limitHit = $this->maxShipmentsPerMinute === $handledThisMinute;
        }

        $subscribers = DataShipper::getSubscribers();
        $packageIds = DataShipper::getPackagesForShipment($this->shipment, true);

        $jobs = [];
        if (!empty($packageIds)) {
            foreach ($subscribers as $subscriberName => $subscriber) {
                $jobs[] = new DispatchShipmentToSubscriber($this->shipment, $packageIds, $subscriberName);
            }
        }

        $jobs[] = new ClearPackagesFromShipment($this->shipment, count($packageIds), $limitHit);

        Bus::chain($jobs)->dispatch();
    }
}
