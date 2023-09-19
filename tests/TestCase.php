<?php

namespace Autoklose\DataShipper\Tests;

use Autoklose\DataShipper\DataShipperServiceProvider;
use Elasticsearch\Client;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\TestCase as Orchestra;
use MailerLite\LaravelElasticsearch\ServiceProvider;

class TestCase extends Orchestra {
    protected function setUp(): void {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn(string $modelName) => 'Autoklose\\DataShipper\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app) {
        return [
            ServiceProvider::class,
            DataShipperServiceProvider::class
        ];
    }

    public function getEnvironmentSetUp($app) {
        config()->set('queue.default', 'sync');
        config()->set('queue.connections',
            ['sync' => [
                'driver' => 'sync',
            ]]);
        config()->set('database.connections.testing', [
            "driver" => "mysql",
            "url" => null,
            "host" => "mysql",
            "port" => "3306",
            "database" => "homestead",
            "username" => "homestead",
            "password" => "secret",
            "unix_socket" => "",
            "charset" => "utf8mb4",
            "collation" => "utf8mb4_unicode_ci",
            "prefix" => "",
            "prefix_indexes" => true,
            "strict" => true,
            "engine" => null,
            "options" => []
        ]);
        config()->set('database.default', 'testing');

        DB::statement("DROP TABLE IF EXISTS test_models;");
        DB::statement("DROP TABLE IF EXISTS failed_packages;");
        DB::statement("DROP TABLE IF EXISTS failed_shipments;");

        $migration = include __DIR__.'/Migrations/create_test_model_table.php.stub';
        $migration->up();
        $migration = include __DIR__.'/../database/migrations/create_failed_shipments_table.php.stub';
        $migration->up();
        $migration = include __DIR__.'/../database/migrations/create_failed_packages_table.php.stub';
        $migration->up();


        $this->setupElasticSearch($app);
        $this->setupRedis($app);
    }

    public function setupRedis($app) {
        config()->set('database.redis', [
            'client' => env('REDIS_CLIENT', 'phpredis'),

            'data-shipper' => [
                'host' => 'redis',
                'password' => '',
                'port' => 6379,
                'database' => 0,
            ],
        ]);

        $redisClient = $app->make(\Illuminate\Contracts\Redis\Factory::class);
        $redisClient->connection('data-shipper')->flushDb();
    }

    public function setupElasticSearch($app) {
        config()->set('elasticsearch', [
            'defaultConnection' => 'default',
            'connections' => [

                'default' => [
                    'hosts' => [
                        env('ELASTICSEARCH_HOST_CONFIG1', 'elasticsearch:9200'),
                    ],
                    'sslVerification' => null,
                    'logging' => false,
                    'logPath' => storage_path('logs/elasticsearch.log'),
                    'logLevel' => 'info',
                    'retries' => null,
                    'sniffOnStart' => false,
                    'httpHandler' => null,
                    'connectionPool' => null,
                    'connectionSelector' => null,
                    'serializer' => null,
                    'connectionFactory' => null,
                    'endpoint' => null,
                ],
                'logger' => [
                    'hosts' => [
                        env('ELASTICSEARCH_LOG_HOST', '192.168.221.8:9200'),
                    ],
                    'sslVerification' => null,
                    'logging' => false,
                    'logPath' => storage_path('logs/elasticsearch.log'),
                    'logLevel' => 'info',
                    'retries' => null,
                    'sniffOnStart' => false,
                    'httpHandler' => null,
                    'connectionPool' => null,
                    'connectionSelector' => null,
                    'serializer' => null,
                    'connectionFactory' => null,
                    'endpoint' => null,
                ],
                'readonly' => [
                    'hosts' => [
                        env('ELASTICSEARCH_HOST_CONFIG_READ', 'elasticsearch:9200'),
                    ],
                ]
            ]]);

        $this->recreateIndex();
    }

    public function recreateIndex() {
        /** @var Client $client */
        $client = app()->make(Client::class);

        if ($client->indices()->exists(['index' => 'test_model_index'])) {
            $client->indices()->delete(['index' => 'test_model_index']);
        }

        // Set the Elasticsearch mapping for the index
        $indexConfig = [
            'index' => 'test_model_index',
            'body' => [
                'mappings' => [
                    'properties' => [
                        'string_field' => ['type' => 'text'],
                        'text_field' => ['type' => 'text'],
                        'integer_field' => ['type' => 'integer'],
                        'float_field' => ['type' => 'float'],
                        'boolean_field' => ['type' => 'boolean'],
                        'timestamp_field' => [
                            'type' => 'date',
                            "format" => "strict_date_optional_time||yyyy-MM-dd HH:mm:ss"
                        ],
                        'json_field' => ['type' => 'object'],
                    ],
                ],
            ],
        ];

        // Create the Elasticsearch index
        $client->indices()
            ->create($indexConfig);
    }
}
