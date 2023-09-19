<?php

namespace Autoklose\DataShipper\Models;

use Illuminate\Database\Eloquent\Model;

class FailedShipment extends Model {
    protected $guarded = [];

    protected $dates = [
        'last_retried_at'
    ];

    public function packages()
    {
        return $this->hasMany(FailedPackage::class);
    }
}
