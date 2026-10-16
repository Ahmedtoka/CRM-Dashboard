<?php

use App\Enums\Platform;
use App\Models\ChannelAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function confirmationWhatsAppAccount(array $attributes = []): ChannelAccount
{
    return ChannelAccount::factory()->create(array_merge([
        'platform' => Platform::WhatsApp,
        'driver' => 'live',
        'status' => 'connected',
        'external_id' => '1111',
        'profile' => ['waba_id' => '555'],
        'credentials' => ['access_token' => 'wa-token'],
    ], $attributes));
}

/** A message_templates listing as Meta returns it for ?name= (a partial match). */
function confirmationTemplateRows(array $rows): array
{
    return ['data' => array_map(fn ($r) => $r + ['language' => 'ar', 'category' => 'UTILITY', 'id' => 'tpl_'.$r['name']], $rows)];
}

it('shows each template status on the WABA', function () {
    confirmationWhatsAppAccount();
    fakeMetaGraph(['GET 555/message_templates' => fn (Request $r) => str_contains($r->url(), 'order_confirm_ar')
        ? confirmationTemplateRows([['name' => 'order_confirm_ar', 'status' => 'APPROVED'], ['name' => 'order_confirm_ar_old', 'status' => 'REJECTED']])
        : confirmationTemplateRows([]),
    ]);

    $this->artisan('whatsapp:confirmation-templates')
        ->expectsTable(['Template', 'Language', 'Status', 'Note'], [
            ['order_confirm_ar', 'ar', 'APPROVED', ''],
            ['order_reminder_ar', 'ar', 'MISSING', 'run with --submit to create it'],
        ])
        ->assertSuccessful();

    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
});

it('submits only the missing templates and never an approved one', function () {
    confirmationWhatsAppAccount();
    $posted = [];
    fakeMetaGraph([
        'GET 555/message_templates' => fn (Request $r) => str_contains($r->url(), 'order_confirm_ar')
            ? confirmationTemplateRows([['name' => 'order_confirm_ar', 'status' => 'APPROVED']])
            : confirmationTemplateRows([]),
        'POST 555/message_templates' => function (Request $r) use (&$posted) {
            $posted[] = $r->data();

            return ['id' => '9001', 'status' => 'PENDING', 'category' => 'UTILITY'];
        },
    ]);

    $this->artisan('whatsapp:confirmation-templates', ['--submit' => true])
        ->expectsOutputToContain('order_reminder_ar: submitted (id 9001, PENDING)')
        ->assertSuccessful();

    expect($posted)->toHaveCount(1)
        ->and($posted[0]['name'])->toBe('order_reminder_ar')
        ->and($posted[0]['category'])->toBe('UTILITY')
        ->and(collect($posted[0]['components'])->pluck('type')->all())->toBe(['BODY', 'FOOTER', 'BUTTONS']);
});

it('does not resubmit a rejected template and shows why', function () {
    confirmationWhatsAppAccount();
    fakeMetaGraph(['GET 555/message_templates' => confirmationTemplateRows([
        ['name' => 'order_confirm_ar', 'status' => 'REJECTED', 'rejected_reason' => 'INVALID_FORMAT'],
        ['name' => 'order_reminder_ar', 'status' => 'PENDING'],
    ])]);

    $this->artisan('whatsapp:confirmation-templates', ['--submit' => true])
        ->expectsTable(['Template', 'Language', 'Status', 'Note'], [
            ['order_confirm_ar', 'ar', 'REJECTED', 'INVALID_FORMAT: edit it in WhatsApp Manager'],
            ['order_reminder_ar', 'ar', 'PENDING', 'Meta is reviewing it'],
        ])
        ->expectsOutputToContain('Nothing to submit.')
        ->assertSuccessful();

    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
});

it('reports a failed submission', function () {
    confirmationWhatsAppAccount();
    fakeMetaGraph([
        'GET 555/message_templates' => confirmationTemplateRows([]),
        'POST 555/message_templates' => [['error' => ['message' => 'Invalid parameter', 'code' => 100]], 400],
    ]);

    $this->artisan('whatsapp:confirmation-templates', ['--submit' => true])
        ->expectsOutputToContain('order_confirm_ar: failed (Invalid parameter)')
        ->assertFailed();
});

it('uses the account given by --account', function () {
    confirmationWhatsAppAccount();
    $other = confirmationWhatsAppAccount(['external_id' => '2222', 'profile' => ['waba_id' => '777']]);
    fakeMetaGraph(['GET 777/message_templates' => confirmationTemplateRows([])]);

    $this->artisan('whatsapp:confirmation-templates', ['--account' => $other->id])->assertSuccessful();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '777/message_templates'));
});

it('fails clearly without a connected WhatsApp account or WABA id', function () {
    $this->artisan('whatsapp:confirmation-templates')
        ->expectsOutputToContain('No connected live WhatsApp account')
        ->assertFailed();

    confirmationWhatsAppAccount(['profile' => []]);

    $this->artisan('whatsapp:confirmation-templates')
        ->expectsOutputToContain('has no WhatsApp Business Account id')
        ->assertFailed();
});

it('fails when Meta refuses the status lookup', function () {
    confirmationWhatsAppAccount();
    fakeMetaGraph(['GET 555/message_templates' => [['error' => ['message' => 'Unsupported get request', 'code' => 100]], 400]]);

    $this->artisan('whatsapp:confirmation-templates')
        ->expectsOutputToContain('Unsupported get request')
        ->assertFailed();
});
