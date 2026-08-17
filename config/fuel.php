<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Fuel Management Configurations
    |--------------------------------------------------------------------------
    */

    'high_consumption_threshold' => 500.00, // liters

    'low_efficiency_threshold' => 2.50, // liters per BCM (higher means less efficient)

    'efficiency_thresholds' => [
        'warning' => 2.50,   // liters per BCM
        'critical' => 4.00,  // liters per BCM
    ],
];
