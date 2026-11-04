<?php
return [
    // Invoice number shows as <prefix>/<financial-year>/<00001>, e.g. SWS/2026-27/00018.
    'prefix' => env('BILLING_PREFIX', 'SWS'),
    'estimate_prefix' => env('BILLING_ESTIMATE_PREFIX', 'EST'),
];
