<?php

use App\Models\User;

it('refuses web and api login for inactive users', function () {
    $u = User::factory()->create(['is_active'=>false]);

    $this->post('/login', ['email'=>$u->email, 'password'=>'password'])->assertSessionHasErrors('email');
    $this->assertGuest();

    $this->postJson('/api/v1/auth/login', ['email'=>$u->email, 'password'=>'password', 'device_name'=>'phone'])->assertStatus(422);
    expect($u->tokens()->count())->toBe(0);
});

it('logs out an existing web session once the user is deactivated', function () {
    $u = User::factory()->create();
    $this->actingAs($u)->get('/reports/me')->assertOk();

    $u->forceFill(['is_active'=>false])->save();

    $this->get('/inbox')->assertRedirect(route('login'));
    $this->assertGuest();
});

it('revokes the api token of a deactivated user', function () {
    $u = User::factory()->create();
    $token = $u->createToken('phone')->plainTextToken;
    $u->forceFill(['is_active'=>false])->save();

    $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    expect($u->tokens()->count())->toBe(0);
});
