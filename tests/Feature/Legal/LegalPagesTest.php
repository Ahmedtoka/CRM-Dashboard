<?php

use App\Models\DataDeletionRequest;
use App\Models\User;

beforeEach(function () {
    $this->withoutVite();
    config([
        'crm.legal.company_name' => 'Le Voile',
        'crm.legal.contact_email' => 'privacy@levoile.test',
        'crm.legal.retention_months' => 18,
    ]);
});

it('renders every legal page for guests in both languages', function (string $path, string $en, string $ar) {
    $this->get($path.'?lang=en')
        ->assertOk()
        ->assertSee('<html lang="en" dir="ltr">', false)
        ->assertSee($en)
        ->assertSee('Le Voile')
        ->assertSee('mailto:privacy@levoile.test', false)
        ->assertSee('/privacy?lang=en', false)
        ->assertSee('/terms?lang=en', false)
        ->assertSee('/data-deletion?lang=en', false);

    $this->get($path.'?lang=ar')
        ->assertOk()
        ->assertSee('<html lang="ar" dir="rtl">', false)
        ->assertSee($ar);
})->with([
    'privacy' => ['/privacy', 'Privacy policy', 'سياسة الخصوصية'],
    'terms' => ['/terms', 'Terms of use', 'شروط الاستخدام'],
    'data deletion' => ['/data-deletion', 'How to request deletion', 'كيف تطلب الحذف'],
]);

it('quotes the configured retention period and the AI provider in the privacy policy', function () {
    $this->get('/privacy?lang=en')
        ->assertOk()
        ->assertSee('for 18 months after the last message')
        ->assertSee('Anthropic')
        ->assertSee('our AI provider processes the content of your message');
});

it('falls back to the session locale and offers the other language', function () {
    $this->withSession(['locale' => 'en'])->get('/privacy')
        ->assertOk()
        ->assertSee('<html lang="en" dir="ltr">', false)
        ->assertSee('lang=ar', false);

    $this->withSession(['locale' => 'ar'])->get('/privacy')
        ->assertOk()
        ->assertSee('<html lang="ar" dir="rtl">', false);
});

it('is reachable for signed-in staff too', function () {
    $this->actingAs(User::factory()->create())->get('/terms?lang=en')->assertOk()->assertSee('Terms of use');
});

it('points people to the Facebook Page when no contact email is configured', function () {
    config(['crm.legal.contact_email' => null]);

    $this->get('/data-deletion?lang=en')
        ->assertOk()
        ->assertDontSee('mailto:', false)
        ->assertDontSee('Email us')
        ->assertSee('Message our Facebook Page');
});

it('shows the status for a confirmation code without any personal data', function (string $status, string $text) {
    DataDeletionRequest::create([
        'confirmation_code' => 'ABCDEF1234567890',
        'platform' => 'facebook',
        'external_user_id' => '987654321012345',
        'status' => $status,
        'requested_at' => now(),
        'completed_at' => $status === 'pending' ? null : now(),
    ]);

    $this->get('/data-deletion?lang=en&code=abcdef1234567890')
        ->assertOk()
        ->assertSee('data-status="'.$status.'"', false)
        ->assertSee($text)
        ->assertDontSee('987654321012345');
})->with([
    'pending' => ['pending', 'deletion is in progress'],
    'completed' => ['completed', 'has been deleted from our support system'],
    'not found' => ['not_found', 'so there was nothing to delete'],
]);

it('handles an unknown confirmation code', function () {
    $this->get('/data-deletion?lang=ar&code=NOPE')
        ->assertOk()
        ->assertSee('data-status="unknown"', false)
        ->assertSee('لم نجد طلبًا بهذا الكود');

    $this->get('/data-deletion?code='.str_repeat('A', 200))
        ->assertOk()
        ->assertSee('data-status="unknown"', false);
});

it('links the legal pages from the login screen', function () {
    expect(file_get_contents(resource_path('js/Layouts/auth/AuthSimpleLayout.vue')))
        ->toContain('/privacy?lang=')
        ->toContain('/terms?lang=');
});
