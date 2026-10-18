<?php

/**
 * Handover-queue lines staff read in the thread (mirror of lang/ar/queue.php).
 */
return [
    'system' => [
        'no_reply_handoff' => 'Handed to another colleague because :agent did not reply within :minutes minutes',
    ],
    // Points ledger reasons (queue Part 2), rendered in the viewer's language from score_events.reason_key.
    'points' => [
        'base_inquiry' => 'Inquiry answered · ticket #:ticket',
        'base_problem' => 'Problem solved · ticket #:ticket',
        'base_case' => 'Opened a case · ticket #:ticket',
        'base_auto' => 'Closed on silence · ticket #:ticket',
        'base_escalation' => 'Escalated to the leader · ticket #:ticket',
        'speed_fast' => 'Fast first reply (:time) · ticket #:ticket',
        'speed_ok' => 'Good first reply (:time) · ticket #:ticket',
        'no_reply' => 'No reply, handed to a colleague · ticket #:ticket',
        'review' => 'Customer rating :stars of 5 · ticket #:ticket',
        'case_in_time' => 'Case #:case resolved in time',
        'case_late' => 'Case #:case resolved late',
        'case_reopened' => 'Case #:case reopened',
        'qa' => 'Quality check :score of 5 · ticket #:ticket',
        'qa_replaced' => 'Quality check changed · ticket #:ticket',
        'reversed' => 'Reversed',
        'undone' => 'Undone',
        'revived' => 'Window reopened',
        'capped' => '(over the cap :cap)',
    ],
    // The queue report's CSV header (queue Part 2).
    'report' => [
        'moderator' => 'Moderator',
        'received' => 'Received',
        'closed' => ['inquiry' => 'Inquiry', 'problem' => 'Problem', 'case' => 'Case', 'auto' => 'Auto', 'escalation' => 'Escalation', 'no_reply' => 'No reply', 'other' => 'Other'],
        'first_reply_avg' => 'Avg first reply (s)',
        'handle_avg' => 'Avg window time (s)',
        'sla_pct' => 'SLA %',
        'rating_avg' => 'Avg rating',
        'rating_count' => 'Ratings',
        'qa_avg' => 'Avg quality',
        'qa_count' => 'Quality checks',
        'cases_resolved' => 'Cases resolved',
        'points' => ['base' => 'Window points', 'speed' => 'Speed points', 'case' => 'Case points', 'review' => 'Rating points', 'qa' => 'Quality points', 'no_reply' => 'No-reply deductions'],
        'total' => 'Total points',
    ],
];
