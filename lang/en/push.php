<?php

// Push notifications to staff phones (mobile app spec §6). Customer first name only; never a phone number.
return [
    'customer' => 'Customer',
    'queue' => [
        'assigned' => ['title' => 'New window #:ticket', 'body' => ':name — :platform'],
        'reply_overdue' => ['title' => 'The customer is waiting for your reply', 'body' => '#:ticket moves to a teammate if you do not reply'],
        'break_overrun' => ['title' => 'Break ran over', 'body' => ':name passed :minutes minutes of break'],
        'escalation_waiting' => ['title' => 'Escalation waiting for someone', 'body' => '#:ticket — :name — :platform'],
        'reply_overdue_leader' => ['title' => 'Overdue reply on the floor', 'body' => '#:ticket — :agent has not replied to :name'],
    ],
    'note' => [
        'mention' => ['title' => ':by mentioned you', 'body' => ':excerpt'],
    ],
    'conversation' => [
        'handover' => ['title' => 'A customer asked for an agent', 'body' => ':name — :platform'],
        'handover_urgent' => ['title' => 'A customer asked for an agent — urgent', 'body' => ':name — :platform'],
    ],
    'case' => [
        'created' => ['title' => 'New case #:id', 'body' => ':type'],
    ],
    'test' => ['title' => 'Test notification', 'body' => 'Notifications work on this phone'],
    'someone' => 'A teammate',
];
