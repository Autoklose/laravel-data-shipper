<?php

use Elasticsearch\Client;
use Autoklose\DataShipper\Facades\DataShipper;

function assertEsIndexHasCount($parameters, $expectedCount)
{
    /** @var Client $client */
    $client = app()->make(Client::class);
    $client->indices()->refresh();

    $params = [
        'index' => 'test_model_index',
        'body' => [
            '_source' => false,
            'query' => [
                'bool' => [
                    'must' => []
                ]
            ]
        ]
    ];

    foreach ($parameters as $key => $value) {
        $expression =  ['match' => [$key => $value]];

        $params['body']['query']['bool']['must'][] = $expression;
    }

    $results = $client->search($params);

    return test()->expect($results['hits']['hits'])->toHaveCount($expectedCount);
}


it("can update items in an index", function(\Autoklose\DataShipper\Tests\Models\TestModel $testModel) {
    /** @var Client $client */
    $client = app()->make(Client::class);

    $client->index([
        'index' => $testModel->elasticsearch_index,
        'id' => $testModel->getKey(),
        'body' => $testModel->toArray()
    ]);

    $testModel->text_field = 'new value';
    DataShipper::pushModel($testModel);
    $packages = DataShipper::getPackagesForShipment(get_class($testModel));
    $esSubscriber = DataShipper::getSubscribers()['elasticsearch'];

    $esSubscriber->ship($packages);

    assertEsIndexHasCount(['text_field' => 'new value'], 1);
})->with('test-models');

it("can create items in an index", function(\Autoklose\DataShipper\Tests\Models\TestModel $testModel) {
    DataShipper::push(get_class($testModel), $testModel->toArray(), $testModel->getKey(), \Autoklose\DataShipper\Package::MODE_CREATE);

    $packages = DataShipper::getPackagesForShipment(get_class($testModel));
    $esSubscriber = DataShipper::getSubscribers()['elasticsearch'];
    $esSubscriber->ship($packages);

    $params = [
        'index' => $testModel->elasticsearch_index,
        'id' => $testModel->getKey(),
    ];

    /** @var Client $client */
    $client = app()->make(Client::class);

    expect($client->exists($params))->toBeTrue();
})->with('test-models');

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

afterEach(function() {
    $this->recreateIndex();
});
