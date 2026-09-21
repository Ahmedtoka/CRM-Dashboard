<?php

/*
 * Case summaries, alerts and handover labels (the "cases & handover" cluster).
 *
 * Namespaces:
 *   cases.types.*        SupportCase::typeLabel()
 *   cases.priority.*     case priority + handover priority
 *   cases.header         the summary header line
 *   cases.sections.*     the section titles
 *   cases.customer.*     the customer block
 *   cases.order.*        the order block
 *   cases.items.*        the picked pieces
 *   cases.fields.*       "label: value" field labels in the request block
 *   cases.exchange.*     the replacement product lines and the exchange note
 *   cases.edit.*         the cancel/edit note
 *   cases.attachments.*  the attachments block
 *   cases.team_action.*  what the team should do
 *   cases.policy.*       stored policy-note codes (see CaseRecorder)
 *   cases.handover.*     the bot handover reason/category labels
 */

return [
    'currency' => 'EGP',

    'types' => [
        'return_exchange' => 'Return / exchange',
        'return' => 'Return',
        'exchange' => 'Exchange',
        'complaint' => 'Complaint',
        'cancel_edit' => 'Cancel / edit order',
        'delivery_followup' => 'Delivery follow-up',
    ],

    'priority' => [
        'low' => 'low',
        'medium' => 'medium',
        'high' => 'high',
    ],

    'header' => '📋 Case #:id — :type — :priority priority',

    'sections' => [
        'customer' => 'Customer',
        'order' => 'Order',
        'items' => 'Items requested',
        'request' => 'Request',
        'attachments' => 'Attachments',
        'alerts' => 'Alerts',
        'team_action' => 'Team action',
    ],

    'no_alerts' => 'None',

    'customer' => [
        'unknown' => 'Unknown',
    ],

    'order' => [
        'numbered' => 'Order #:number',
        'not_found' => 'Order: :ref (not found in the system)',
        'placed_on' => 'Placed :date',
        'products' => 'Items: :list',
        'separator' => ', ',
        'more_one' => 'and 1 more item',
        'more_many' => 'and :count more items',
    ],

    'items' => [
        'exchange_only' => '(exchange only)',
    ],

    'fields' => [
        'kind' => 'Request',
        'reason' => 'Reason',
        'request' => 'Wants',
        'complaint_type' => 'Type',
        'branch' => 'Branch',
        'visit_date' => 'Visit date',
        'description' => 'Details',
        'cancel_reason' => 'Reason for cancelling',
        'edit_kind' => 'Kind of change',
        'new_address' => 'New address',
        'new_phone' => 'New phone number',
        'edit_details' => 'Change',
        'delivery_status' => 'Shipping status',
    ],

    'exchange' => [
        'replacement' => 'Replacement',
        'link' => 'Link',
        'typed' => 'Replacement: not identified — the customer wrote «:text»',
        'photo' => 'Replacement: the customer sent a photo of the product',
        'unknown' => 'Replacement: not identified',
        'note_item' => 'the item',
        'note' => 'Exchange request: :items → :product',
        'note_typed' => 'Exchange request: :items → product not identified, the customer wrote: «:text»',
        'note_photo' => 'Exchange request: :items → the customer sent a photo of the replacement (see the photos)',
        'note_unknown' => 'Exchange request: :items → replacement not identified',
    ],

    'edit' => [
        'new_address' => '📍 New address: :address',
        'new_phone' => '📞 New phone number: :phone',
        'note_header' => '✏️ Changes requested on :order',
        'note_order_numbered' => 'order #:number',
        'note_order_any' => 'the order',
    ],

    'attachments' => [
        'item_photo' => 'Photo of the item',
        'no_photo' => '— (no photo sent)',
        'replacement_photo' => 'Photo of the replacement',
        'change_photo' => 'Photo of the replacement',
        'customer_photos' => 'Photos from the customer',
        'product_photo' => 'Product photo',
        'defect_photo' => 'Photo of the defect',
    ],

    'team_action' => [
        'return' => 'Check the item, book the courier to collect the return and tell the customer when.',
        'exchange' => 'Confirm the replacement and her size are in stock, then call her to agree the exchange and ship it.',
        'return_exchange' => [
            'refund' => 'Call the customer, arrange collection of the item and refund her.',
            'exchange' => 'Call the customer and arrange the exchange.',
            'default' => 'Call the customer and review the return request.',
        ],
        'complaint' => 'Call the customer, follow the complaint through and resolve it.',
        'cancel_edit' => [
            'editable' => [
                'cancel' => 'Cancel the order before it ships and confirm the cancellation with the customer.',
                'edit' => 'Apply the changes to the order before it ships and confirm them with the customer.',
                'default' => 'Review the order and action her request before it ships.',
            ],
            'window' => [
                'cancel' => 'Review the order and cancel it if it is still within the window.',
                'edit' => 'Review the order and apply the change if it is still within the window.',
                'default' => 'Review the order and action her request if it is still within the window.',
            ],
        ],
        'delivery_followup' => 'Chase the shipment with the courier and get back to the customer.',
        'default' => 'Review the case and contact the customer.',
    ],

    'policy' => [
        'cancel_window_open' => 'The order had not shipped when she asked — check before it leaves the warehouse.',
        'cancel_window_left' => ':minutes minutes left to cancel or change the order',
        'cancel_window_over' => 'The window to cancel or change the order has closed',
    ],

    'handover' => [
        'categories' => [
            'unclear' => 'Unclear',
            'repeated' => 'Repeated question',
            'angry_or_urgent' => 'Angry / urgent',
            'no_script' => 'No saved reply',
            'ai_error' => 'Bot error',
            'window_closed' => 'Reply window closed',
            'order_not_found' => 'Order not found',
            'delayed_order' => 'Order delayed',
            'order_hold' => 'Order on hold for review',
            'order_returned' => 'Order returned',
            'failed_delivery_attempt' => 'Failed delivery attempt',
            'order_details_missing' => 'Order details missing',
            'new_order' => 'New order request',
            'order_verification_failed' => 'Could not verify the order is hers',
        ],
        'reasons' => [
            'purchase' => 'Customer wants to order',
            'contact_details' => 'Customer sent an address or phone number',
            'size_recommendation' => 'Customer is asking about her size',
            'complaint' => 'Complaint',
            'negative_sentiment' => 'Customer is upset',
            'order_status' => 'Question about an existing order',
            'ai_low_confidence' => 'Bot was unsure of the reply',
            'keyword' => 'Asked to speak to a person',
            'max_turns' => 'Bot kept replying without resolving it',
            'ai_guard' => 'Reply contained figures that are not in the data',
            'ai_error' => 'Bot error',
            'window_closed' => 'Reply window closed',
            'no_rule' => 'No suitable reply',
            'rule' => 'Handover rule',
            'no_product_match' => 'Product not found in the catalogue',
            'intent' => 'Customer request',
        ],
    ],
];
