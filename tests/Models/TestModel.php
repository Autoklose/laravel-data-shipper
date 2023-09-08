<?php

namespace Autoklose\DataShipper\Tests\Models;

use Autoklose\DataShipper\Traits\HasDataSubscribers;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TestModel extends Model {
    use HasDataSubscribers;

    public $elasticsearch_index = 'test_model_index';
    protected $guarded = [];

    protected $casts = [
        'json_field' => 'array'
    ];
}
