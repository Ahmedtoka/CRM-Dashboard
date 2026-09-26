<?php

/*
| User-facing failures: exception messages, aborts and inline validation that
| the dashboard shows as a toast or a field error. SetLocale has already set
| the requesting user's language, so __() here resolves in the language of the
| person who will read the response.
|
| Keep the key set identical to lang/ar/errors.php. Numbers are never baked
| into the copy — pass them as :parameters so each locale formats its own.
*/

return [

    'media' => [
        'unsupported' => 'This file type is not supported.',
        'too_big' => 'The file is larger than the limit (:max MB).',
        'platform_too_big' => 'The file is larger than the limit on :platform (:max MB).',
        'platform_type' => 'This file type is not supported on :platform.',
        'instagram_file' => 'Instagram does not accept documents — send a photo, a video or audio instead.',
        'whatsapp_voice_unsupported' => 'This voice format is not supported on WhatsApp.',
        'not_claimable' => 'A file is missing or has already been sent — upload it again.',
        'source_missing' => 'The original file no longer exists.',
        'copy_failed' => 'The attachment could not be copied.',
    ],

    'send' => [
        'no_cards' => 'This message has no cards to send.',
        'not_supported_on_whatsapp' => 'This kind of message is not supported on WhatsApp.',
        'media_attachment_missing' => 'The attachment is missing — upload it again.',
        'missing_waba_id' => 'No WhatsApp Business account on file — check the connection settings.',
        'graph_unreachable' => 'Could not reach Meta — please try again.',
        'facebook_not_connected' => 'No Facebook Page is connected.',
        'no_identity' => 'This customer has no identity on that platform.',
        'send_failed' => 'The message was not sent.',
    ],

    'inbox' => [
        'window_template_only' => 'The 24-hour reply window has closed; only an approved template can be sent (window mode: template_only).',
        'window_closed' => 'The reply window for this conversation is closed; the message cannot be sent (window mode: closed).',
        'window_other' => 'The message cannot be sent in the current reply window (window mode: :mode).',
        'retry_only_failed' => 'Only failed outbound messages can be retried.',
        'platform_not_allowed' => 'You are not allowed to reply on :platform.',
    ],

    'flows' => [
        'main_menu_must_stay_active' => 'The main menu has to stay active.',
        'draft_conflict' => 'The draft was changed somewhere else — load the latest version first.',
        'publish_has_errors' => 'The flow has errors that must be fixed before publishing.',
        'restore_has_errors' => 'This version has errors and cannot be restored.',
        'sandbox_failed' => 'Something went wrong while testing the flow — start again.',
    ],

    'knowledge' => [
        'core_entry_undeletable' => 'This is a core entry and cannot be deleted — you can switch it off instead.',
    ],

    'learning' => [
        'review_failed' => 'The review failed: :reason',
        'unknown_error' => 'Unknown error',
        'apply_failed' => 'Applying it failed: :reason',
        'suggestion_already_decided' => 'This suggestion has already been decided.',
    ],

    'channels' => [
        'instagram_not_linked' => 'This account is not linked to a Facebook page yet.',
        'instagram_subscribe_note' => 'The linked Facebook page was subscribed. Remember to enable the messages and comments fields once from App Dashboard → Webhooks → Instagram (details in README §6 or docs/deploy/cloudways-staging.md), otherwise Instagram messages and comments will not arrive.',
    ],

    'replies' => [
        'attachments_max' => 'A reply takes at most :max attachments.',
        'shortcut_taken' => 'That shortcut is already in use.',
    ],

    'shopify' => [
        'import_running' => 'An import is already running — wait for it to finish.',
        'no_integration' => 'No Shopify integration on file.',
        'orders_range_required' => 'Syncing orders needs a from and a to date.',
        'orders_range_max' => 'The longest sync range is one year (:days days).',
        'reconcile_range_max' => 'The longest reconciliation range is :days days at a time.',
    ],

    'bot' => [
        'ai_admin_only' => 'Only admins can change AI settings.',
        'burst_max_wait_attribute' => 'burst max wait seconds',
    ],

    'users' => [
        'cannot_remove_own_admin' => 'You cannot remove your own admin access.',
        'cannot_deactivate_self' => 'You cannot deactivate yourself.',
    ],

    'customers' => [
        'cannot_merge_into_itself' => 'A customer cannot be merged into itself.',
    ],

    'orders' => [
        'cancel_fulfilled' => 'An order that has shipped, fully or partly, cannot be cancelled.',
        'idempotency_conflict' => 'This order key is already used by another order — open the order form again.',
        'only_awaiting_payment_can_be_paid' => 'Only orders awaiting payment can be paid (status: :status).',
        'shipment_cannot_advance' => 'A :status shipment cannot be advanced.',
    ],

    'auth' => [
        'reset_link_sent' => 'A reset link will be sent if the account exists.',
        'account_inactive' => 'Your account is inactive.',
    ],

    'roles' => [
        'requires' => 'This action requires the :role role.',
        'names' => [
            'moderator' => 'moderator',
            'supervisor' => 'supervisor',
            'admin' => 'admin',
        ],
    ],

    'reports' => [
        'date_range_invalid' => 'The end date must be on or after the start date.',
    ],

    'bot_replies' => [
        'values_missing' => 'The new sentence must keep the numbers, names and links of the original',
    ],
];
