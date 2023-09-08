<?php

use Autoklose\DataShipper\Facades\DataShipper;

it('accepts specific fields to create a payload', function(\Autoklose\DataShipper\Tests\Models\TestModel $test) {
    $test->string_field = 'updated string';
    DataShipper::pushModel($test, ['string_field']);

    $shipment = DataShipper::getPackagesForShipment(\Autoklose\DataShipper\Tests\Models\TestModel::class);

    $package = $shipment->first();
    expect(get_class($package))->toEqual(\Autoklose\DataShipper\Package::class);
    expect($package->getPayload())->toBeArray()->toEqual(['string_field' => $test->string_field]);
    expect($package->id())->toEqual($test->getKey());
})->with('test-models');

it('stores changes to create a payload', function(\Autoklose\DataShipper\Tests\Models\TestModel $test) {
    $test->string_field = 'updated string';
    $test->integer_field = 20;

    DataShipper::pushModel($test);

    $shipment = DataShipper::getPackagesForShipment(\Autoklose\DataShipper\Tests\Models\TestModel::class);
    $package = $shipment->first();

    expect(get_class($package))->toEqual(\Autoklose\DataShipper\Package::class);
    expect($package->getPayload())->toBeArray()->toEqual($test->getDirty());
    expect($package->id())->toEqual($test->getKey());
})->with('test-models');

it('stores an array of specific changes', function() {
    $changes = ['string_field' => 'updated_changes', 'integer_field' => 20];

    DataShipper::push(\Autoklose\DataShipper\Tests\Models\TestModel::class, $changes, 1);

    $shipment = DataShipper::getPackagesForShipment(\Autoklose\DataShipper\Tests\Models\TestModel::class);
    $package = $shipment->first();

    expect(get_class($package))->toEqual(\Autoklose\DataShipper\Package::class);
    expect($package->getPayload())->toBeArray()->toEqual($changes);
    expect($package->id())->toEqual(1);
});

it("stores an array of many specific changes", function($changes) {
    DataShipper::pushMany(\Autoklose\DataShipper\Tests\Models\TestModel::class, $changes, 'id');

    $shipment = DataShipper::getPackagesForShipment(\Autoklose\DataShipper\Tests\Models\TestModel::class);
    expect($shipment)->toHaveCount(count($changes));
})->with('bulk-changes');

it("flushes a queue based on length and leaves remaining changes untouched", function($changes) {
    config()->set('data-shipper.shipments.max_size', 5);
    DataShipper::pushMany(\Autoklose\DataShipper\Tests\Models\TestModel::class, $changes, 'id');
    $key = \Autoklose\DataShipper\Tests\Models\TestModel::class;

    $shipment = DataShipper::getPackagesForShipment($key);
    expect($shipment)->toHaveCount(5);

    /** @var \Autoklose\DataShipper\ShipmentRepository $repository */
    $repository = app()->make(\Autoklose\DataShipper\ShipmentRepository::class);

    $repository->flushPackagesForShipment($key, 5);

    $remainingShipment = DataShipper::getPackagesForShipment($key);
    expect($remainingShipment)->toHaveCount(5);

    $repository->flushPackagesForShipment($key, 5);
    $remainingShipment = DataShipper::getPackagesForShipment($key);
    expect($remainingShipment)->toBeEmpty();

    expect(\Illuminate\Support\Facades\Redis::connection('data-shipper')->exists($key))->toBeFalsy();
    expect(\Illuminate\Support\Facades\Redis::connection('data-shipper')->exists("{$key}-shipment-length"))->toBeFalsy();
})->with('bulk-changes');

it("will not be mark changes as ready if not enough time has passed", function($changes) {
    config()->set('data-shipper.shipments.max_size', 20);
    DataShipper::pushMany(\Autoklose\DataShipper\Tests\Models\TestModel::class, $changes, 'id');

    $pendingShipments = DataShipper::pendingShipments();
    expect($pendingShipments)->toBeArray()->toBeEmpty();

    \Carbon\Carbon::setTestNow(now()->addMinutes(config()->get('data-shipper.shipments.max_wait_minutes')));

    $pendingShipments = DataShipper::pendingShipments();
    expect($pendingShipments)->toBeArray()->toHaveCount(1);
})->with('bulk-changes');

it("will mark changes as ready if enough changes exist", function($changes)
{
    config()->set('data-shipper.shipments.max_size', count($changes) + 1);

    DataShipper::pushMany(\Autoklose\DataShipper\Tests\Models\TestModel::class, $changes, 'id');

    $pendingShipments = DataShipper::pendingShipments();
    expect($pendingShipments)->toBeArray()->toBeEmpty();

    DataShipper::push(\Autoklose\DataShipper\Tests\Models\TestModel::class, ['text_field' => 'changed'],  1);


    $pendingShipments = DataShipper::pendingShipments();
    expect($pendingShipments)->toBeArray()->toHaveCount(1);
})->with('bulk-changes');

it('has a console command that will automatically push changes to subscribers', function($changes)
{
    DataShipper::pushMany(\Autoklose\DataShipper\Tests\Models\TestModel::class, $changes, 'id');

    \Illuminate\Support\Facades\Queue::fake();

    $command = new \Autoklose\DataShipper\Commands\ShipIt();
    $command->handle();

    \Illuminate\Support\Facades\Queue::assertPushed(\Autoklose\DataShipper\Jobs\NotifySubscriberOfShipment::class);

    $notifyJob = new \Autoklose\DataShipper\Jobs\NotifySubscriberOfShipment(\Autoklose\DataShipper\Tests\Models\TestModel::class);
    $notifyJob->handle();

    \Illuminate\Support\Facades\Queue::assertPushed(\Autoklose\DataShipper\Jobs\DispatchShipmentToSubscriber::class);
})->with('bulk-changes');

it('has a job that flushes item from the queue', function($changes) {
    DataShipper::pushMany(\Autoklose\DataShipper\Tests\Models\TestModel::class, $changes, 'id');
    $key = \Autoklose\DataShipper\Tests\Models\TestModel::class;

    $job = new \Autoklose\DataShipper\Jobs\ClearPackagesFromShipment($key, count($changes));
    $job->handle();

    $remainingShipment = DataShipper::getPackagesForShipment($key);
    expect($remainingShipment)->toBeEmpty();

    expect(\Illuminate\Support\Facades\Redis::connection('data-shipper')->exists($key))->toBeFalsy();
    expect(\Illuminate\Support\Facades\Redis::connection('data-shipper')->exists("{$key}-shipment-length"))->toBeFalsy();
})->with('bulk-changes');

dataset('test-models', [
    [fn() => \Autoklose\DataShipper\Tests\Models\TestModel::create([
        'string_field' => 'some string',
        'text_field' => 'some text',
        'integer_field' => 10,
        'float_field' => 3.14159,
        'boolean_field' => false,
        'timestamp_field' => now()->format('Y-m-d H:i:s'),
        'json_field' => ['test' => 'test']
    ])]
]);

dataset('bulk-changes', [
   [fn() => array_map(fn($i) => ['id' => $i, 'text_field' => "text change $i", 'integer' => $i * 10], range(1, 10))]
]);

beforeEach(function() {
   \Illuminate\Support\Facades\Redis::connection('data-shipper')->flushdb();
});
