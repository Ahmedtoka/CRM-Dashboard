<?php

use App\Inbox\SoftLock;
use App\Models\{Conversation, User};

it('locks for 45 seconds for one user', function () {
    $c = Conversation::factory()->create(); [$a, $b] = User::factory()->count(2)->create()->all();
    $lock = app(SoftLock::class);
    expect($lock->acquire($c, $a))->toBeTrue()->and($lock->acquire($c->fresh(), $b))->toBeFalse()
        ->and($lock->holder($c->fresh())->id)->toBe($a->id);
    $this->travel(46)->seconds();
    expect($lock->holder($c->fresh()))->toBeNull()->and($lock->acquire($c->fresh(), $b))->toBeTrue();
});
