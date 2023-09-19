<?php

namespace Autoklose\DataShipper\Models;

use Illuminate\Database\Eloquent\Model;

class FailedPackage extends Model {
    protected $guarded = [];

    protected $casts = [
        'payload' => 'array'
    ];
}
