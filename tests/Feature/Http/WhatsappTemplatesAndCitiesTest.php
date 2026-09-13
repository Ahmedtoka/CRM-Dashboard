<?php

use App\Models\City;
use App\Models\User;

it('returns the configured whatsapp template list', function () {
    $u = User::factory()->create();
    $token = $u->createToken('phone')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/whatsapp-templates')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'order_update')
        ->assertJsonPath('data.0.params', 2)
        ->assertJsonCount(count(config('crm.whatsapp_templates')), 'data');
});

it('requires a token for whatsapp-templates and cities', function () {
    $this->getJson('/api/v1/whatsapp-templates')->assertUnauthorized();
    $this->getJson('/api/v1/cities')->assertUnauthorized();
});

it('shares the whatsapp template list with every inertia page', function () {
    $u = User::factory()->create();

    $this->actingAs($u)->get('/reports/me')->assertOk()
        ->assertInertia(fn ($page) => $page->where('whatsappTemplates.0.name', 'order_update'));
});

it('lists cities ordered by arabic name with their shipping fee', function () {
    $u = User::factory()->create();
    $token = $u->createToken('phone')->plainTextToken;

    City::factory()->create(['name_ar' => 'بورسعيد', 'name_en' => 'Port Said', 'shipping_fee' => 55]);
    City::factory()->create(['name_ar' => 'أسوان', 'name_en' => 'Aswan', 'shipping_fee' => 90]);

    $res = $this->withToken($token)->getJson('/api/v1/cities')->assertOk();

    $names = collect($res->json('data'))->pluck('name_ar')->all();
    expect($names)->toBe(collect($names)->sort()->values()->all())
        ->and($res->json('data.0'))->toHaveKeys(['id', 'name_ar', 'name_en', 'shipping_fee']);
});
