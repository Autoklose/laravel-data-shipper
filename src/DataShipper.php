<?php

namespace Autoklose\DataShipper;

use Autoklose\DataShipper\Models\FailedPackage;
use Autoklose\DataShipper\Models\FailedShipment;
use Autoklose\DataShipper\Subscribers\ElasticsearchSubscriber;
use Autoklose\DataShipper\Subscribers\Contracts\DataSubscriberInterface;
use Autoklose\DataShipper\Traits\HasDataSubscribers;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class DataShipper {
    protected $app;
    protected $repository;
    protected $subscribers = [];

    public function __construct($app) {
        $this->app = $app;
        $this->setup();
    }

    /**
     * Push many changes to a shipment queue
     *
     * @param  string  $className
     * @param  Collection|array  $changes
     * @param  null  $identifierKey
     * @return void
     * @throws \Exception
     */
    public function pushMany(string $className, array|Collection $changes, $identifierKey = null): void {
        if (!in_array(HasDataSubscribers::class, class_uses_recursive($className))) {
            throw new \Exception("Provided model does not use the HasDataSubscribers trait");
        }

        if ($changes instanceof Collection) {
            $changes = $changes->toArray();
        }

        /** @var Model $class */
        $class = new $className();

        $identifierKey = $identifierKey ?? $class->getKeyName();
        $ids = array_column($changes, $identifierKey);

        if (count($ids) !== count($changes)) {
            throw new InvalidArgumentException("Not all changes have an identifier.");
        }
        unset($ids);

        foreach (array_chunk($changes, 250) as $chunk) {
            $packages = array_map(function ($item) use ($identifierKey, $className) {
                $id = $item[$identifierKey];
                unset($item[$identifierKey]);

                $mode = array_key_exists('mode', $item) ? $item['mode'] : Package::MODE_UPDATE;

                return new Package($id, $item, $className, $mode);
            }, $chunk);

            $this->repository->push($packages, $class);
        }
    }

    /**
     * Push a list of manually created changes for a model
     *
     * @param  string  $className
     * @param  Collection|array  $changes
     * @param  mixed  $identifier
     * @param  string  $mode
     * @return void
     * @throws \Exception
     */
    public function push(string $className, array|Collection $changes, $identifier, string $mode = Package::MODE_UPDATE): void {
        if (!in_array(HasDataSubscribers::class, class_uses_recursive($className))) {
            throw new \Exception("Provided model does not use the HasDataSubscribers trait");
        }

        if ($changes instanceof Collection) {
            $changes = $changes->toArray();
        }

        $this->repository->push(new Package($identifier, $changes, $className, $mode), new $className());
    }

    /**
     * Push a list of changes from an Eloquent Model
     *
     * @param  Model  $model
     * @param  array  $only
     * @param  string  $mode
     * @return void
     * @throws \Exception
     */
    public function pushModel(Model $model, array $only = [], string $mode = Package::MODE_UPDATE): void {
        if (!in_array(HasDataSubscribers::class, class_uses_recursive(get_class($model)))) {
            throw new \Exception("Provided model does not use the HasDataSubscribers trait");
        }

        if (!empty($only)) {
            $payload = collect($model->toArray())->filter(fn($value, $key) => in_array($key, $only));
        } else {
            $payload = $model->getDirty();
        }

        $this->repository->push(new Package($model->getKey(), $payload, get_class($model), $mode), $model);
    }


    /**
     * Offloads a shipment with its packages to the database for long-term storage so that it can be retried at a later time.
     *
     * @param  string  $subscriber
     * @param  string  $shipment
     * @param  array  $packageIds
     * @return void
     */
    public function handleProblematicShipment(string $subscriber, string $shipment, array $packageIds): void {
        // We failed to push this dataset as an update for one or more subscribers.
        // Offload it to a table for retrying.
        $packages = $this->repository->getPackagesByUuids($shipment, $packageIds);

        $failedShipment = FailedShipment::create([
            'class_name' => $shipment,
            'subscriber' => $subscriber
        ]);

        $failedShipment->packages()->saveMany(
            $packages->map(fn($package) => new FailedPackage(['model_id' => $package->id(), 'payload' => $package->getPayload()]))
        );
    }

    public function getPackagesForShipment(string $key, bool $uuidsOnly = false) {
        return $uuidsOnly ? $this->repository->getPackageUuidsForShipment($key) :
            $this->repository->getPackagesForShipment($key);
    }

    /**
     * Get a list of all shipments that are ready to notify subscribers
     *
     * @return array
     */
    public function pendingShipments(): array {
        return $this->repository->getPendingShipments();
    }

    protected function setup(): void {
        $config = $this->getConfig();

        $shipmentConfig = $config['shipments'];
        $this->repository = new ShipmentRepository(
            $this->app->make(Factory::class),
            $shipmentConfig['max_wait_minutes'],
            $shipmentConfig['max_size'],
        );

        if (empty($config['subscribers'])) {
            throw new InvalidArgumentException("At least one data source must be subscribed");
        }

        foreach ($config['subscribers'] as $subscriber) {
            $resolver = $this->resolveSubscriber($subscriber);
            $this->subscribers[$subscriber] = $resolver;
        }
    }

    protected function resolveSubscriber($subscriber): DataSubscriberInterface {
        if ($subscriber === 'elasticsearch') {
            return app()->make(ElasticsearchSubscriber::class);
        }

        throw new InvalidArgumentException("No resolver exists for $subscriber data source.");
    }

    /**
     * Get a list of all data subscribers
     *
     * @return array
     */
    public function getSubscribers(): array {
        return $this->subscribers;
    }

    protected function getConfig() {
        return $this->app['config']['data-shipper'];
    }
}
