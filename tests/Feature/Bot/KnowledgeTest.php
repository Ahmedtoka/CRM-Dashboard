<?php

use App\Bot\Knowledge\KnowledgeBase;
use App\Bot\Knowledge\SizeChart;
use App\Enums\UserRole;
use App\Media\SampleMedia;
use App\Models\BotKnowledgeEntry;
use App\Models\BotSetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('seeds the real Le Voile knowledge entries, no longer marked as samples', function () {
    // The Le Voile owner scripts (`script.*`, seeded separately, not templates) are excluded here.
    $defaults = BotKnowledgeEntry::where('key', 'not like', 'script.%');

    expect((clone $defaults)->orderBy('sort')->pluck('key')->all())
        ->toBe(['store_intro', 'working_hours_text', 'payment_methods', 'shipping_times', 'exchange_policy', 'return_policy', 'fabric_care', 'agent_instructions'])
        ->and((clone $defaults)->where('is_template', true)->count())->toBe(0)
        ->and(BotKnowledgeEntry::where('key', 'exchange_policy')->value('body'))->toContain('14 يوم');
});

it('clears the template flag when an entry is edited and hides inactive entries from the bot', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $entry = BotKnowledgeEntry::where('key', 'payment_methods')->first();

    $this->actingAs($sup)->putJson("/settings/bot-knowledge/entries/{$entry->id}", ['body' => 'الدفع كاش عند الاستلام بس'])->assertOk()
        ->assertJsonPath('data.is_template', false);
    $this->actingAs($sup)->putJson("/settings/bot-knowledge/entries/{$entry->id}", ['is_active' => false])->assertOk();

    expect(app(KnowledgeBase::class)->get('payment_methods'))->toBeNull();
    $this->actingAs($sup)->deleteJson("/settings/bot-knowledge/entries/{$entry->id}")->assertStatus(422);

    $custom = $this->actingAs($sup)->postJson('/settings/bot-knowledge/entries', ['key' => 'eid_offer', 'title' => 'عرض العيد', 'body' => 'خصم 10%'])->assertCreated()->json('data.id');
    $this->actingAs($sup)->deleteJson("/settings/bot-knowledge/entries/{$custom}")->assertOk();
});

it('stores a validated size chart and renders it as text', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    expect(SizeChart::fromSettings(BotSetting::current())->toText())->toContain('S: الصدر 86-90، الوسط 66-70، الهيب 92-96، الوزن التقريبي 50-57 كجم');

    $this->actingAs($sup)->putJson('/settings/bot-knowledge/size-chart', ['unit' => 'cm', 'columns' => ['المقاس', 'الصدر'], 'rows' => [['S', '86-90', 'extra']], 'note' => null])
        ->assertStatus(422)->assertJsonValidationErrors('rows.0');
    $this->actingAs($sup)->putJson('/settings/bot-knowledge/size-chart', ['unit' => 'cm', 'columns' => ['المقاس', 'الصدر'], 'rows' => [['S', '86-90'], ['M', '90-94']], 'note' => 'تقريبي'])
        ->assertOk();

    expect(SizeChart::fromSettings(BotSetting::current())->toText())->toBe("جدول المقاسات (cm):\nS: الصدر 86-90\nM: الصدر 90-94\nتقريبي");
});

it('uploads and serves the size chart image', function () {
    Storage::fake('media');
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $png = tempnam(sys_get_temp_dir(), 'crm');
    file_put_contents($png, SampleMedia::bytes('image'));

    $this->actingAs($sup)->post('/settings/bot-knowledge/size-chart/image', ['file' => new UploadedFile($png, 'chart.png', 'image/png', null, true)], ['Accept' => 'application/json'])->assertOk();
    expect(SizeChart::fromSettings(BotSetting::current())->hasImage())->toBeTrue();
    $this->actingAs(User::factory()->create())->get('/bot/size-chart-image')->assertOk()->assertHeader('Content-Type', 'image/png');
});

it('is supervisor only', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Moderator]))->get('/settings/bot-knowledge')->assertForbidden();
});

it('forbids a moderator from mutating knowledge entries or the size chart', function () {
    $mod = User::factory()->create(['role' => UserRole::Moderator]);

    $this->actingAs($mod)->postJson('/settings/bot-knowledge/entries', ['key' => 'eid_offer', 'title' => 'عرض العيد', 'body' => 'خصم 10%'])->assertForbidden();
    $this->actingAs($mod)->putJson('/settings/bot-knowledge/size-chart', ['unit' => 'cm', 'columns' => ['المقاس', 'الصدر'], 'rows' => [['S', '86-90']], 'note' => null])
        ->assertForbidden();
});

it('rejects a size chart note longer than 200 characters', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);

    $this->actingAs($sup)->putJson('/settings/bot-knowledge/size-chart', [
        'unit' => 'cm', 'columns' => ['المقاس', 'الصدر'], 'rows' => [['S', '86-90']], 'note' => str_repeat('ا', 201),
    ])->assertStatus(422)->assertJsonValidationErrors('note');
});

it('rejects a new entry key longer than 60 characters', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);

    $this->actingAs($sup)->postJson('/settings/bot-knowledge/entries', [
        'key' => str_repeat('a', 61), 'title' => 'عنوان', 'body' => 'نص',
    ])->assertStatus(422)->assertJsonValidationErrors('key');
});

it('keeps is_template true when only is_active is toggled on a fresh template entry', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $entry = BotKnowledgeEntry::where('key', 'store_intro')->first();
    $entry->update(['is_template' => true]);
    expect($entry->is_template)->toBeTrue();

    $this->actingAs($sup)->putJson("/settings/bot-knowledge/entries/{$entry->id}", ['is_active' => false])->assertOk()
        ->assertJsonPath('data.is_template', true)
        ->assertJsonPath('data.is_active', false);
});

it('404s the size chart image route when no image is set', function () {
    $this->actingAs(User::factory()->create())->get('/bot/size-chart-image')->assertNotFound();
});

it('rejects a pdf upload for the size chart image', function () {
    Storage::fake('media');
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $pdf = tempnam(sys_get_temp_dir(), 'crm');
    file_put_contents($pdf, SampleMedia::bytes('file'));

    $this->actingAs($sup)->post('/settings/bot-knowledge/size-chart/image', ['file' => new UploadedFile($pdf, 'catalog.pdf', 'application/pdf', null, true)], ['Accept' => 'application/json'])
        ->assertStatus(422);
    expect(SizeChart::fromSettings(BotSetting::current())->hasImage())->toBeFalse();
});

it('accepts only jpeg or png up to 5 MB for the size chart image (final fix wave I4)', function () {
    Storage::fake('media');
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);

    // A real RIFF/WEBP header: sniffed as image/webp (uploadable globally, but not sendable on WhatsApp).
    $webp = tempnam(sys_get_temp_dir(), 'crm');
    $chunk = 'VP8L'.pack('V', 10)."\x2f\x00\x00\x00\x00\x00\x00\x00\x00\x00";
    file_put_contents($webp, 'RIFF'.pack('V', 4 + strlen($chunk)).'WEBP'.$chunk);
    $this->actingAs($sup)->post('/settings/bot-knowledge/size-chart/image', ['file' => new UploadedFile($webp, 'chart.webp', 'image/webp', null, true)], ['Accept' => 'application/json'])
        ->assertStatus(422);

    // A png over 5 MB is refused too.
    $big = tempnam(sys_get_temp_dir(), 'crm');
    file_put_contents($big, SampleMedia::bytes('image').str_repeat("\0", 5 * 1024 * 1024));
    $this->actingAs($sup)->post('/settings/bot-knowledge/size-chart/image', ['file' => new UploadedFile($big, 'chart.png', 'image/png', null, true)], ['Accept' => 'application/json'])
        ->assertStatus(422);

    expect(SizeChart::fromSettings(BotSetting::current())->hasImage())->toBeFalse();
});

it('falls back to the default size chart when the stored one is structurally invalid', function () {
    $settings = BotSetting::current();
    $settings->forceFill(['size_chart' => ['columns' => ['المقاس', 'الصدر'], 'rows' => [['S']]]])->save();

    expect(SizeChart::fromSettings($settings->fresh())->toText())->toContain('S: الصدر 86-90، الوسط 66-70، الهيب 92-96، الوزن التقريبي 50-57 كجم');
});

it('deletes the old file when the size chart image is replaced', function () {
    Storage::fake('media');
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);

    $first = tempnam(sys_get_temp_dir(), 'crm');
    file_put_contents($first, SampleMedia::bytes('image'));
    $this->actingAs($sup)->post('/settings/bot-knowledge/size-chart/image', ['file' => new UploadedFile($first, 'chart1.png', 'image/png', null, true)], ['Accept' => 'application/json'])->assertOk();
    $oldPath = BotSetting::current()->fresh()->size_chart_image_path;

    $second = tempnam(sys_get_temp_dir(), 'crm');
    file_put_contents($second, SampleMedia::bytes('image'));
    $this->actingAs($sup)->post('/settings/bot-knowledge/size-chart/image', ['file' => new UploadedFile($second, 'chart2.png', 'image/png', null, true)], ['Accept' => 'application/json'])->assertOk();
    $newPath = BotSetting::current()->fresh()->size_chart_image_path;

    expect($newPath)->not->toBe($oldPath);
    Storage::disk('media')->assertMissing($oldPath);
    Storage::disk('media')->assertExists($newPath);
});
