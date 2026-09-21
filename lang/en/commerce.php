<?php

/**
 * Order validation / permission messages thrown straight back to the staff
 * member who made the request (ValidationException, AuthorizationException),
 * so they resolve in that viewer's locale.
 *
 * Nothing that ends up in `Order.last_error`, in an activity log or in the
 * conversation thread belongs here — that content is persisted by a queue
 * worker running in the default locale.
 */
return [
    'order' => [
        'creation_disabled' => 'Creating orders from conversations is currently switched off in the Shopify settings.',
        'items_required' => 'At least one item is required.',
        'variant_not_found' => 'Variant :id was not found.',
        'retry_forbidden' => 'Only the order creator or a supervisor can retry it.',
        'retry_not_failed' => 'Only failed orders can be retried (status: :status).',
        'cancel_forbidden' => 'Only a supervisor or admin can cancel an order.',
    ],

    'discount' => [
        'supervisor_only' => 'Only a supervisor or admin can apply a discount.',
        'reason_required' => 'A discount reason is required.',
        'invalid_value' => 'The discount amount is not valid.',
        'invalid_type' => 'The discount type must be a fixed amount or a percentage.',
    ],

    'shipping' => [
        'address_not_customers' => 'The selected address does not belong to this customer.',
        'rate_unavailable' => 'The selected shipping method is not available for this governorate or order value.',
    ],
];
