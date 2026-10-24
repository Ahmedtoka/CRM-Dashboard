<?php

use App\Inbox\Outcomes\EpisodeEnd;
use App\Inbox\Outcomes\Outcome;

// finish-api.md §1 (2026-10-24): one pick that says what happened; the old keys stay readable.

it('has the contract outcomes: the three groups, then the legacy service and unknown', function () {
    expect(array_map(fn (Outcome $o) => $o->value, Outcome::cases()))->toBe([
        'ordered', 'price', 'size_out', 'shipping', 'browsing',
        'order_inquiry', 'return_case', 'exchange_case', 'complaint_case', 'order_change',
        'no_answer', 'spam', 'other', 'service', 'unknown',
    ]);
});

it('lets a person pick every group in sheet order, never service or unknown', function () {
    expect(Outcome::agentValues())->toBe([
        'ordered', 'price', 'size_out', 'shipping', 'browsing',
        'order_inquiry', 'return_case', 'exchange_case', 'complaint_case', 'order_change',
        'no_answer', 'spam', 'other',
    ])->and(Outcome::acceptedValues())->toContain('service')->not->toContain('unknown');
});

it('keeps the why-not-bought reasons the ads funnel reads', function () {
    expect(Outcome::lostValues())->toBe(['price', 'size_out', 'shipping', 'browsing', 'no_answer', 'other'])
        ->and(Outcome::ReturnCase->isLostSale())->toBeFalse()
        ->and(Outcome::Spam->isLostSale())->toBeFalse()
        ->and(Outcome::Ordered->isLostSale())->toBeFalse();
});

it('knows which outcomes are service chats and which record a case', function () {
    expect(Outcome::caseValues())->toBe(['return_case', 'exchange_case', 'complaint_case', 'order_change'])
        ->and(Outcome::ReturnCase->caseType())->toBe('return')
        ->and(Outcome::ExchangeCase->caseType())->toBe('exchange')
        ->and(Outcome::ComplaintCase->caseType())->toBe('complaint')
        ->and(Outcome::OrderChange->caseType())->toBe('cancel_edit')
        ->and(Outcome::OrderInquiry->caseType())->toBeNull()
        ->and(Outcome::OrderInquiry->isService())->toBeTrue()
        ->and(Outcome::Service->isService())->toBeTrue()
        ->and(Outcome::Price->isService())->toBeFalse();
});

it('maps a case type and a handover category to the outcome they suggest', function () {
    expect(Outcome::fromCaseType('return_exchange'))->toBe(Outcome::ReturnCase)
        ->and(Outcome::fromCaseType('delivery_followup'))->toBe(Outcome::OrderInquiry)
        ->and(Outcome::fromCaseType('nope'))->toBeNull()
        ->and(Outcome::fromHandoverCategory('complaint'))->toBe(Outcome::ComplaintCase)
        ->and(Outcome::fromHandoverCategory('edit_order'))->toBe(Outcome::OrderChange)
        ->and(Outcome::fromHandoverCategory('order_status'))->toBe(Outcome::OrderInquiry)
        ->and(Outcome::fromHandoverCategory('sizes'))->toBeNull()
        ->and(Outcome::fromHandoverCategory(null))->toBeNull();
});

it('reads the installed app\'s legacy service as the case outcome of its type, else an order inquiry', function () {
    expect(Outcome::fromRequest('service', 'complaint'))->toBe(Outcome::ComplaintCase)
        ->and(Outcome::fromRequest('service'))->toBe(Outcome::OrderInquiry)
        ->and(Outcome::fromRequest('return_case'))->toBe(Outcome::ReturnCase)
        ->and(Outcome::fromRequest('price', 'complaint'))->toBe(Outcome::Price)
        ->and(Outcome::fromRequest('bogus'))->toBeNull()
        ->and(Outcome::fromRequest(null))->toBeNull();
});

it('says what each outcome sends at the close', function () {
    expect(Outcome::NoAnswer->sendsClosing())->toBeFalse()->and(Outcome::NoAnswer->skipReason())->toBe('no_reply')->and(Outcome::NoAnswer->endsWithBot())->toBeFalse()
        ->and(Outcome::Spam->sendsClosing())->toBeFalse()->and(Outcome::Spam->skipReason())->toBe('spam')->and(Outcome::Spam->endsWithBot())->toBeTrue()
        ->and(Outcome::ReturnCase->sendsClosing())->toBeTrue()->and(Outcome::Ordered->skipReason())->toBeNull();
});

it('labels every outcome in both languages', function (string $locale) {
    app()->setLocale($locale);
    foreach (Outcome::cases() as $o) {
        expect($o->label())->not->toBe('labels.outcomes.'.$o->value);
    }
})->with(['ar', 'en']);

it('names the five episode ends', function () {
    expect(array_map(fn (EpisodeEnd $e) => $e->value, EpisodeEnd::cases()))
        ->toBe(['close', 'auto_close', 'resolve', 'api_resolve', 'idle']);
});
