<?php

/**
 * Labels for the figures this addon offers `statamic-insights`.
 */
return [

    'metric_group' => 'Lead magnets',

    'metric_requested' => 'Requested',
    'metric_requested_description' => 'Freebies last requested in the period. A second request from the same address for the same resource replaces the first; there is one row per address and resource.',

    'metric_confirmed' => 'Confirmed',
    'metric_confirmed_description' => 'Confirmations in the period, counted at the moment access opened. Not every confirmation belongs to a request from the same period.',

    'metric_downloads' => 'Downloads',
    'metric_downloads_description' => 'Files handed over in the period. Somebody who downloads twice counts twice.',

    'metric_confirm_rate' => 'Confirmation rate',
    'metric_confirm_rate_description' => 'Confirmations against the period\'s requests. With no requests there is no value.',

    'metric_breakdown_resource' => 'Resource',

    'metric_no_resource' => 'No resource',

];
