<?php

use App\Bot\Ai\SonnetBudget;
use App\Bot\ArabicNormalizer;
use App\Bot\OrderFactGuard;
use App\Bot\Router\EscalationPolicy;
use App\Bot\Router\FakeTurnPlanner;
use App\Bot\Router\TaskPlan;
use App\Models\Conversation;
use Illuminate\Support\Facades\Cache;

function pp(array $tasks, string $text, array $extra = []): TaskPlan
{
    $tasks = array_map(fn ($t) => $t + ['order_refs' => [], 'items' => [], 'confidence' => 0.9], $tasks);

    return TaskPlan::fromArray($extra + ['tasks' => $tasks], $text);
}

it('merges a return and an exchange of the same order into one mixed task (B4)', function () {
    $plan = pp([
        ['kind' => 'return', 'order_refs' => ['209129'], 'items' => [['text' => 'البلوزة']]],
        ['kind' => 'exchange', 'order_refs' => ['209129'], 'items' => [['text' => 'الجيبة', 'exchange_to' => 'مقاس اكبر']]],
    ], 'ارجع البلوزة وابدل الجيبة بمقاس اكبر من 209129');

    expect($plan->tasks)->toHaveCount(1)
        ->and($plan->tasks[0]['kind'])->toBe('mixed')
        ->and(array_column($plan->tasks[0]['items'], 'action'))->toBe(['return', 'exchange']);
});

it('splits one return for two orders into two tasks (B6), and drops numbers she never wrote', function () {
    $plan = pp([['kind' => 'return', 'order_refs' => ['209129', '209200', '123456']]], 'ارجع اوردر 209129 واوردر 209200');

    expect(array_column($plan->tasks, 'order_refs'))->toBe([['209129'], ['209200']]);
});

it('gives her order number to the one directed task the planner left without it', function () {
    $plan = pp([['kind' => 'cancel'], ['kind' => 'shipping_fee']], 'الغي الاوردر 209129 وبكام الشحن لاسكندرية');

    expect($plan->tasks[0]['order_refs'])->toBe(['209129'])
        ->and($plan->tasks[1]['order_refs'])->toBe([])
        ->and(array_column($plan->directed(), 'kind'))->toBe(['cancel'])
        ->and(array_column($plan->immediate(), 'kind'))->toBe(['shipping_fee']);
});

it('escalates per E1–E6 and hands over past four tasks, a request for a person, or anger with a threat', function () {
    $c = Conversation::factory()->create();
    $p = app(EscalationPolicy::class);

    expect($p->decide(pp([['kind' => 'shipping_fee']], 'الشحن بكام'), $c, 'الشحن بكام')['tier'])->toBe('1')
        ->and($p->decide(pp([['kind' => 'complaint'], ['kind' => 'order_status']], 'x'), $c, 'x')['reason'])->toBe('E1')
        ->and($p->decide(pp([['kind' => 'order_status', 'order_refs' => ['209129']]], '209129', ['sentiment' => 'angry']), $c, 'اوردر 209129 اتأخر!!')['reason'])->toBe('E2')
        ->and($p->decide(pp([['kind' => 'return']], 'x', ['policy_edge' => true]), $c, 'x')['reason'])->toBe('E3')
        ->and($p->decide(pp([['kind' => 'other', 'confidence' => 0.4]], 'x'), $c, 'x')['reason'])->toBe('E4')
        ->and($p->decide(pp(array_fill(0, 5, ['kind' => 'product']), 'x'), $c, 'x'))->toMatchArray(['tier' => 'handover', 'reason' => 'too_many_tasks'])
        ->and($p->decide(pp([['kind' => 'human']], 'x', ['wants_human' => true]), $c, 'x')['tier'])->toBe('handover')
        ->and($p->decide(pp([['kind' => 'complaint']], 'x', ['sentiment' => 'angry']), $c, 'هعمل بوست فضيحة'))->toMatchArray(['tier' => 'handover', 'priority' => 'high']);

    // E6: the same question again.
    $plan = pp([['kind' => 'product']], 'x');
    $p->remember($c, $plan, 'عندكم بوركيني؟');
    expect($p->decide($plan, $c->refresh(), 'عندكم بوركيني؟')['reason'])->toBe('E6');
});

it('keeps tier 2 on Haiku when the Sonnet cap is used up (B3)', function () {
    Cache::put('bot:sonnet-spend:'.now('Africa/Cairo')->toDateString(), 5_000_000, now()->addDay());

    expect(app(SonnetBudget::class)->exceeded())->toBeTrue()
        ->and(app(EscalationPolicy::class)->decide(pp([['kind' => 'return']], 'x', ['policy_edge' => true]), Conversation::factory()->create(), 'x'))
        ->toMatchArray(['tier' => '1', 'reason' => 'E3:budget']);
});

it('counts Sonnet spend per Cairo day at the real price', function () {
    $b = app(SonnetBudget::class);
    $b->charge('claude-sonnet-5-5', ['input_tokens' => 1_000_000, 'output_tokens' => 100_000]);
    $b->charge('claude-haiku-5-5', ['input_tokens' => 1_000_000]);

    expect($b->spentToday())->toBe(3.0)->and($b->exceeded())->toBeFalse();
});

it('rejects an order number, a date or a status the tools did not give (OrderFactGuard)', function () {
    $g = new OrderFactGuard(new ArabicNormalizer);
    $evidence = ['الأوردر رقم #209129 اتشحن يوم 12/10 وهيوصل خلال يومين'];

    expect($g->isSafe('أوردرك #209129 اتشحن يوم 12/10', $evidence, [], ['get_order_status']))->toBeTrue()
        ->and($g->isSafe('أوردرك #209130 اتشحن', $evidence, [], ['get_order_status']))->toBeFalse()
        ->and($g->isSafe('هيوصلك يوم 15/10', $evidence, [], ['get_order_status']))->toBeFalse()
        ->and($g->isSafe('هيوصلك 20 أكتوبر', $evidence, [], ['get_order_status']))->toBeFalse()
        ->and($g->isSafe('الأوردر اتشحن خلاص', [], [], []))->toBeFalse()
        ->and($g->isSafe('ابعتيلي رقم الأوردر 209129 تاني', [], ['اوردر 209129 فين'], []))->toBeTrue();
});

it('plans without a model from keywords and her own order numbers', function () {
    $plan = app(FakeTurnPlanner::class)->plan([], ['الغي الاوردر 209129 وبكام الشحن لاسكندرية'], ['flow' => null, 'step' => null, 'last_question' => null]);

    expect($plan->kinds())->toContain('cancel', 'shipping_fee')
        ->and($plan->directed()[0]['order_refs'])->toBe(['209129'])
        ->and($plan->source)->toBe('keywords');

    $mixed = app(FakeTurnPlanner::class)->plan([], ['عايزة ارجع البلوزة وابدل الجيبة بمقاس اكبر'], ['flow' => null, 'step' => null, 'last_question' => null]);
    expect($mixed->tasks[0]['kind'])->toBe('mixed');
});
