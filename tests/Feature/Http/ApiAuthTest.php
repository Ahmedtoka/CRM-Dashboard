<?php

use App\Analytics\ActivityLogger;
use App\Models\{ActivityLog, User};

it('issues sanctum tokens and returns me', function () {
    $u = User::factory()->create(['password'=>bcrypt('secret123')]);
    $token = $this->postJson('/api/v1/auth/login', ['email'=>$u->email,'password'=>'secret123','device_name'=>'test'])->assertOk()->json('token');
    $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $u->id);
    $this->postJson('/api/v1/auth/login', ['email'=>$u->email,'password'=>'wrong','device_name'=>'t'])->assertStatus(422);
});

it('logs api login and logout and revokes the token', function () {
    $u = User::factory()->create(['password'=>bcrypt('secret123')]);
    $res = $this->postJson('/api/v1/auth/login', ['email'=>$u->email,'password'=>'secret123','device_name'=>'phone'])
        ->assertOk()->assertJsonPath('user.id', $u->id)->assertJsonPath('user.role', 'moderator');

    $this->withToken($res->json('token'))->postJson('/api/v1/auth/logout')->assertOk();

    expect(ActivityLog::where('action', ActivityLogger::USER_LOGIN)->where('user_id', $u->id)->exists())->toBeTrue()
        ->and(ActivityLog::where('action', ActivityLogger::USER_LOGOUT)->where('user_id', $u->id)->exists())->toBeTrue()
        ->and($u->tokens()->count())->toBe(0);
});

it('requires a token for api endpoints', function () {
    $this->getJson('/api/v1/me')->assertUnauthorized();
});
