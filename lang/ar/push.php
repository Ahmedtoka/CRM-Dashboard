<?php

// Push notifications to staff phones (mobile app spec §6). Customer first name only; never a phone number.
return [
    'customer' => 'عميلة',
    'queue' => [
        'assigned' => ['title' => 'شباك جديد #:ticket', 'body' => ':name — :platform'],
        'reply_overdue' => ['title' => 'العميلة مستنية ردك', 'body' => '#:ticket هيتحوّل لزميلة لو مردتيش'],
        'break_overrun' => ['title' => 'استراحة زادت عن وقتها', 'body' => ':name عدّت :minutes دقيقة استراحة'],
        'escalation_waiting' => ['title' => 'تصعيد مستني حد يستلمه', 'body' => '#:ticket — :name — :platform'],
        'reply_overdue_leader' => ['title' => 'رد متأخر في الصالة', 'body' => '#:ticket — :agent مردتش على :name'],
    ],
    'note' => [
        'mention' => ['title' => ':by عملتلك منشن', 'body' => ':excerpt'],
    ],
    'conversation' => [
        'handover' => ['title' => 'عميلة طالبة موظف', 'body' => ':name — :platform'],
        'handover_urgent' => ['title' => 'عميلة طالبة موظف — مستعجل', 'body' => ':name — :platform'],
    ],
    'case' => [
        'created' => ['title' => 'كيس جديد #:id', 'body' => ':type'],
    ],
    'test' => ['title' => 'تجربة إشعار', 'body' => 'الإشعارات شغالة على الموبايل ده'],
    'someone' => 'زميلة',
];
