<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Marketplace connector driver
    |--------------------------------------------------------------------------
    |
    | Use "mock" locally until the real marketplace integrations are ready.
    |
    */

    'driver' => env(
        'MARKETPLACE_CONNECTOR_DRIVER',
        'mock'
    ),
];