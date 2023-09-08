# Laravel Data Shipper
Laravel Data Shipper is a tool that helps relieve stress from secondary data sources (e.g. Elasticsearch) by delaying non time-sensitive data updates that is managed in a queue based system in Redis.

## Installation

You can install the package via composer:

```bash
composer require autoklose/laravel-data-shipper
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="data_shipper-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag=":data_shipper-config"
```

You can customize how often data shipments happen by changing the following values in the config file:

### max_size
- How many updates are held in the queue for a model before it will be acted upon.

### max_wait_minutes
- How many minutes should Data Shipper wait until shipping changes regardless of not yet reaching the max queue size.

```php
return [
    'subscribers' => ['elasticsearch'],
    'shipments' => [
        'max_size' => 10,
        'max_wait_minutes' => 5
    ]
];
```

In order for updates/changes passed to DataShipper to be acted upon, you must add the following command to your Laravel project's scheduler:

```php
protected function schedule(Schedule $schedule)
{
  // ...

  $shedule->command('data-shipper:ship-it')->everyMinute();
}
```

All models that are passed through Data Shipper must use the HasDataSubscriber trait.

```php
use Illuminate\Database\Eloquent\Model;
use Autoklose\DataShipper\Traits\HasDataSubscribers;

class Record extends Model
{
  use HasDataSubscribers;
}
```

## Usage
Data Shipper can be made use of anywhere in your app using the Data Shipper Facade.

### Shipping Changes to a Model

Data Shipper provides support to automatically detect changes to a model and add them to the shipment queue.

However, you must push the model before saving the model instance.

```php
$record->foo = 'bar';
DataShipper::pushModel($record);

$record->save();
```

In cases where you only want certain fields to be written to the shipment queue, you can specifiy what columns should be observed.

```php
$record->foo = 'bar';
$record->bar = 'foo';

// Only changes made to the 'foo' field will be acted on
DataShipper::pushModel($record, ['foo']);

$record->save();
```

### Shipping Specific Data
In some cases you may want to provide a custom made array of data that should be passed to data subscribers.

In order to do this, provide the class of the model the changes are related to, an array of changes and the identifier for the model you are applying the changes to.
```php
DataShipper::push(Record::class, ['text_field' => 'changed text'], $record->key());
```

### Shipping Multiple Changes
If you have a large set of changes you want to provide to subscribers you can do so by using Data Shippers <b>pushMany</b> method.

You can provide an array of changes, however each array item must have an identifier.

```php
$changes = [
  ['id' => 1, 'text_field' => 'change 1'],
  ['id' => 2, 'text_field' => 'change 2'],
];

DataShipper::pushMany(Record::class, $changes, 'id');
```

### Renaming Columns per Subscriber
In some subscribers you may have a column named differently than you do than in your primary data source. You can easily remap these columns in your changes by adding a column map to your model

```php
use Illuminate\Database\Eloquent\Model;
use Autoklose\DataShipper\Traits\HasDataSubscribers;

class Record extends Model
{
  use HasDataSubscribers;

  protected $elasticsearchMap = [
    'sql_column_name' => 'elastic_search_column_name'
  ];
}
```

### Transforming Data Before Shipping
If you need to massage the data before it is handled by a subscriber you can make use of the transformData method on your model.

```php
use Illuminate\Database\Eloquent\Model;
use Autoklose\DataShipper\Traits\HasDataSubscribers;

class Record extends Model
{
  use HasDataSubscribers;

  public function transformData($value, $key, $subscriber) {
      if ($subscriber === 'elasticsearch') {
        if ($key === 'foo') {
          return $value * 2;
        } else if ($key === 'bar') {
          return $value . ' added';
        }
      }

      return $value;
  }
}
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Simon Chawla](https://github.com/SimonChaw)
- [All Contributors](../../contributors)

## License

This project is licensed under the [Apache License, Version 2.0](LICENSE). Please see [License File](LICENSE) for more information.
