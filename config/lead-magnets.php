<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public routes
    |--------------------------------------------------------------------------
    |
    | The prefix the request, confirmation and download routes are mounted
    | under. `!` is the Statamic convention for a route that is not a page.
    |
    */

    'routes' => [
        'prefix' => '!/lead-magnets',
    ],

    /*
    |--------------------------------------------------------------------------
    | Entitlements
    |--------------------------------------------------------------------------
    |
    | Access state lives in goldnead/statamic-entitlements. These two values say
    | how this addon's grants appear there, and both are install-time settings:
    | changing either one after grants exist orphans every entitlement written
    | under the old value, because both are part of that table's unique key.
    |
    | `source` is what marks an entitlement as this addon's. The delivery
    | listener and the resource-deletion sweep both filter on it, so a purchase
    | recorded by a payment webhook against the same product slug is left alone.
    |
    | `subject_type` is the morph type a lead-magnet contact is stored under. A
    | host application that already models contacts and wants its own grants and
    | these to land on the same subject points this at its own morph alias.
    |
    */

    'entitlements' => [
        'source' => 'lead_magnet',
        'subject_type' => 'lead-magnet-contact',
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    |
    | `link_ttl` is how long a signed download link stays valid, in minutes. A
    | resource may shorten or lengthen it for itself; this is the fallback.
    |
    | `max_downloads` caps how often one grant may be redeemed. `null` means no
    | cap — the signature expiry is then the only limit.
    |
    | `grant_ttl_days` expires an unredeemed grant. `null` keeps it forever.
    |
    */

    'delivery' => [
        'link_ttl' => 60 * 24 * 7,
        'max_downloads' => null,
        'grant_ttl_days' => null,
        'disk' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Requests
    |--------------------------------------------------------------------------
    |
    | `honeypot` names a form field that a human never fills and a bot always
    | does. A filled honeypot gets a believable success and nothing else.
    |
    | `confirmation_ttl_hours` is how long an unconfirmed request may be
    | confirmed for. After that the token is dead and the visitor asks again.
    |
    */

    'requests' => [
        'honeypot' => 'website',
        'confirmation_ttl_hours' => 72,
        'throttle' => '10,1',
    ],

    /*
    |--------------------------------------------------------------------------
    | Mail
    |--------------------------------------------------------------------------
    |
    | Slugs handed to goldnead/statamic-email-templates when it is installed.
    | Without that addon the Blade views in resources/views/mail are used, and
    | they are publishable, so a site never depends on the sibling to change
    | the wording.
    |
    */

    'mail' => [
        'confirmation_template' => 'lead-magnet-confirmation',
        'delivery_template' => 'lead-magnet-delivery',
    ],

    /*
    |--------------------------------------------------------------------------
    | Optional sibling addons
    |--------------------------------------------------------------------------
    |
    | Every bridge is off the moment its addon is absent. These switches exist
    | to turn one off while it is present — an installed addon that should not
    | be written to.
    |
    */

    'integrations' => [
        'leadhub' => true,
        'marketing' => true,
        'email_templates' => true,
        'suppression' => true,
        'activity' => true,
    ],

];
