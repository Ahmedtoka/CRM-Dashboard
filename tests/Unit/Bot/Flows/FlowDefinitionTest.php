<?php

use App\Bot\Flows\FlowDefinition;
use App\Bot\Flows\FlowDefinitions;

it('validates every seeded flow definition with no errors', function () {
    foreach (FlowDefinitions::all() as $key => $flow) {
        expect(FlowDefinition::validate($flow['definition']))->toBe([]);
    }
});

it('rejects a next target that does not reference an existing step or end', function () {
    $def = [
        'start' => 'a',
        'steps' => [
            'a' => ['type' => 'text', 'field' => 'x', 'text' => 'hi', 'next' => 'missing_step'],
        ],
    ];

    expect(FlowDefinition::validate($def))->not->toBe([]);
});

it('rejects an unknown step type', function () {
    $def = [
        'start' => 'a',
        'steps' => [
            'a' => ['type' => 'not_a_real_type', 'next' => 'end'],
        ],
    ];

    expect(FlowDefinition::validate($def))->not->toBe([]);
});

it('rejects a title longer than 20 characters', function () {
    $def = [
        'start' => 'a',
        'steps' => [
            'a' => ['type' => 'menu', 'text' => 'hi', 'options' => [
                ['title' => 'a title that is definitely too long', 'action' => 'handover'],
            ]],
        ],
    ];

    expect(FlowDefinition::validate($def))->not->toBe([]);
});

it('rejects a choice step without a field', function () {
    $def = [
        'start' => 'a',
        'steps' => [
            'a' => ['type' => 'choice', 'text' => 'hi', 'options' => [
                ['value' => 'x', 'title' => 'X'],
            ], 'next' => 'end'],
        ],
    ];

    expect(FlowDefinition::validate($def))->not->toBe([]);
});

it('rejects a missing start step', function () {
    $def = [
        'start' => 'missing',
        'steps' => [
            'a' => ['type' => 'text', 'field' => 'x', 'text' => 'hi', 'next' => 'end'],
        ],
    ];

    expect(FlowDefinition::validate($def))->not->toBe([]);
});

it('rejects a branches[].next that does not reference an existing step or end', function () {
    $def = [
        'start' => 'a',
        'steps' => [
            'a' => ['type' => 'script', 'script' => 'x', 'branches' => [
                ['field' => 'reason', 'in' => ['defective'], 'next' => 'nowhere'],
            ], 'next' => 'end'],
        ],
    ];

    expect(FlowDefinition::validate($def))->not->toBe([]);
});

it('rejects a record_case step without a case_type', function () {
    $def = [
        'start' => 'a',
        'steps' => [
            'a' => ['type' => 'record_case', 'next' => 'end'],
        ],
    ];

    expect(FlowDefinition::validate($def))->not->toBe([]);
});

it('rejects a record_case step with an unknown case_type', function () {
    $def = [
        'start' => 'a',
        'steps' => [
            'a' => ['type' => 'record_case', 'case_type' => 'not_a_case_type', 'next' => 'end'],
        ],
    ];

    expect(FlowDefinition::validate($def))->not->toBe([]);
});

it('rejects a script step without a script key', function () {
    $def = [
        'start' => 'a',
        'steps' => [
            'a' => ['type' => 'script', 'next' => 'end'],
        ],
    ];

    expect(FlowDefinition::validate($def))->not->toBe([]);
});

it('rejects a menu step with more than 13 options', function () {
    $options = [];
    for ($i = 0; $i < 14; $i++) {
        $options[] = ['title' => 'opt'.$i, 'action' => 'handover'];
    }
    $def = [
        'start' => 'a',
        'steps' => [
            'a' => ['type' => 'menu', 'text' => 'hi', 'options' => $options],
        ],
    ];

    expect(FlowDefinition::validate($def))->not->toBe([]);
});

it('rejects a menu option without an action', function () {
    $def = [
        'start' => 'a',
        'steps' => [
            'a' => ['type' => 'menu', 'text' => 'hi', 'options' => [
                ['title' => 'X'],
            ]],
        ],
    ];

    expect(FlowDefinition::validate($def))->not->toBe([]);
});

it('accepts a status, branches_list, summary and end step with no field', function () {
    $def = [
        'start' => 'status',
        'steps' => [
            'status' => ['type' => 'status', 'next' => 'list'],
            'list' => ['type' => 'branches_list', 'text' => 'hi', 'next' => 'summary'],
            'summary' => ['type' => 'summary', 'text' => 'hi', 'next' => 'end'],
        ],
    ];

    expect(FlowDefinition::validate($def))->toBe([]);
});

it('accepts branches on any step type, including a script step', function () {
    $def = [
        'start' => 'a',
        'steps' => [
            'a' => ['type' => 'script', 'script' => 'x', 'branches' => [
                ['field' => 'reason', 'in' => ['defective'], 'next' => 'end'],
            ], 'next' => 'end'],
        ],
    ];

    expect(FlowDefinition::validate($def))->toBe([]);
});

it('accepts a layout for existing steps and rejects a layout for unknown steps or bad ids', function () {
    $def = ['start' => 'a', 'steps' => ['a' => ['type' => 'text', 'field' => 'x', 'text' => 't', 'next' => 'end']]];

    expect(FlowDefinition::validate($def + ['layout' => ['a' => ['x' => 10, 'y' => 20.5]]]))->toBe([])
        ->and(FlowDefinition::validate($def + ['layout' => ['zzz' => ['x' => 1, 'y' => 1]]]))->not->toBe([])
        ->and(FlowDefinition::validate(['start' => 'Bad Id', 'steps' => ['Bad Id' => ['type' => 'end']]]))->not->toBe([]);
});

it('warns about steps nothing leads to', function () {
    $def = ['start' => 'a', 'steps' => [
        'a' => ['type' => 'text', 'field' => 'x', 'text' => 't', 'next' => 'end'],
        'orphan' => ['type' => 'text', 'field' => 'y', 'text' => 't', 'next' => 'end'],
    ]];

    expect(FlowDefinition::warnings($def))->toBe(['الخطوة orphan مش متوصلة بأي خطوة قبلها']);
});
