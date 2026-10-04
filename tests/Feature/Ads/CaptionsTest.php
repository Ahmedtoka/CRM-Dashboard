<?php

use App\Ads\Captions\CaptionException;
use App\Ads\Captions\CaptionGenerator;
use App\Ads\Captions\FakeCaptionGenerator;
use App\Ads\Captions\FrameExtractor;
use App\Ads\Captions\GeneratesCaptions;
use App\Enums\UserRole;
use App\Models\AdMaterial;
use App\Models\AdMaterialCaption;
use App\Models\AdMaterialFile;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['crm.drivers.ai' => 'fake']);
});

function capSetup(): array
{
    $product = Product::factory()->create(['title' => 'Silk abaya']);
    ProductVariant::factory()->create(['product_id' => $product->id, 'price' => '1200.00', 'compare_at_price' => '1500.00']);
    $material = AdMaterial::factory()->create(['product_id' => $product->id, 'content_notes' => 'Soft silk abaya']);
    $file = AdMaterialFile::factory()->create(['ad_material_id' => $material->id, 'mime' => 'video/mp4', 'duration' => 20]);

    return [$material, $file];
}

function noFrames(): void
{
    app()->bind(FrameExtractor::class, fn () => new class extends FrameExtractor
    {
        public function frames(AdMaterialFile $f, int $count = 4): array
        {
            return [];
        }
    });
}

function claudeCaptionsResponse(array $captions): array
{
    return [
        'content' => [['type' => 'text', 'text' => json_encode(['captions' => $captions], JSON_UNESCAPED_UNICODE)]],
        'usage' => ['input_tokens' => 900, 'output_tokens' => 120],
    ];
}

it('generates three captions with the fake generator and stores them', function () {
    [$m, $f] = capSetup();
    $user = User::factory()->create(['role' => UserRole::Content]);

    $res = $this->actingAs($user)->postJson("/ads/materials/{$m->id}/captions", ['file_id' => $f->id])->assertOk();

    expect($res->json('captions'))->toHaveCount(3)
        ->and(array_column($res->json('captions'), 'angle'))->toBe(['emotional', 'offer', 'quality']);
    expect(AdMaterialCaption::query()->where('ad_material_file_id', $f->id)->count())->toBe(3);
});

it('lets a media buyer generate and regenerate replaces the previous captions', function () {
    [$m, $f] = capSetup();
    $user = User::factory()->create(['role' => UserRole::MediaBuyer]);

    $this->actingAs($user)->postJson("/ads/materials/{$m->id}/captions", ['file_id' => $f->id])->assertOk();
    $firstIds = AdMaterialCaption::query()->pluck('id')->all();
    $this->actingAs($user)->postJson("/ads/materials/{$m->id}/captions", ['file_id' => $f->id])->assertOk();

    expect(AdMaterialCaption::query()->count())->toBe(3)
        ->and(AdMaterialCaption::query()->whereIn('id', $firstIds)->count())->toBe(0);
});

it('rejects a file of another material and a moderator', function () {
    [$m] = capSetup();
    [, $other] = capSetup();

    $this->actingAs(User::factory()->create(['role' => UserRole::Content]))
        ->postJson("/ads/materials/{$m->id}/captions", ['file_id' => $other->id])->assertStatus(422);
    $this->actingAs(User::factory()->create(['role' => UserRole::Moderator]))
        ->postJson("/ads/materials/{$m->id}/captions", ['file_id' => $other->id])->assertForbidden();
});

it('edits a caption, strips emoji and validates lengths', function () {
    [$m, $f] = capSetup();
    $user = User::factory()->create(['role' => UserRole::Content]);
    $this->actingAs($user)->postJson("/ads/materials/{$m->id}/captions", ['file_id' => $f->id]);
    $c = AdMaterialCaption::query()->orderBy('position')->first();

    $this->actingAs($user)->putJson("/ads/captions/{$c->id}", ['headline' => "عباية حرير \u{1F60D}", 'primary_text' => 'نص جديد', 'cta' => 'ORDER_NOW'])->assertOk();
    $c->refresh();
    expect($c->headline)->toBe('عباية حرير')->and($c->cta)->toBe('ORDER_NOW')->and($c->edited_by_id)->toBe($user->id);

    $this->actingAs($user)->putJson("/ads/captions/{$c->id}", ['headline' => str_repeat('a', 41), 'primary_text' => 'x', 'cta' => 'SHOP_NOW'])->assertStatus(422);
    $this->actingAs($user)->putJson("/ads/captions/{$c->id}", ['headline' => 'ok', 'primary_text' => str_repeat('a', 401), 'cta' => 'SHOP_NOW'])->assertStatus(422);
    $this->actingAs($user)->putJson("/ads/captions/{$c->id}", ['headline' => 'ok', 'primary_text' => 'x', 'cta' => 'BUY'])->assertStatus(422);
});

it('lists the stored captions of a file', function () {
    [$m, $f] = capSetup();
    $user = User::factory()->create(['role' => UserRole::Content]);
    $this->actingAs($user)->postJson("/ads/materials/{$m->id}/captions", ['file_id' => $f->id]);

    $this->actingAs($user)->getJson("/ads/materials/{$m->id}/captions?file_id={$f->id}")->assertOk()->assertJsonCount(3, 'captions');
});

it('sends image blocks and product data to Claude and maps the answer', function () {
    [$m, $f] = capSetup();
    config(['crm.anthropic.key' => 'test-key', 'crm.ads.captions.model' => 'claude-test']);
    app()->bind(FrameExtractor::class, fn () => new class extends FrameExtractor
    {
        public function frames(AdMaterialFile $f, int $count = 4): array
        {
            return ['QUJD'];
        }
    });
    Http::fake(['api.anthropic.com/*' => Http::response(claudeCaptionsResponse([
        ['angle' => 'emotional', 'headline' => str_repeat('ع', 60)." \u{1F60D}", 'primary_text' => str_repeat('ب', 450), 'cta' => 'SHOP_NOW'],
        ['angle' => 'offer', 'headline' => 'عرض', 'primary_text' => 'نص', 'cta' => 'WEIRD'],
        ['angle' => 'quality', 'headline' => 'جودة', 'primary_text' => 'نص', 'cta' => 'LEARN_MORE'],
    ]))]);

    $rows = app(CaptionGenerator::class)->generate($m, $f);

    expect($rows)->toHaveCount(3)
        ->and(mb_strlen($rows[0]->headline))->toBeLessThanOrEqual(40)
        ->and(mb_strlen($rows[0]->primary_text))->toBeLessThanOrEqual(400)
        ->and($rows[1]->cta)->toBe('SHOP_NOW')
        ->and($rows[0]->model)->toBe('claude-test')
        ->and($rows[0]->input_tokens)->toBe(900)->and($rows[0]->output_tokens)->toBe(120);

    Http::assertSent(function ($request) {
        $body = $request->data();
        $content = $body['messages'][0]['content'];
        $image = collect($content)->firstWhere('type', 'image');
        $text = collect($content)->firstWhere('type', 'text')['text'];

        return $request->hasHeader('x-api-key', 'test-key')
            && $body['model'] === 'claude-test'
            && ($image['source']['media_type'] ?? null) === 'image/jpeg' && $image['source']['data'] === 'QUJD'
            && str_contains($text, 'Silk abaya') && str_contains($text, '1200') && str_contains($text, '1500');
    });
});

it('still generates from product data when no frame can be made', function () {
    [$m, $f] = capSetup();
    config(['crm.anthropic.key' => 'test-key']);
    noFrames();
    Http::fake(['api.anthropic.com/*' => Http::response(claudeCaptionsResponse([
        ['angle' => 'emotional', 'headline' => 'a', 'primary_text' => 'b', 'cta' => 'SHOP_NOW'],
        ['angle' => 'offer', 'headline' => 'a', 'primary_text' => 'b', 'cta' => 'SHOP_NOW'],
        ['angle' => 'quality', 'headline' => 'a', 'primary_text' => 'b', 'cta' => 'SHOP_NOW'],
    ]))]);

    expect(app(CaptionGenerator::class)->generate($m, $f))->toHaveCount(3);
    Http::assertSent(fn ($r) => collect($r->data()['messages'][0]['content'])->where('type', 'image')->isEmpty());
});

it('returns a readable 422 when the API key is missing', function () {
    [$m, $f] = capSetup();
    config(['crm.drivers.ai' => 'claude', 'crm.anthropic.key' => null]);
    noFrames();

    $res = $this->actingAs(User::factory()->create(['role' => UserRole::Content]))
        ->postJson("/ads/materials/{$m->id}/captions", ['file_id' => $f->id])->assertStatus(422);
    expect($res->json('message'))->toContain('ANTHROPIC')->and(AdMaterialCaption::query()->count())->toBe(0);
});

it('binds the fake generator unless the ai driver is claude', function () {
    expect(app(GeneratesCaptions::class))->toBeInstanceOf(FakeCaptionGenerator::class);
    config(['crm.drivers.ai' => 'claude']);
    expect(app(GeneratesCaptions::class))->toBeInstanceOf(CaptionGenerator::class);
});

it('treats a configured ffmpeg path that does not exist as missing', function () {
    [, $f] = capSetup();
    Storage::fake('public');
    Storage::disk('public')->put($f->path, 'video');
    Process::fake();
    config(['crm.media.ffmpeg_path' => '/nonexistent/ffmpeg']);

    expect((new FrameExtractor)->frames($f))->toBe([]);
    Process::assertNothingRan();
});

it('runs ffmpeg at 10, 35, 60 and 85 percent of the duration at 768 px', function () {
    [, $f] = capSetup();
    Storage::fake('public');
    Storage::disk('public')->put($f->path, 'video');
    $bin = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ffmpeg-fake.exe';
    file_put_contents($bin, 'x');
    chmod($bin, 0755);
    config(['crm.media.ffmpeg_path' => $bin]);
    Process::fake(function ($p) {
        file_put_contents(end($p->command), 'jpg');

        return Process::result();
    });

    $frames = (new FrameExtractor)->frames($f);

    expect($frames)->toHaveCount(4)->and($frames[0])->toBe(base64_encode('jpg'));
    $times = [];
    Process::assertRan(function ($p) use (&$times, $bin) {
        $c = $p->command;
        if ($c[0] === $bin && in_array('scale=768:-2', $c, true)) {
            $times[] = $c[array_search('-ss', $c, true) + 1];
        }

        return true;
    });
    expect($times)->toBe(['2', '7', '12', '17']);
    @unlink($bin);
});

it('assigns angles by position, drops non-array entries and needs three valid ones', function () {
    [$m, $f] = capSetup();
    config(['crm.anthropic.key' => 'test-key']);
    noFrames();
    $c = fn ($a) => ['angle' => $a, 'headline' => 'h', 'primary_text' => 't', 'cta' => 'SHOP_NOW'];
    Http::fake(['api.anthropic.com/*' => Http::sequence()
        ->push(claudeCaptionsResponse([$c('quality'), 'junk', $c('quality'), $c('x'), $c('offer')]))
        ->push(claudeCaptionsResponse([$c('offer'), 'junk', $c('quality')]))]);

    $rows = app(CaptionGenerator::class)->generate($m, $f);
    expect(array_column($rows, 'angle'))->toBe(['emotional', 'offer', 'quality']);

    expect(fn () => app(CaptionGenerator::class)->generate($m, $f))->toThrow(CaptionException::class);
});

it('refuses a caption that is empty after cleaning and keeps the previous ones', function () {
    [$m, $f] = capSetup();
    config(['crm.anthropic.key' => 'test-key']);
    noFrames();
    app(FakeCaptionGenerator::class)->generate($m, $f);
    $ok = ['angle' => 'emotional', 'headline' => 'h', 'primary_text' => 't', 'cta' => 'SHOP_NOW'];
    Http::fake(['api.anthropic.com/*' => Http::response(claudeCaptionsResponse([$ok, $ok, ['headline' => "\u{1F60D}"] + $ok]))]);

    expect(fn () => app(CaptionGenerator::class)->generate($m, $f))->toThrow(CaptionException::class);
    expect(AdMaterialCaption::query()->where('model', 'fake')->count())->toBe(3);
});

it('logs only status and model when the API call fails', function () {
    [$m, $f] = capSetup();
    config(['crm.anthropic.key' => 'secret-key', 'crm.ads.captions.model' => 'claude-test']);
    noFrames();
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'boom'], 500)]);
    Log::spy();

    expect(fn () => app(CaptionGenerator::class)->generate($m, $f))->toThrow(CaptionException::class);
    Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx) => $ctx === ['status' => 500, 'model' => 'claude-test'])->once();
});

it('returns no frames when ffmpeg or the local file is missing', function () {
    [, $f] = capSetup();
    config(['crm.media.ffmpeg_path' => '/nonexistent/ffmpeg']);
    expect((new FrameExtractor)->frames($f))->toBe([]);
});
