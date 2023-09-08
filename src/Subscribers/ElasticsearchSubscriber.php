<?php

namespace Autoklose\DataShipper\Subscribers;

use Autoklose\DataShipper\Package;
use Autoklose\DataShipper\Subscribers\Contracts\DataSubscriberInterface;
use Elasticsearch\Client;
use Elasticsearch\Common\Exceptions\Conflict409Exception;

class ElasticsearchSubscriber implements DataSubscriberInterface {
    protected $app;
    protected $retries;

    public function __construct($app)
    {
        $this->app = $app;

        $this->retries = config('data-shipper.subscribers.elasticsearch.retires', 3);
    }

    public function ship($packages) {
        $package = $packages->first();

        $className = $package->className();
        $class = new $className();

        $index = $class->elasticsearch_index;

        $esManifest = [];
        // Transform packages
        foreach ($packages as $package)
        {
            $payload = $package->getPayload();
            $mode = $package->mode();
            $payload = $class->mapData($payload, 'elasticsearch');

            $esManifest[] = [$mode => ['_index' => $index, '_id' => $package->id()]];

            if ($mode === Package::MODE_UPDATE) {
                $esManifest[] = ['doc' => $payload];
            } else {
                $esManifest[] = $payload;
            }
        }

        /** @var Client $client */
        $client = $this->app->make(Client::class);

        $retries = $this->retries;

        $params = [];
        $params['body'] = $esManifest;
        $params['refresh'] = true;

        while ($retries > 0) {
            try {
                $client->bulk($params);
                $retries = 0;
            } catch (Conflict409Exception) {
                $retries --;

                if ($retries === 0) {
                   throw new \Exception("Failed to save changes in elasticsearch subscriber for {$package['class_name']}");
                }
                sleep(2);
            }
        }
    }
}
