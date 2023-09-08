<?php

namespace Autoklose\DataShipper;

use Autoklose\DataShipper\Contracts\PackageInterface;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class ShipmentRepository {
    private RedisFactory $redis;

    /**
     * The number of minutes until the shipment will execute,
     * regardless of shipment size.
     *
     * @var int
     */
    public int $minutesUntilShipment;

    /**
     * The maximum amount of packages in a shipment until it is released
     *
     * @var int
     */
    public int $maxShipmentLength;

    /**
     * The keys stored on the job hashes.
     *
     * @var array
     */
    public array $keys = [
        'id', 'uuid', 'table', 'payload', 'class_name', 'mode'
    ];

    public function __construct(RedisFactory $redis, int $minutesUntilShipment = 5, int $maxShipmentLength = 10) {
        $this->redis = $redis;
        $this->minutesUntilShipment = $minutesUntilShipment;
        $this->maxShipmentLength = $maxShipmentLength;
    }

    /**
     * Get the hashset values from redis using a list of UUIDs
     *
     * @param  array  $ids
     * @param  string  $key
     * @param $startingIndex
     * @return mixed
     */
    public function getPackagesByUuids(array $ids, string $key, $startingIndex = 0): mixed {
        $packages = $this->connection()->pipeline(function ($pipe) use ($ids, $key) {
            foreach ($ids as $id) {
                $pipe->hmget($id, $this->keys);
            }

            $pipe->get("{$key}-shipment-length");
        });

        // Remove these jobs from the queue
        $count = array_pop($packages) - count($ids);
        if ($count >= 1 && $count < $this->maxShipmentLength) {
            $this->connection()->pipeline(function ($pipe) use ($key) {
                // Reset the timeout for the shipment
                $this->updateShipmentManifest($pipe, $key);
            });
        }

        return collect($packages)->filter(fn($package) => !empty($package))
            ->map(fn($package) => new Package($package['id'], $package['payload'], $package['class_name'], $package['mode'], $package['uuid']));
    }

    /**
     * Determine the length of the shipment for a given model
     *
     * @param  string  $key
     * @return int
     */
    public function getShipmentLength(string $key): int {
        return $this->connection()->zcount($key, '-inf', '+inf');
    }

    /**
     * Get just the UUIDs in a package
     *
     * @param  string  $key
     * @return mixed
     */
    public function getPackageUuidsForShipment(string $key): mixed {
        return $this->connection()->zrangebyscore(
            $key, '-inf', '+inf', ['limit' => ['offset' => 0, 'count' => $this->maxShipmentLength]]
        );
    }

    /**
     * Get all packages belonging to a shipment
     *
     * @param  string  $key
     * @return mixed
     */
    public function getPackagesForShipment(string $key): mixed {
        return $this->getPackagesByUuids($this->connection()->zrangebyscore(
            $key, '-inf', '+inf', ['limit' => ['offset' => 0, 'count' => $this->maxShipmentLength]]
        ), $key);
    }

    /**
     * Flush a subset of packages from Redis
     * Returns a boolean to determine if another shipment is ready immediately.
     *
     * @param  string  $key
     * @param  int  $length
     * @return bool
     */
    public function flushPackagesForShipment(string $key, int $length): bool {
        $lock = Cache::lock("$key-data-shipper-lock");

        $lock->block(10);

        $packageIds = $this->connection()->zrange($key, 0, $length);

        $results = $this->connection()->pipeline(function ($pipe) use ($key, $length, $packageIds) {
            foreach ($packageIds as $id) {
                $pipe->hdel($id);
            }

            $pipe->zrem(...$packageIds);
            $pipe->decrby("{$key}-shipment-length", count($packageIds));
        });

        $count = array_pop($results);

        // If there are no shipments left, we can remove this shipment record, so we don't keep checking to push changes
        if ($count < 1) {
            $this->connection()->pipeline(function ($pipe) use ($key) {
                $pipe->del($key);
                $pipe->del("{$key}-shipment-length");
            });
        }

        $lock->release();

        return $count === $this->maxShipmentLength;
    }

    /**
     * Get a list of shipments that are ready to notify subscribers
     *
     * @return mixed
     */
    public function getPendingShipments(): mixed {
        return $this->connection()->zrangebyscore('shipments', '-inf', now()->getPreciseTimestamp(4));
    }

    /**
     * Add a package to a shipment pipeline
     *
     * @param $pipe
     * @param  string  $key
     * @param  PackageInterface  $package
     * @return void
     */
    protected function addPackageToShipment($pipe, string $key, PackageInterface $package): void {
        $pipe->zadd($key, str_replace(',', '.', microtime(true) * -1), $package->uuid());
    }

    /**
     * Record reference of the shipment sorted by how soon it will be processed
     *
     * @param $pipe
     * @param  string  $key
     * @param  bool  $now
     * @return void
     */
    public function updateShipmentManifest($pipe, string $key, bool $now = false): void {
        $timestamp = !$now ? now()->addMinutes($this->minutesUntilShipment)->getPreciseTimestamp(4) : now()->getPreciseTimestamp(4);
        $pipe->zadd("shipments", $timestamp, $key);
    }

    /**
     * Push a package into a shipment container
     *
     * @param  PackageInterface|PackageInterface[]  $packages
     * @param  Model  $model
     * @return void
     */
    public function push(array|PackageInterface $packages, Model $model): void {
        $table = $model->getTable();
        $className = get_class($model);

        if (!(is_array($packages))) {
            $packages = [$packages];
        }

        // Make sure a flush isn't happening at this time
        $lock = Cache::lock("{$className}-data-shipper-lock");
        $lock->block(10);

        $currentShipmentSize = $this->connection()->incrby("{$className}-shipment-length", count($packages));

        $lock->release();

        $this->connection()->pipeline(function ($pipe) use ($packages, $table, $className, $currentShipmentSize) {

            if ($currentShipmentSize === count($packages) && $currentShipmentSize < $this->maxShipmentLength) {
                // Record the shipment and
                $this->updateShipmentManifest($pipe, $className);
            } elseif ($currentShipmentSize >= $this->maxShipmentLength) {
                $this->updateShipmentManifest($pipe, $className, true);
            }

            $time = str_replace(',', '.', microtime(true));

            foreach ($packages as $package) {
                $this->addPackageToShipment($pipe, $className, $package);
                $pipe->hmset($package->uuid(), [
                    'id' => $package->id(),
                    'uuid' => $package->uuid(),
                    'table' => $table,
                    'class_name' => $package->className(),
                    'payload' => $package->pack(),
                    'mode' => $package->mode(),
                    'created_at' => $time,
                    'updated_at' => $time
                ]);
            }
        });
    }

    /**
     * @return \Illuminate\Redis\Connections\Connection
     */
    protected function connection(): \Illuminate\Redis\Connections\Connection {
        return $this->redis->connection('data-shipper');
    }
}
