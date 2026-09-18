<?php

use App\Enums\UserRole;
use App\Media\SampleMedia;
use App\Models\QuickReply;
use App\Models\QuickReplyAttachment;
use App\Models\QuickReplyCategory;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('lets moderators manage only their personal replies', function () {
    $mod = User::factory()->create(['role' => UserRole::Moderator]);

    $this->actingAs($mod)->get('/settings/quick-replies')->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/QuickReplies')->where('canManageShared', false));

    $this->actingAs($mod)->postJson('/settings/quick-replies', ['scope' => 'personal', 'shortcut' => 'hi', 'title' => 'ترحيب', 'body' => 'أهلاً {الاسم_الأول}'])->assertCreated();
    $this->actingAs($mod)->postJson('/settings/quick-replies', ['scope' => 'shared', 'shortcut' => 'x', 'title' => 'x', 'body' => 'x'])->assertForbidden();

    $shared = QuickReply::factory()->create();
    $this->actingAs($mod)->putJson("/settings/quick-replies/{$shared->id}", ['shortcut' => 'y', 'title' => 'y', 'body' => 'y'])->assertForbidden();
    $this->actingAs($mod)->postJson('/settings/quick-reply-categories', ['name' => 'x'])->assertForbidden();
});

it('enforces unique shortcuts per scope owner while letting personal shadow shared', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    QuickReply::factory()->create(['shortcut' => 'ship']);

    $this->actingAs($sup)->postJson('/settings/quick-replies', ['scope' => 'shared', 'shortcut' => 'ship', 'title' => 'a', 'body' => 'b'])
        ->assertStatus(422)->assertJsonValidationErrors('shortcut');
    $this->actingAs($sup)->postJson('/settings/quick-replies', ['scope' => 'personal', 'shortcut' => 'ship', 'title' => 'a', 'body' => 'b'])->assertCreated();
    $this->actingAs($sup)->postJson('/settings/quick-replies', ['scope' => 'personal', 'shortcut' => '/ship', 'title' => 'a', 'body' => 'b'])
        ->assertStatus(422)->assertJsonValidationErrors('shortcut');
});

it('uploads up to five attachments and previews variables against a sample customer', function () {
    Storage::fake('media');
    $sup = User::factory()->create(['role' => UserRole::Supervisor, 'name' => 'سارة']);
    $reply = QuickReply::factory()->create();
    $png = tempnam(sys_get_temp_dir(), 'crm');
    file_put_contents($png, SampleMedia::bytes('image'));

    foreach (range(1, 5) as $i) {
        $this->actingAs($sup)->post("/settings/quick-replies/{$reply->id}/attachments", ['file' => new UploadedFile($png, "chart{$i}.png", 'image/png', null, true)], ['Accept' => 'application/json'])->assertCreated();
    }
    $this->actingAs($sup)->post("/settings/quick-replies/{$reply->id}/attachments", ['file' => new UploadedFile($png, 'six.png', 'image/png', null, true)], ['Accept' => 'application/json'])->assertStatus(422);

    $this->actingAs($sup)->postJson('/settings/quick-replies/preview', ['body' => 'أهلاً {الاسم_الأول}، معاكي {agent_name}'])
        ->assertOk()->assertJson(['body' => 'أهلاً منى، معاكي سارة', 'missing' => []]);
});

it('lets supervisors manage categories', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $id = $this->actingAs($sup)->postJson('/settings/quick-reply-categories', ['name' => 'عروض', 'sort' => 90])->assertCreated()->json('data.id');
    $this->actingAs($sup)->putJson("/settings/quick-reply-categories/{$id}", ['name' => 'العروض', 'sort' => 5])->assertOk();
    expect(QuickReplyCategory::find($id)->name)->toBe('العروض');
    $this->actingAs($sup)->deleteJson("/settings/quick-reply-categories/{$id}")->assertOk();
});

it('forbids another user from touching someone else\'s personal reply', function () {
    Storage::fake('media');
    $owner = User::factory()->create(['role' => UserRole::Moderator]);
    $other = User::factory()->create(['role' => UserRole::Moderator]);
    $reply = QuickReply::factory()->personal($owner)->create();
    $png = tempnam(sys_get_temp_dir(), 'crm');
    file_put_contents($png, SampleMedia::bytes('image'));

    $this->actingAs($other)->putJson("/settings/quick-replies/{$reply->id}", ['shortcut' => 'z', 'title' => 'z', 'body' => 'z'])->assertForbidden();
    $this->actingAs($other)->deleteJson("/settings/quick-replies/{$reply->id}")->assertForbidden();
    $this->actingAs($other)->post("/settings/quick-replies/{$reply->id}/attachments", ['file' => new UploadedFile($png, 'a.png', 'image/png', null, true)], ['Accept' => 'application/json'])->assertForbidden();
});

it('rejects audio and video attachments on a saved reply', function () {
    Storage::fake('media');
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $reply = QuickReply::factory()->create();
    $voice = tempnam(sys_get_temp_dir(), 'crm');
    file_put_contents($voice, SampleMedia::bytes('voice'));
    $video = tempnam(sys_get_temp_dir(), 'crm');
    file_put_contents($video, SampleMedia::bytes('video'));

    $this->actingAs($sup)->post("/settings/quick-replies/{$reply->id}/attachments", ['file' => new UploadedFile($voice, 'voice.ogg', SampleMedia::mime('voice'), null, true)], ['Accept' => 'application/json'])->assertStatus(422);
    $this->actingAs($sup)->post("/settings/quick-replies/{$reply->id}/attachments", ['file' => new UploadedFile($video, 'video.mp4', SampleMedia::mime('video'), null, true)], ['Accept' => 'application/json'])->assertStatus(422);
    expect($reply->fresh()->attachments)->toHaveCount(0);
});

it('sanitises a slash-bearing attachment filename', function () {
    Storage::fake('media');
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $reply = QuickReply::factory()->create();
    $png = tempnam(sys_get_temp_dir(), 'crm');
    file_put_contents($png, SampleMedia::bytes('image'));

    $data = $this->actingAs($sup)->post("/settings/quick-replies/{$reply->id}/attachments", ['file' => new UploadedFile($png, '../evil/name.png', 'image/png', null, true)], ['Accept' => 'application/json'])
        ->assertCreated()->json('data');

    expect($data['original_name'])->not->toContain('/');
});

it('removes the stored file when an attachment is deleted, and every file when the reply itself is deleted', function () {
    Storage::fake('media');
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);
    $reply = QuickReply::factory()->create();
    $png = tempnam(sys_get_temp_dir(), 'crm');
    file_put_contents($png, SampleMedia::bytes('image'));

    $attachmentId = $this->actingAs($sup)->post("/settings/quick-replies/{$reply->id}/attachments", ['file' => new UploadedFile($png, 'a.png', 'image/png', null, true)], ['Accept' => 'application/json'])
        ->assertCreated()->json('data.id');
    $path = QuickReplyAttachment::findOrFail($attachmentId)->path;
    Storage::disk('media')->assertExists($path);

    $this->actingAs($sup)->deleteJson("/settings/quick-replies/{$reply->id}/attachments/{$attachmentId}")->assertOk();
    Storage::disk('media')->assertMissing($path);
    expect(QuickReplyAttachment::find($attachmentId))->toBeNull();

    $secondId = $this->actingAs($sup)->post("/settings/quick-replies/{$reply->id}/attachments", ['file' => new UploadedFile($png, 'b.png', 'image/png', null, true)], ['Accept' => 'application/json'])
        ->assertCreated()->json('data.id');
    $secondPath = QuickReplyAttachment::findOrFail($secondId)->path;

    $this->actingAs($sup)->deleteJson("/settings/quick-replies/{$reply->id}")->assertOk();
    Storage::disk('media')->assertMissing($secondPath);
});

it('rejects an invalid or non-string scope with a normal validation error, not a crash', function () {
    $sup = User::factory()->create(['role' => UserRole::Supervisor]);

    $this->actingAs($sup)->postJson('/settings/quick-replies', ['scope' => ['not', 'a', 'string'], 'shortcut' => 'x', 'title' => 'x', 'body' => 'x'])
        ->assertStatus(422)->assertJsonValidationErrors('scope');
    $this->actingAs($sup)->postJson('/settings/quick-replies', ['scope' => 'bogus', 'shortcut' => 'y', 'title' => 'y', 'body' => 'y'])
        ->assertStatus(422)->assertJsonValidationErrors('scope');
});
