<?php

use App\Bot\ArabicNormalizer;
use App\Bot\Flows\EntityExtractor;
use App\Bot\Flows\Returns\ItemSelection;
use App\Bot\Flows\Returns\ReturnPrefill;

function sel(): ItemSelection
{
    return new ItemSelection(new ArabicNormalizer);
}

const PIECES = [11 => 'فستان ستان اسود M', 12 => 'طرحة شيفون بيج', 13 => 'بلوزة كتان', 14 => 'جيبة بليسيه'];

it('reads every order number in her message, not only the first (R2)', function () {
    expect(EntityExtractor::orderRefs('عايزة ارجع اوردر 209129 واوردر ٢٠٩٢٠٠ الاتنين'))->toBe(['209129', '209200'])
        ->and(EntityExtractor::orderRefs('#209129 و 209129 تاني'))->toBe(['209129'])
        ->and(EntityExtractor::orderRefs('رقمي 01012345678 والاوردر 209129'))->toBe(['209129'])
        ->and(EntityExtractor::orderRef('اوردر 209129 واوردر 209200'))->toBe('209129');
});

it('matches every piece she names, split on و / ، / and (R3)', function () {
    expect(sel()->matchTitles('الفستان الاسود والطرحة', PIECES))->toBe(['matched' => [11, 12], 'unmatched' => []])
        ->and(sel()->matchTitles('البلوزة، الجيبة', PIECES))->toBe(['matched' => [13, 14], 'unmatched' => []])
        ->and(sel()->matchTitles('الطرحة والشنطة', PIECES))->toBe(['matched' => [12], 'unmatched' => ['الشنطة']]);
});

it('reads «2 قطع» as a count, never as piece number 2 (R7)', function () {
    expect(sel()->positions('عايزة ارجع 2 قطع من الاوردر', 4))->toBeNull()
        ->and(sel()->positions('٣ حتت', 4))->toBeNull()
        ->and(sel()->positions('2', 4))->toBe([2])
        ->and(sel()->positions('1 و 3', 4))->toBe([1, 3]);
});

it('fills the return flow from the message that started it (R1)', function () {
    $p = ReturnPrefill::fromText('عايزة ارجع الفستان الاسود والطرحة من اوردر 209129');

    expect($p['order_refs'])->toBe(['209129'])
        ->and($p['request_kind'])->toBe('return')
        ->and($p['item_hints'])->toBe([['text' => 'الفستان الاسود والطرحة', 'action' => 'return', 'exchange_to' => null]]);
});

it('gives each piece its own action when she returns one and exchanges another (R2/R4)', function () {
    $p = ReturnPrefill::fromText('عايزة ارجع البلوزة وابدل الجيبة بمقاس اكبر');

    expect($p['request_kind'])->toBe('mixed')
        ->and($p['item_hints'])->toBe([
            ['text' => 'البلوزة', 'action' => 'return', 'exchange_to' => null],
            ['text' => 'الجيبة', 'action' => 'exchange', 'exchange_to' => 'مقاس اكبر'],
        ]);
});

it('keeps both pieces of one exchange and the size they want', function () {
    $p = ReturnPrefill::fromText('عايزة ابدل الجيبة والبلوزة بمقاس M الاتنين');

    expect($p['request_kind'])->toBe('exchange')
        ->and($p['item_hints'])->toBe([['text' => 'الجيبة والبلوزة', 'action' => 'exchange', 'exchange_to' => 'مقاس M']]);
});

it('knows a return written in Franco and says nothing it did not read', function () {
    expect(ReturnPrefill::fromText('3ayza arga3 el fostan')['request_kind'])->toBe('return')
        ->and(ReturnPrefill::fromText('عايزة ارجع اوردر 209129 واوردر 209200'))->toBe(['order_refs' => ['209129', '209200'], 'request_kind' => 'return', 'item_hints' => []])
        ->and(ReturnPrefill::fromText('ممكن اعرف الاوردر فين')['request_kind'])->toBeNull();
});

it('turns a prefill into the first flow data and the orders still waiting', function () {
    $p = ReturnPrefill::fromText('عايزة ارجع اوردر 209129 واوردر 209200');

    expect(ReturnPrefill::flowData($p, 0))->toBe(['order_ref_hint' => '209129', 'request_kind' => 'return', 'request_kind_title' => 'إرجاع'])
        ->and(ReturnPrefill::flowData($p, 1))->toBe(['order_ref_hint' => '209200', 'request_kind' => 'return', 'request_kind_title' => 'إرجاع'])
        ->and(ReturnPrefill::flowData(ReturnPrefill::fromText('عايزة ارجع حاجة'), 0))->toBe(['request_kind' => 'return', 'request_kind_title' => 'إرجاع']);
});
