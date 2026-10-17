<?php

use App\Inbox\UserNotifier;
use App\Models\DeviceToken;
use App\Models\User;
use App\Models\UserNotification;
use App\Push\Drivers\FakePushSender;
use App\Push\Drivers\FcmPushSender;
use App\Push\Jobs\SendPushNotification;
use App\Push\PushManager;
use App\Push\PushMessage;
use App\Push\PushMessageFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config(['crm.push.driver' => 'fake']);
    $this->user = User::factory()->create(['role' => 'moderator', 'locale' => 'ar']);
    $this->device = DeviceToken::factory()->create(['user_id' => $this->user->id]);
});

function mbNotify(User $u, string $type, array $data): UserNotification
{
    return app(UserNotifier::class)->notify($u, $type, $data);
}

/** @return list<PushMessage> */
function mbPushes(User $u, ?string $type = null): array
{
    return app(FakePushSender::class)->messagesFor($u, $type);
}

function mbAssertSafe(PushMessage $m): void
{
    expect(mb_strlen($m->body))->toBeLessThanOrEqual(80)
        ->and($m->title.' '.$m->body)->not->toMatch('/[0-9\x{0660}-\x{0669}][\s\-+0-9\x{0660}-\x{0669}]{5,}[0-9\x{0660}-\x{0669}]/u')
        ->and($m->title.' '.$m->body)->not->toMatch('/:[a-z_]+/');

    foreach ($m->data as $k => $v) {
        expect($k)->toBeString()->and($v)->toBeString();
    }
}

it('pushes a new window with the localized ticket, first name and platform', function () {
    $n = mbNotify($this->user, 'queue.assigned', [
        'entry_id' => 7, 'conversation_id' => 55, 'ticket' => 12, 'window_no' => 1,
        'customer_name' => 'Nour Ali 01001234567', 'platform' => 'whatsapp', 'bot_summary' => 'عايزة مقاس',
    ]);

    app(FakePushSender::class)->assertSentTo($this->user, 'queue.assigned');
    $m = mbPushes($this->user, 'queue.assigned')[0];

    expect($m->title)->toBe('شباك جديد #12')
        ->and($m->body)->toBe('Nour — WhatsApp')
        ->and($m->channel)->toBe('queue')
        ->and($m->priority)->toBe('high')
        ->and($m->collapseKey)->toBe('conversation-55')
        ->and($m->data)->toBe(['type' => 'queue.assigned', 'notification_id' => (string) $n->id, 'conversation_id' => '55', 'entry_id' => '7']);
    mbAssertSafe($m);
    expect($this->device->fresh()->last_push_at)->not->toBeNull();
});

it('speaks the user\'s language', function () {
    $this->user->forceFill(['locale' => 'en'])->save();

    mbNotify($this->user->fresh(), 'queue.assigned', ['entry_id' => 7, 'conversation_id' => 55, 'ticket' => 12, 'customer_name' => 'Nour', 'platform' => 'instagram']);

    $m = mbPushes($this->user, 'queue.assigned')[0];
    expect($m->title)->toBe('New window #12')->and($m->body)->toBe('Nour — Instagram');
});

it('masks phone numbers and caps the mention excerpt at 80 characters', function () {
    mbNotify($this->user, 'note.mention', [
        'conversation_id' => 9, 'note_id' => 3, 'by' => 'Salma Hassan',
        'excerpt' => 'كلمي العميلة على +20 100 123 4567 '.str_repeat('وبعدين أكدي الأوردر ', 10),
    ]);

    $m = mbPushes($this->user, 'note.mention')[0];
    expect($m->title)->toBe('Salma عملتلك منشن')
        ->and($m->body)->toContain('•••')->not->toContain('4567')
        ->and($m->channel)->toBe('mentions')
        ->and($m->priority)->toBe('normal');
    mbAssertSafe($m);
});

it('never puts a phone-number name in the push', function () {
    mbNotify($this->user, 'conversation.handover', ['conversation_id' => 4, 'customer_name' => '+201001234567', 'platform' => 'whatsapp']);

    $m = mbPushes($this->user, 'conversation.handover')[0];
    expect($m->title)->toBe('عميلة طالبة موظف')->and($m->body)->toBe('عميلة — WhatsApp');
    mbAssertSafe($m);
});

it('builds every spec §6 type with its channel and priority', function (string $type, array $data, string $channel, string $priority, string $title) {
    mbNotify($this->user, $type, $data);

    $m = mbPushes($this->user, $type)[0];
    expect($m->title)->toBe($title)->and($m->channel)->toBe($channel)->and($m->priority)->toBe($priority)
        ->and($m->body)->not->toBe('');
    mbAssertSafe($m);
})->with([
    'reply overdue' => ['queue.reply_overdue', ['entry_id' => 1, 'conversation_id' => 2, 'ticket' => 31, 'customer_name' => 'Mona'], 'queue', 'high', 'العميلة مستنية ردك'],
    'handover urgent' => ['conversation.handover_urgent', ['conversation_id' => 2, 'customer_name' => 'Mona', 'platform' => 'facebook'], 'handover', 'high', 'عميلة طالبة موظف — مستعجل'],
    'case created' => ['case.created', ['case_id' => 5, 'conversation_id' => 2, 'type' => 'complaint', 'excerpt' => 'x'], 'general', 'normal', 'كيس جديد #5'],
    'break overrun' => ['queue.break_overrun', ['member_id' => 1, 'user_id' => 3, 'name' => 'Hoda', 'minutes' => 15], 'queue', 'normal', 'استراحة زادت عن وقتها'],
    'escalation waiting' => ['queue.escalation_waiting', ['entry_id' => 1, 'conversation_id' => 2, 'ticket' => 8, 'customer_name' => 'Mona', 'platform' => 'tiktok'], 'queue', 'high', 'تصعيد مستني حد يستلمه'],
    'reply overdue leader' => ['queue.reply_overdue_leader', ['entry_id' => 1, 'conversation_id' => 2, 'ticket' => 8, 'customer_name' => 'Mona', 'agent_name' => 'Hoda Ali'], 'queue', 'high', 'رد متأخر في الصالة'],
]);

it('labels the case type in the push body', function () {
    mbNotify($this->user, 'case.created', ['case_id' => 5, 'conversation_id' => 2, 'type' => 'complaint']);

    expect(mbPushes($this->user, 'case.created')[0]->body)->toBe(__('cases.types.complaint', [], 'ar'))
        ->and(mbPushes($this->user, 'case.created')[0]->data)->toHaveKey('case_id', '5');
});

it('does not queue a push for unlisted types, without devices, or with push off', function () {
    Bus::fake([SendPushNotification::class]);

    mbNotify($this->user, 'comment.complaint', ['comment_id' => 1]);
    Bus::assertNotDispatched(SendPushNotification::class);

    $lonely = User::factory()->create();
    DeviceToken::factory()->disabled()->create(['user_id' => $lonely->id]);
    mbNotify($lonely, 'queue.assigned', ['ticket' => 1]);
    Bus::assertNotDispatched(SendPushNotification::class);

    config(['crm.push.driver' => null]);
    mbNotify($this->user, 'queue.assigned', ['ticket' => 1]);
    Bus::assertNotDispatched(SendPushNotification::class);

    config(['crm.push.driver' => 'fake']);
    mbNotify($this->user, 'queue.assigned', ['ticket' => 1]);
    Bus::assertDispatched(SendPushNotification::class, fn (SendPushNotification $job) => $job->queue === 'outbound' && $job->afterCommit === true);
});

it('never lets a push problem break notify()', function () {
    app()->instance(PushManager::class, new class extends PushManager
    {
        public function enabled(): bool
        {
            throw new RuntimeException('boom');
        }
    });

    $n = mbNotify($this->user, 'queue.assigned', ['ticket' => 1]);

    expect($n->exists)->toBeTrue();
});

it('pushes nothing for a user deactivated before the job ran', function () {
    Bus::fake([SendPushNotification::class]);
    $n = mbNotify($this->user, 'queue.assigned', ['ticket' => 1]);
    $this->user->forceFill(['is_active' => false])->save();

    (new SendPushNotification($n->id))->handle(app(PushManager::class), app(PushMessageFactory::class));

    app(FakePushSender::class)->assertNothingSent();
});

it('disables a device after 5 consecutive failures, and resets the count on success', function () {
    $fake = app(FakePushSender::class);
    $push = app(PushManager::class);
    $msg = app(PushMessageFactory::class)->test($this->user);

    $fake->failNext();
    foreach (range(1, 4) as $i) {
        expect($push->sendToDevice($this->device->fresh(), $msg)->ok)->toBeFalse();
    }
    expect($this->device->fresh()->failure_count)->toBe(4)->and($this->device->fresh()->disabled_at)->toBeNull();

    $push->sendToDevice($this->device->fresh(), $msg);
    expect($this->device->fresh()->disabled_at)->not->toBeNull();
});

// ───── FCM HTTP v1 driver ─────

/** A throwaway service-account key file; returns the public key PEM for signature checks. */
function mbFcmCredentials(string $mode = 'file'): string
{
    // Windows PHP builds (XAMPP) need an explicit openssl.cnf to generate keys.
    $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    $cnf = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
    if (getenv('OPENSSL_CONF') === false && is_file($cnf)) {
        $options['config'] = $cnf;
    }
    $key = openssl_pkey_new($options);
    openssl_pkey_export($key, $privatePem, null, $options);
    $json = json_encode([
        'type' => 'service_account', 'project_id' => 'levoile-test', 'client_email' => 'push@levoile-test.iam.gserviceaccount.com',
        'private_key' => $privatePem, 'token_uri' => 'https://oauth2.googleapis.com/token',
    ]);
    $path = tempnam(sys_get_temp_dir(), 'fcm').'.json';
    file_put_contents($path, $json);
    $value = match ($mode) {
        'inline' => $json,
        'base64' => base64_encode($json),
        default => $path,
    };
    config(['crm.push.driver' => 'fcm', 'crm.push.fcm.project_id' => 'levoile-test', 'crm.push.fcm.credentials' => $value]);

    return openssl_pkey_get_details($key)['key'];
}

function mbB64d(string $s): string
{
    return base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4));
}

it('exchanges a signed service-account JWT for an access token, then sends the HTTP v1 message', function () {
    Cache::flush();
    $publicKey = mbFcmCredentials();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.test', 'expires_in' => 3599]),
        'fcm.googleapis.com/*' => Http::response(['name' => 'projects/levoile-test/messages/1']),
    ]);

    mbNotify($this->user, 'queue.assigned', ['entry_id' => 7, 'conversation_id' => 55, 'ticket' => 12, 'customer_name' => 'Nour', 'platform' => 'whatsapp']);

    Http::assertSent(function (HttpRequest $r) use ($publicKey) {
        if ($r->url() !== 'https://oauth2.googleapis.com/token') {
            return false;
        }
        [$h, $c, $s] = explode('.', $r['assertion']);
        $claims = json_decode(mbB64d($c), true);

        return $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
            && json_decode(mbB64d($h), true)['alg'] === 'RS256'
            && $claims['iss'] === 'push@levoile-test.iam.gserviceaccount.com'
            && $claims['scope'] === FcmPushSender::SCOPE
            && openssl_verify("{$h}.{$c}", mbB64d($s), $publicKey, OPENSSL_ALGO_SHA256) === 1;
    });

    Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://fcm.googleapis.com/v1/projects/levoile-test/messages:send'
        && $r->hasHeader('Authorization', 'Bearer ya29.test')
        && $r['message']['token'] === $this->device->token
        && $r['message']['notification']['title'] === 'شباك جديد #12'
        && $r['message']['data']['conversation_id'] === '55'
        && $r['message']['android']['priority'] === 'HIGH'
        && $r['message']['android']['notification']['channel_id'] === 'queue'
        && $r['message']['android']['collapse_key'] === 'conversation-55');

    expect($this->device->fresh()->last_push_at)->not->toBeNull();
});

it('caches the FCM access token across sends', function () {
    Cache::flush();
    mbFcmCredentials();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.cached', 'expires_in' => 3599]),
        'fcm.googleapis.com/*' => Http::response(['name' => 'ok']),
    ]);

    $push = app(PushManager::class);
    $msg = app(PushMessageFactory::class)->test($this->user);
    $push->sendToDevice($this->device, $msg);
    $push->sendToDevice($this->device->fresh(), $msg);

    expect(collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'oauth2'))->count())->toBe(1)
        ->and(collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'messages:send'))->count())->toBe(2);
});

it('disables a device FCM reports as unregistered or invalid', function (int $status, array $error) {
    Cache::flush();
    mbFcmCredentials();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.x']),
        'fcm.googleapis.com/*' => Http::response(['error' => $error], $status),
    ]);

    $result = app(PushManager::class)->sendToDevice($this->device, app(PushMessageFactory::class)->test($this->user));

    expect($result->ok)->toBeFalse()->and($result->invalidToken)->toBeTrue()
        ->and($this->device->fresh()->disabled_at)->not->toBeNull();
})->with([
    'unregistered' => [404, ['code' => 404, 'status' => 'NOT_FOUND', 'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']]]],
    'invalid token' => [400, ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'The registration token is not a valid FCM registration token', 'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'INVALID_ARGUMENT']]]],
]);

it('treats SENDER_ID_MISMATCH as a configuration error: logged once per job, device untouched', function () {
    Cache::flush();
    mbFcmCredentials();
    $second = DeviceToken::factory()->create(['user_id' => $this->user->id]);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.x']),
        'fcm.googleapis.com/*' => Http::response(['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED',
            'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'SENDER_ID_MISMATCH']]]], 403),
    ]);
    Log::spy();
    Bus::fake([SendPushNotification::class]);
    $n = UserNotification::create(['user_id' => $this->user->id, 'type' => 'queue.assigned', 'data' => ['ticket' => 3]]);

    (new SendPushNotification($n->id))->handle(app(PushManager::class), app(PushMessageFactory::class));

    Log::shouldHaveReceived('error')->withArgs(fn (string $msg) => $msg === 'push.config_error')->once();
    Bus::assertNotDispatched(SendPushNotification::class);
    foreach ([$this->device, $second] as $d) {
        expect($d->fresh()->failure_count)->toBe(0)->and($d->fresh()->disabled_at)->toBeNull();
    }
});

it('leaves the device alone when FCM rejects the payload, not the token', function () {
    Cache::flush();
    mbFcmCredentials();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.x']),
        'fcm.googleapis.com/*' => Http::response(['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'Invalid value at message.android.collapse_key',
            'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'INVALID_ARGUMENT']]]], 400),
    ]);

    $result = app(PushManager::class)->sendToDevice($this->device, app(PushMessageFactory::class)->test($this->user));

    expect($result->deviceFault)->toBeFalse()->and($result->transient)->toBeFalse()
        ->and($this->device->fresh()->failure_count)->toBe(0)->and($this->device->fresh()->disabled_at)->toBeNull();
});

it('never disables devices because Google auth keeps failing', function () {
    Cache::flush();
    mbFcmCredentials();
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400), 'fcm.googleapis.com/*' => Http::response([], 200)]);

    foreach (range(1, 6) as $i) {
        expect(app(PushManager::class)->sendToDevice($this->device->fresh(), app(PushMessageFactory::class)->test($this->user))->transient)->toBeTrue();
    }

    expect($this->device->fresh()->failure_count)->toBe(0)->and($this->device->fresh()->disabled_at)->toBeNull();
});

it('accepts the service-account key inline or base64-encoded', function (string $mode) {
    Cache::flush();
    mbFcmCredentials($mode);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.x']),
        'fcm.googleapis.com/*' => Http::response(['name' => 'ok']),
    ]);

    expect(app(PushManager::class)->sendToDevice($this->device, app(PushMessageFactory::class)->test($this->user))->ok)->toBeTrue();
})->with(['inline', 'base64']);

it('hides the text on the lock screen and collapses on iOS too', function () {
    Cache::flush();
    mbFcmCredentials();
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.x']), 'fcm.googleapis.com/*' => Http::response(['name' => 'ok'])]);

    mbNotify($this->user, 'queue.assigned', ['entry_id' => 7, 'conversation_id' => 55, 'ticket' => 12]);

    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'messages:send')
        && $r['message']['android']['notification']['visibility'] === 'PRIVATE'
        && $r['message']['apns']['headers']['apns-collapse-id'] === 'conversation-55');
});

it('retries transient failures later, only to the devices that missed the push', function () {
    $second = DeviceToken::factory()->create(['user_id' => $this->user->id]);
    $n = UserNotification::create(['user_id' => $this->user->id, 'type' => 'queue.assigned', 'data' => ['ticket' => 3, 'conversation_id' => 9]]);
    Bus::fake([SendPushNotification::class]);

    $fake = app(FakePushSender::class);
    $fake->failDevice($second->id, 'transient');

    (new SendPushNotification($n->id))->handle(app(PushManager::class), app(PushMessageFactory::class));

    Bus::assertDispatched(SendPushNotification::class, fn (SendPushNotification $j) => $j->deviceIds === [$second->id] && $j->attempt === 1 && $j->delay !== null);
    expect($fake->sent)->toHaveCount(1)->and($second->fresh()->failure_count)->toBe(0);

    $fake->succeedAgain();
    (new SendPushNotification($n->id, [$second->id], 1))->handle(app(PushManager::class), app(PushMessageFactory::class));
    expect($fake->sent)->toHaveCount(2)->and($fake->sent[1]['device']->id)->toBe($second->id);
});

it('stops retrying after the last backoff step', function () {
    $n = UserNotification::create(['user_id' => $this->user->id, 'type' => 'queue.assigned', 'data' => ['ticket' => 3]]);
    Bus::fake([SendPushNotification::class]);
    app(FakePushSender::class)->failNext('fcm_503', kind: 'transient');

    (new SendPushNotification($n->id, [$this->device->id], count(SendPushNotification::BACKOFF)))->handle(app(PushManager::class), app(PushMessageFactory::class));

    Bus::assertNotDispatched(SendPushNotification::class);
});

it('treats a server error as transient: no failure counted', function () {
    Cache::flush();
    mbFcmCredentials();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.x']),
        'fcm.googleapis.com/*' => Http::response(['error' => ['code' => 503, 'status' => 'UNAVAILABLE']], 503),
    ]);

    $result = app(PushManager::class)->sendToDevice($this->device, app(PushMessageFactory::class)->test($this->user));

    expect($result->transient)->toBeTrue()
        ->and($this->device->fresh()->failure_count)->toBe(0)->and($this->device->fresh()->disabled_at)->toBeNull();
});

it('fails cleanly when the FCM credentials are missing', function () {
    config(['crm.push.driver' => 'fcm', 'crm.push.fcm.credentials' => storage_path('nope.json')]);
    Http::fake();

    $result = app(PushManager::class)->sendToDevice($this->device, app(PushMessageFactory::class)->test($this->user));

    expect($result->ok)->toBeFalse()->and($result->invalidToken)->toBeFalse()->and($result->deviceFault)->toBeFalse()
        ->and($this->device->fresh()->failure_count)->toBe(0);
    Http::assertNothingSent();
});
