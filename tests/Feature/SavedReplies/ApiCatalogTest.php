<?php

use App\Enums\{Platform, UserRole};
use App\Models\{QuickReply, User};

beforeEach(function () {
    $this->mod = User::factory()->create(['role' => UserRole::Moderator]);
    $this->mod->userPlatforms()->create(['platform' => Platform::WhatsApp]);
    $this->token = $this->mod->createToken('phone')->plainTextToken;
});

it('filters the api v1 catalog by an accessible platform query param', function () {
    QuickReply::factory()->create(['shortcut' => 'wa', 'platforms' => ['whatsapp']]);
    QuickReply::factory()->create(['shortcut' => 'fb', 'platforms' => ['facebook']]);
    QuickReply::factory()->create(['shortcut' => 'any', 'platforms' => []]);

    $rows = $this->withToken($this->token)->getJson('/api/v1/quick-replies?platform=whatsapp')->assertOk()->json('data');

    expect(array_column($rows, 'shortcut'))->toEqualCanonicalizing(['wa', 'any']);
});

it('returns an empty list for a platform query param the user cannot access, instead of leaking it', function () {
    QuickReply::factory()->create(['shortcut' => 'fb', 'platforms' => ['facebook']]);
    QuickReply::factory()->create(['shortcut' => 'any', 'platforms' => []]);

    $rows = $this->withToken($this->token)->getJson('/api/v1/quick-replies?platform=facebook')->assertOk()->json('data');

    expect($rows)->toBe([]);
});
