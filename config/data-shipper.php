<?php

return [
    'subscribers' => ['elasticsearch'],
    'shipments' => [
        'max_size' => 10,
        'max_wait_minutes' => 5,
        'max_shipments_per_minute' => 10,
        'max_retries' => 3
    ]
];
