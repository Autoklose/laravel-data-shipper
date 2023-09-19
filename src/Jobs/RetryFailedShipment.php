<?php

namespace Autoklose\DataShipper\Jobs;

use Autoklose\DataShipper\Facades\DataShipper;
use Autoklose\DataShipper\Package;
use Autoklose\DataShipper\Subscribers\Contracts\DataSubscriberInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Autoklose\DataShipper\Models\FailedShipment;

class RetryFailedShipment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var FailedShipment $failedShipment */
    public $failedShipment;

    public function __construct($failedShipment)
    {
        $this->failedShipment = $failedShipment;
    }

    public function handle()
    {
        $this->failedShipment->increment('retries');
        $this->failedShipment->update(['last_retried_at' => now()]);

        if ($this->failedShipment->retries >= (int)config('data-shipper.shipments.max_retries')) {
            return;
        }

        $packages = $this->failedShipment->packages;

        $preparedPackages = $packages->map(fn($package) => new Package($package->model_id, $package->payload, $this->failedShipment->class_name));
        $subscribers = DataShipper::getSubscribers();

        /** @var DataSubscriberInterface $subscriber */
        $subscriber = $subscribers[$this->failedShipment->subscriber];

        $subscriber->ship($preparedPackages);

        $this->failedShipment->delete();
    }
}
