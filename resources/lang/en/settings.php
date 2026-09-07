<?php

/*
 * The suite's settings screen, Lead Magnets section.
 *
 * Key-identical to resources/lang/de/settings.php. Field keys are the config
 * path with the dots replaced (`delivery.link_ttl` → `delivery_link_ttl`),
 * because a dot in a translation key is a path separator.
 */

return [

    'groups' => [

        'delivery' => [
            'title' => 'Delivery',
            'description' => 'The defaults every resource follows unless it sets its own. Where the files live stays in the config file, because moving them has to happen before the setting changes.',
        ],

        'requests' => [
            'title' => 'Requests',
            'description' => 'What holds between the form being submitted and the address being confirmed. The request throttle and the route prefix stay in the config file: both are read while the routes are registered and would only take effect on the next deploy.',
        ],

        'mail' => [
            'title' => 'Mail',
            'description' => 'Which templates are taken from the email templates addon. Without that addon the shipped Blade views are used and these values do nothing.',
        ],

        'integrations' => [
            'title' => 'Sibling addons',
            'description' => 'Each switch covers an addon that is installed and should nevertheless not be written to. An addon that is not installed is off anyway.',
        ],

    ],

    'fields' => [

        'delivery_link_ttl' => [
            'label' => 'Download link lifetime (minutes)',
            'description' => 'How long a link that went out keeps working. Shorter means somebody opening the mail later has to ask again. Links already sent keep the lifetime they were signed with.',
        ],

        'delivery_max_downloads' => [
            'label' => 'Maximum downloads per grant',
            'description' => 'How often one grant may be redeemed. Empty means no cap, and the link lifetime is then the only limit.',
        ],

        'delivery_grant_ttl_days' => [
            'label' => 'Unredeemed grant expires after (days)',
            'description' => 'A grant nobody used expires after this many days. Empty means it stays.',
        ],

        'requests_confirmation_ttl_hours' => [
            'label' => 'Confirmation window (hours)',
            'description' => 'How long the confirmation link from the mail stays valid. After that it is dead and the visitor asks again.',
        ],

        'requests_honeypot' => [
            'label' => 'Honeypot field name',
            'description' => 'The field a human never fills and a bot always does. Change it and the request form has to carry the same name, or every real request is taken for a bot.',
        ],

        'mail_confirmation_template' => [
            'label' => 'Confirmation mail template',
            'description' => 'The slug of the template in the email templates addon. With no template under that slug the shipped view goes out.',
        ],

        'mail_delivery_template' => [
            'label' => 'Delivery mail template',
            'description' => 'The slug of the template carrying the download link. With no template under that slug the shipped view goes out.',
        ],

        'integrations_leadhub' => [
            'label' => 'Write to LeadHub',
            'description' => 'Off: a confirmed request no longer creates a contact, and the resource\'s tags are written nowhere.',
        ],

        'integrations_marketing' => [
            'label' => 'Write to Marketing',
            'description' => 'Off: the confirmed address is no longer subscribed to any list, even where a resource names one.',
        ],

        'integrations_email_templates' => [
            'label' => 'Use email templates',
            'description' => 'Off: confirmation and delivery go out with the shipped Blade views instead of the templates edited in the Control Panel.',
        ],

        'integrations_suppression' => [
            'label' => 'Honour the suppression list',
            'description' => 'Off: addresses that have bounced or complained are delivered to again.',
        ],

        'integrations_activity' => [
            'label' => 'Record activity',
            'description' => 'Off: request, confirmation, delivery and download stop reaching the shared timeline.',
        ],

        'integrations_insights' => [
            'label' => 'Offer the metrics',
            'description' => 'Off: this addon\'s four tiles are not on offer on the analytics dashboard. That is a different thing from showing a zero.',
        ],

    ],

];
