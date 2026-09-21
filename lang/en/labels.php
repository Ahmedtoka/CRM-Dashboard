<?php

/**
 * Display labels resolved at READ time (SetLocale has already switched the app
 * locale to the viewer's). Never store the result of one of these in a column —
 * that would freeze the writer's locale. Anything persisted keeps a stable code
 * and is translated through here when it is rendered.
 */
return [
    // Egyptian pound, as staff write it.
    'currency' => 'EGP',

    'shipping' => [
        // ShippingQuote's fallback option when no Shopify zone rate matches.
        'default' => 'Shipping',
    ],

    'test_session' => [
        // BotTestSession::label(); the first run shows the name on its own.
        'run' => ':name — run :n',
    ],

    // DeviceFamily::label(): only the non-brand values. iPhone/iPad/Android/Mac/
    // Windows/Linux are brand words and stay as they are in both locales.
    'device' => [
        'unknown' => 'Unknown',
        'android_tablet' => 'Android tablet',
    ],

    // ConnectionHealthCheck::problemText(): the stored `problem:<code>` in
    // ChannelAccount.last_error. An unrecognised value (a raw Graph/Shopify
    // message, or a row written before the codes existed) renders unchanged.
    'channel_problem' => [
        'token_missing' => 'No token saved — reconnect the account',
        'token_invalid' => 'The token is no longer valid — reconnect the account',
        'missing_scopes' => 'The token is missing permissions',
        'not_subscribed' => 'The webhook is not subscribed — messages will not arrive',
        'missing_fields' => 'The webhook is subscribed without the messages field',
        'instagram_unlinked' => 'The Instagram account was unlinked from the Facebook Page',
        'instagram_changed' => 'The Facebook Page is now linked to a different Instagram account',
        'facebook_disconnected' => 'The Facebook Page that Instagram uses is not connected',
    ],

    // ShipmentEvent.description values the CRM itself writes (carrier text is
    // passed through untouched). Keyed by the stored sentinel in OrderResource.
    'shipment_event' => [
        'created' => 'Shipment created',
        'order_cancelled' => 'Order cancelled',
    ],

    'csv' => [
        'yes' => 'yes',
        'no' => 'no',

        'test_links' => [
            'link' => 'link',
            'tester' => 'tester',
            'run' => 'run',
            'started_at' => 'started_at',
            'duration_seconds' => 'duration_seconds',
            'messages_in' => 'messages_in',
            'messages_out' => 'messages_out',
            'device' => 'device',
            'flows' => 'flows',
            'last_step' => 'last_step',
            'finished' => 'finished',
            'cases' => 'cases',
            'handovers' => 'handovers',
            'conversation_id' => 'conversation_id',
        ],

        'quick_replies' => [
            'shortcut' => 'shortcut',
            'title' => 'title',
            'scope' => 'scope',
            'category' => 'category',
            'uses' => 'uses',
            'users' => 'users',
            'platforms' => 'platforms',
            'last_used_at' => 'last_used_at',
        ],
    ],
];
