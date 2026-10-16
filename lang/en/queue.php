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
        'capped' => '(over the cap :cap)',
    ],
];
