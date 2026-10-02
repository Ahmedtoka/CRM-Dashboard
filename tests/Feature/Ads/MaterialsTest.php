<?php

use App\Ads\Buyers\AssignmentService;
use App\Ads\Materials\MaterialFileStorage;
use App\Enums\UserRole;
use App\Media\Thumbnailer;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdDailyMetric;
use App\Models\AdMaterial;
use App\Models\AdMaterialCollection;
use App\Models\AdMaterialFile;
use App\Models\MediaBuyer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['crm.ads.drivers.meta' => 'fake', 'crm.ads.drivers.tiktok' => 'fake', 'crm.ads.drivers.google' => 'fake']);
    $this->withoutVite();
    Storage::fake(config('crm.media.disk'));
    $this->disk = Storage::disk(config('crm.media.disk'));
});

function matUser(UserRole $role): User
{
    return User::factory()->create(['role' => $role]);
}

/** A real (sniffable) mp4: an ISO-BMFF ftyp box padded to the wanted size, so finfo sees video/mp4 whatever the name. */
function matVideo(string $name = 'v.mp4', int $kb = 2000): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", $kb * 1024));
}

function matPayload(array $over = []): array
{
    return array_merge([
        'title' => 'Eid abaya reel', 'types' => ['reel'], 'drive_links' => "https://drive.google.com/a\nhttps://drive.google.com/b",
        'website_links' => 'https://example.com/p, https://example.com/q',
    ], $over);
}

/** @return array{buyer: MediaBuyer, user: User, account: AdAccount, ad: Ad, other: Ad} */
function matBuyerWorld(): array
{
    $user = matUser(UserRole::MediaBuyer);
    $buyer = MediaBuyer::factory()->create(['user_id' => $user->id, 'name' => 'Own Buyer']);
    $account = AdAccount::factory()->create();
    app(AssignmentService::class)->assign($account, $buyer, CarbonImmutable::now('Africa/Cairo')->subDays(40));
    $ad = Ad::factory()->for($account, 'account')->create(['name' => 'Mine ad']);
    $other = Ad::factory()->for(AdAccount::factory()->create(), 'account')->create(['name' => 'Foreign ad']);

    return compact('buyer', 'user', 'account', 'ad', 'other');
}

function matDays(Ad $ad, int $days, float $spend, float $value, float $purchases = 1): void
{
    for ($i = 1; $i <= $days; $i++) {
        AdDailyMetric::factory()->create([
            'ad_id' => $ad->id, 'ad_account_id' => $ad->ad_account_id, 'date' => CarbonImmutable::now('Africa/Cairo')->subDays($i)->toDateString(),
            'spend' => $spend, 'purchase_value' => $value, 'purchases' => $purchases,
        ]);
    }
}

it('lets a content user create a material with files and collections, with a thumbnail for the image', function () {
    $content = matUser(UserRole::Content);
    $c1 = AdMaterialCollection::factory()->create();
    $c2 = AdMaterialCollection::factory()->create();
    $product = Product::factory()->create();

    $this->actingAs($content)->post('/ads/materials', matPayload([
        'product_id' => $product->id, 'collection_ids' => [$c1->id, $c2->id], 'content_notes' => 'notes',
        'files' => [UploadedFile::fake()->image('a.jpg', 800, 600), matVideo()],
    ]))->assertRedirect('/ads/materials');

    $m = AdMaterial::with(['files', 'collections'])->firstOrFail();
    expect($m->created_by_id)->toBe($content->id)->and($m->status)->toBe('not_started')
        ->and($m->collections)->toHaveCount(2)->and($m->files)->toHaveCount(2)
        ->and($m->drive_links)->toBe(['https://drive.google.com/a', 'https://drive.google.com/b'])
        ->and($m->website_links)->toBe(['https://example.com/p', 'https://example.com/q']);

    [$image, $video] = [$m->files[0], $m->files[1]];
    expect($image->mime)->toBe('image/jpeg')->and($image->path)->toMatch('#^ad-materials/\d{4}/\d{2}/[0-9a-f-]{36}\.jpg$#')
        ->and($image->thumb_path)->not->toBeNull()->and($video->mime)->toBe('video/mp4')->and($video->thumb_path)->toBeNull();
    $this->disk->assertExists($image->path);
    $this->disk->assertExists($image->thumb_path);
    $this->disk->assertExists($video->path);
});

it('validates uploads by their real mime and size, links and required fields', function () {
    $content = matUser(UserRole::Content);
    $this->actingAs($content);

    // an html file wearing a .jpg name and an image/jpeg client type
    $fake = UploadedFile::fake()->createWithContent('evil.jpg', '<html><script>alert(1)</script></html>');
    $this->post('/ads/materials', matPayload(['files' => [$fake]]))->assertSessionHasErrors('files.0');
    expect(session('errors')->first('files.0'))->toContain('evil.jpg');

    config(['crm.ads.material_max_mb.image' => 1]);
    $this->post('/ads/materials', matPayload(['files' => [UploadedFile::fake()->image('big.jpg')->size(2048)]]))->assertSessionHasErrors('files.0');

    $this->post('/ads/materials', matPayload(['types' => []]))->assertSessionHasErrors('types');
    $this->post('/ads/materials', matPayload(['types' => ['nope']]))->assertSessionHasErrors('types.0');
    $this->post('/ads/materials', matPayload(['title' => '']))->assertSessionHasErrors('title');
    $this->post('/ads/materials', matPayload(['drive_links' => 'not a url']))->assertSessionHasErrors('drive_links.0');
    $this->post('/ads/materials', matPayload(['ig_links' => 'javascript:alert(1)']))->assertSessionHasErrors('ig_links.0');

    expect(AdMaterial::count())->toBe(0)->and($this->disk->allFiles())->toBe([]);
});

it('keeps media buyers and moderators out of authoring', function () {
    $w = matBuyerWorld();
    $this->actingAs($w['user'])->post('/ads/materials', matPayload())->assertForbidden();
    $this->actingAs($w['user'])->get('/ads/materials/create')->assertForbidden();
    $this->actingAs($w['user'])->post('/ads/collections', ['name' => 'x'])->assertForbidden();
    $this->actingAs(matUser(UserRole::Moderator))->get('/ads/materials')->assertForbidden();
    $this->actingAs(matUser(UserRole::Moderator))->post('/ads/materials', matPayload())->assertForbidden();
});

it('filters the index by status, collection, stock, type and date, with stats over the whole library', function () {
    $col = AdMaterialCollection::factory()->create(['name' => 'Eid']);
    $inStock = Product::factory()->create();
    ProductVariant::factory()->create(['product_id' => $inStock->id, 'inventory_quantity' => 5]);
    ProductVariant::factory()->create(['product_id' => $inStock->id, 'inventory_quantity' => 2]);
    $outStock = Product::factory()->create();
    ProductVariant::factory()->create(['product_id' => $outStock->id, 'inventory_quantity' => 0]);

    $a = AdMaterial::factory()->create(['title' => 'Alpha', 'status' => 'activated', 'types' => ['reel'], 'product_id' => $inStock->id]);
    $b = AdMaterial::factory()->create(['title' => 'Beta', 'status' => 'not_started', 'types' => ['post', 'carousel'], 'product_id' => $outStock->id, 'need_stop_at' => now()]);
    $c = AdMaterial::factory()->create(['title' => 'Gamma', 'status' => 'done', 'types' => ['reel'], 'product_id' => $outStock->id, 'stock_override' => true]);
    $d = AdMaterial::factory()->create(['title' => 'Delta', 'status' => 'not_started', 'types' => ['story']]);
    $a->collections()->attach($col);
    $c->collections()->attach($col);
    $d->forceFill(['created_at' => now()->subDays(20)])->save();

    $admin = matUser(UserRole::Admin);
    $ids = fn (string $qs) => collect($this->actingAs($admin)->get('/ads/materials'.$qs)->assertOk()->viewData('page')['props']['materials']['data'])->pluck('title')->sort()->values()->all();

    expect($ids(''))->toBe(['Alpha', 'Beta', 'Delta', 'Gamma'])
        ->and($ids('?status=not_started'))->toBe(['Beta', 'Delta'])
        ->and($ids('?collection='.$col->id))->toBe(['Alpha', 'Gamma'])
        ->and($ids('?stock=in'))->toBe(['Alpha', 'Gamma'])
        ->and($ids('?stock=out'))->toBe(['Beta'])
        ->and($ids('?stock=none'))->toBe(['Delta'])
        ->and($ids('?type=reel'))->toBe(['Alpha', 'Gamma'])
        ->and($ids('?q=alp'))->toBe(['Alpha'])
        ->and($ids('?from='.now('Africa/Cairo')->subDays(2)->toDateString()))->toBe(['Alpha', 'Beta', 'Gamma'])
        ->and($ids('?to='.now('Africa/Cairo')->subDays(10)->toDateString()))->toBe(['Delta'])
        ->and($ids('?status=bogus&stock=bogus&type=bogus'))->toBe(['Alpha', 'Beta', 'Delta', 'Gamma']);

    $this->actingAs($admin)->get('/ads/materials?status=activated&stock=in')->assertInertia(fn (Assert $p) => $p
        ->component('Ads/Materials/Index')
        ->where('filters.status', 'activated')->where('filters.stock', 'in')->where('filters.q', null)->has('filters.page')
        ->where('stats', ['total' => 4, 'activated' => 1, 'not_started' => 2, 'done' => 1, 'reels' => 2, 'posts' => 1, 'carousels' => 1, 'in_stock' => 2, 'out_of_stock' => 1, 'need_stop' => 1])
        ->has('materials.data', 1)->has('materials.total')
        ->where('materials.data.0.title', 'Alpha')->where('materials.data.0.stock', 'in')->where('materials.data.0.product.inventory', 7)
        ->has('collections', 1)->where('canSeeSpend', true));
});

it('presents a MaterialRow with files, links and linked ads', function () {
    $content = matUser(UserRole::Content);
    $product = Product::factory()->create(['image_url' => 'https://x/y.jpg']);
    $m = AdMaterial::factory()->create(['title' => 'Row', 'product_id' => $product->id, 'created_by_id' => $content->id, 'drive_links' => ['https://d/1'], 'need_stop_at' => now()]);
    $this->actingAs($content)->post('/ads/materials/'.$m->id, ['_method' => 'PUT'] + matPayload(['title' => 'Row', 'files' => [UploadedFile::fake()->image('a.png')]]))->assertRedirect();
    $ad = Ad::factory()->create(['name' => 'Linked']);
    $m->ads()->attach($ad);

    $this->actingAs(matUser(UserRole::Supervisor))->get('/ads/materials')->assertInertia(fn (Assert $p) => $p
        ->has('materials.data.0', fn (Assert $r) => $r
            ->where('title', 'Row')->where('files_count', 1)->where('need_stop', true)->where('stock', 'out')
            ->where('creator.id', $content->id)->where('product.title', $product->title)->where('product.image_url', 'https://x/y.jpg')
            ->where('types', ['reel'])->where('status', 'not_started')->where('buyer', null)
            ->where('ads.0.name', 'Linked')->where('ads.0.platform', $ad->account->platform)
            ->where('performance.spend', 0)->where('performance.roas', null)->where('performance.winner_tier', null)
            ->whereType('thumb_url', 'string')->whereType('created_at', 'string')
            ->etc()));
});

it('hides spend from content users and scopes a buyers numbers to their own rows', function () {
    $w = matBuyerWorld();
    matDays($w['ad'], 10, 100, 500);       // own: spend 1000, value 5000
    matDays($w['other'], 10, 100, 100);    // someone elses
    Order::factory()->create(['ad_id' => $w['ad']->id, 'placed_at' => now()->subDays(2), 'total' => 300]);
    $m = AdMaterial::factory()->create();
    $m->ads()->attach([$w['ad']->id, $w['other']->id]);

    $content = matUser(UserRole::Content);
    $this->actingAs($content)->get('/ads/materials')->assertInertia(fn (Assert $p) => $p
        ->where('canSeeSpend', false)->where('materials.data.0.performance', null)->has('materials.data.0.ads', 2));

    $this->actingAs($w['user'])->get('/ads/materials')->assertInertia(fn (Assert $p) => $p
        ->where('canSeeSpend', true)
        ->has('materials.data.0.ads', 1)->where('materials.data.0.ads.0.name', 'Mine ad')
        ->where('materials.data.0.performance.spend', 1000)->where('materials.data.0.performance.spend_tax', 1140)
        ->where('materials.data.0.performance.purchase_value', 5000)->where('materials.data.0.performance.roas', 5)
        ->where('materials.data.0.performance.purchases', 10)->where('materials.data.0.performance.real_orders', 1)->where('materials.data.0.performance.winner_tier', 'winner'));

    $this->actingAs(matUser(UserRole::Admin))->get('/ads/materials')->assertInertia(fn (Assert $p) => $p
        ->has('materials.data.0.ads', 2)
        ->where('materials.data.0.performance.spend', 2000)->where('materials.data.0.performance.purchase_value', 6000)->where('materials.data.0.performance.roas', 3)
        ->where('materials.data.0.performance.winner_tier', 'winner'));
});

it('computes performance for a page of materials without a query per material', function () {
    $admin = matUser(UserRole::Admin);
    $ads = Ad::factory()->count(3)->create();
    foreach ($ads as $ad) {
        matDays($ad, 3, 200, 400);
    }
    foreach (range(0, 2) as $i) {
        AdMaterial::factory()->create()->ads()->attach($ads[$i]);
    }
    $queries = function () use ($admin) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get('/ads/materials')->assertOk();

        return count(DB::getQueryLog());
    };

    $queries(); // warm per-process caches (settings)
    $few = $queries();
    foreach (range(1, 9) as $i) {
        AdMaterial::factory()->create()->ads()->attach($ads[$i % 3]);
    }

    expect($queries())->toBe($few);
});

it('lets a buyer link ads in their scope and refuses ads outside it', function () {
    $w = matBuyerWorld();
    $m = AdMaterial::factory()->create();
    $foreignLink = Ad::factory()->create();
    $m->ads()->attach($foreignLink); // linked earlier by another buyer or a supervisor

    $this->actingAs($w['user'])->post("/ads/materials/{$m->id}/ads", ['ad_ids' => [$w['ad']->id]])->assertRedirect();
    expect($m->ads()->pluck('ads.id')->sort()->values()->all())->toBe(collect([$w['ad']->id, $foreignLink->id])->sort()->values()->all());

    $this->actingAs($w['user'])->post("/ads/materials/{$m->id}/ads", ['ad_ids' => [$w['ad']->id, $w['other']->id]])->assertForbidden();
    $this->actingAs($w['user'])->post("/ads/materials/{$m->id}/ads", ['ad_ids' => [999999]])->assertSessionHasErrors('ad_ids.0');

    // unlinking an own ad keeps the other buyers link
    $this->actingAs($w['user'])->post("/ads/materials/{$m->id}/ads", ['ad_ids' => []])->assertRedirect();
    expect($m->ads()->pluck('ads.id')->all())->toBe([$foreignLink->id]);

    // a supervisor may link anything and replace the set
    $this->actingAs(matUser(UserRole::Supervisor))->post("/ads/materials/{$m->id}/ads", ['ad_ids' => [$w['other']->id]])->assertRedirect();
    expect($m->ads()->pluck('ads.id')->all())->toBe([$w['other']->id]);

    $this->actingAs(matUser(UserRole::Content))->post("/ads/materials/{$m->id}/ads", ['ad_ids' => []])->assertForbidden();
});

it('searches ads in scope, at most 20, by name or external id', function () {
    $w = matBuyerWorld();
    Ad::factory()->count(25)->for($w['account'], 'account')->create(['name' => 'Bulk ad']);
    $needle = Ad::factory()->for($w['account'], 'account')->create(['name' => 'zzz', 'external_id' => '99887766']);

    $buyer = $this->actingAs($w['user'])->getJson('/ads/materials/ad-search?q=ad')->assertOk();
    expect($buyer->json())->toHaveCount(20)->and(collect($buyer->json())->pluck('name')->contains('Foreign ad'))->toBeFalse();
    expect($this->actingAs($w['user'])->getJson('/ads/materials/ad-search?q=9988')->json('0.id'))->toBe($needle->id);
    expect($this->actingAs($w['user'])->getJson('/ads/materials/ad-search?q=Foreign')->json())->toBe([]);

    $admin = $this->actingAs(matUser(UserRole::Admin))->getJson('/ads/materials/ad-search?q=Foreign')->assertOk();
    expect($admin->json('0.name'))->toBe('Foreign ad')->and($admin->json('0'))->toHaveKeys(['id', 'name', 'external_id', 'platform']);

    $this->actingAs(matUser(UserRole::Content))->getJson('/ads/materials/ad-search?q=a')->assertForbidden();
});

it('moves a material through its statuses and sets the timestamps', function () {
    $w = matBuyerWorld();
    $m = AdMaterial::factory()->create();

    $this->actingAs($w['user'])->post("/ads/materials/{$m->id}/status", ['status' => 'activated'])->assertRedirect();
    $m->refresh();
    expect($m->status)->toBe('activated')->and($m->activated_at)->not->toBeNull()->and($m->done_at)->toBeNull()
        ->and($m->media_buyer_id)->toBe($w['buyer']->id); // the buyer who activates takes it

    $this->travel(1)->hours();
    $first = $m->activated_at;
    $this->actingAs($w['user'])->post("/ads/materials/{$m->id}/status", ['status' => 'done'])->assertRedirect();
    $m->refresh();
    expect($m->status)->toBe('done')->and($m->done_at)->not->toBeNull()->and($m->activated_at->equalTo($first))->toBeTrue();

    $this->actingAs(matUser(UserRole::Supervisor))->post("/ads/materials/{$m->id}/status", ['status' => 'not_started'])->assertRedirect();
    $m->refresh();
    expect($m->status)->toBe('not_started')->and($m->activated_at)->toBeNull()->and($m->done_at)->toBeNull();

    $this->actingAs($w['user'])->post("/ads/materials/{$m->id}/status", ['status' => 'bogus'])->assertSessionHasErrors('status');
});

it('lets content only send a material back to not started', function () {
    $content = matUser(UserRole::Content);
    $m = AdMaterial::factory()->create(['status' => 'activated', 'activated_at' => now()]);

    $this->actingAs($content)->post("/ads/materials/{$m->id}/status", ['status' => 'activated'])->assertForbidden();
    $this->actingAs($content)->post("/ads/materials/{$m->id}/status", ['status' => 'done'])->assertForbidden();
    $this->actingAs($content)->post("/ads/materials/{$m->id}/status", ['status' => 'not_started'])->assertRedirect();
    expect($m->refresh()->status)->toBe('not_started')->and($m->activated_at)->toBeNull();
});

it('exports the library as a streamed CSV with a BOM and one line per material', function () {
    $col = AdMaterialCollection::factory()->create(['name' => 'عيد']);
    $product = Product::factory()->create(['title' => 'عباية']);
    $ad = Ad::factory()->create(['name' => 'Ad one']);
    matDays($ad, 4, 150, 450);
    $a = AdMaterial::factory()->create(['title' => 'فيديو العيد', 'product_id' => $product->id, 'drive_links' => ['https://d/1', 'https://d/2'], 'status' => 'activated']);
    $a->collections()->attach($col);
    $a->ads()->attach($ad);
    AdMaterial::factory()->create(['title' => 'Plain']);

    $res = $this->actingAs(matUser(UserRole::Admin))->get('/ads/materials/export')->assertOk();
    $body = $res->streamedContent();
    expect($res->headers->get('content-type'))->toContain('text/csv')
        ->and(str_starts_with($body, "\xEF\xBB\xBF"))->toBeTrue();
    $rows = array_map('str_getcsv', array_values(array_filter(preg_split('/\r?\n/', substr($body, 3)))));
    expect($rows)->toHaveCount(3)->and($rows[0])->toHaveCount(11);
    $line = collect($rows)->first(fn ($r) => $r[0] === 'فيديو العيد');
    expect($line[2])->toBe('عباية')->and($line[3])->toBe('عيد')->and($line[7])->toBe('https://d/1 | https://d/2')->and($line[8])->toBe('Ad one')
        ->and((float) $line[9])->toBe(600.0)->and((float) $line[10])->toBe(3.0);

    // content users get no spend columns; filters apply
    $content = $this->actingAs(matUser(UserRole::Content))->get('/ads/materials/export?status=activated')->streamedContent();
    $crow = array_map('str_getcsv', array_values(array_filter(preg_split('/\r?\n/', substr($content, 3)))));
    expect($crow)->toHaveCount(2)->and($crow[0])->toHaveCount(9);
});

it('streams material files to ads roles only, with range support', function () {
    $content = matUser(UserRole::Content);
    $this->actingAs($content)->post('/ads/materials', matPayload(['files' => [UploadedFile::fake()->image('a.jpg'), matVideo('v.mp4', 64)]]));
    [$image, $video] = AdMaterialFile::orderBy('id')->get()->all();

    $this->actingAs($content)->get("/ads/materials/files/{$image->id}")->assertOk()->assertHeader('content-type', 'image/jpeg')
        ->assertHeader('x-content-type-options', 'nosniff');
    $this->actingAs($content)->get("/ads/materials/files/{$image->id}/thumb")->assertOk()->assertHeader('content-type', 'image/webp');
    $this->actingAs($content)->get("/ads/materials/files/{$video->id}/thumb")->assertNotFound();

    $range = $this->actingAs($content)->get("/ads/materials/files/{$video->id}", ['Range' => 'bytes=0-99']);
    expect($range->getStatusCode())->toBe(206)->and($range->headers->get('content-range'))->toStartWith('bytes 0-99/');

    $this->actingAs(matUser(UserRole::Moderator))->get("/ads/materials/files/{$image->id}")->assertForbidden();
    $this->actingAs(matUser(UserRole::Moderator))->get("/ads/materials/files/{$image->id}/thumb")->assertForbidden();
    $this->actingAs(matUser(UserRole::MediaBuyer))->get("/ads/materials/files/{$image->id}")->assertOk();
});

it('removes files from disk when a file is removed or the material deleted', function () {
    $content = matUser(UserRole::Content);
    $this->actingAs($content)->post('/ads/materials', matPayload(['files' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.png')]]));
    $m = AdMaterial::firstOrFail();
    [$a, $b] = $m->files()->get()->all();
    $kept = $b->path;
    $paths = [$a->path, $a->thumb_path, $b->path, $b->thumb_path];
    foreach ($paths as $p) {
        $this->disk->assertExists($p);
    }

    $this->actingAs($content)->put("/ads/materials/{$m->id}", matPayload(['title' => 'Renamed', 'remove_file_ids' => [$a->id], 'files' => [UploadedFile::fake()->image('c.gif')]]))->assertRedirect();
    $this->disk->assertMissing($a->path);
    $this->disk->assertMissing($a->thumb_path);
    $this->disk->assertExists($kept);
    expect($m->refresh()->title)->toBe('Renamed')->and($m->files()->count())->toBe(2);

    $this->actingAs($content)->delete("/ads/materials/{$m->id}")->assertRedirect('/ads/materials');
    expect(AdMaterial::count())->toBe(0)->and($this->disk->allFiles())->toBe([]);
});

it('lets only a supervisor or the creator delete a material', function () {
    $owner = matUser(UserRole::Content);
    $mine = AdMaterial::factory()->create(['created_by_id' => $owner->id]);
    $theirs = AdMaterial::factory()->create(['created_by_id' => matUser(UserRole::Content)->id]);
    $w = matBuyerWorld();

    $this->actingAs($w['user'])->delete("/ads/materials/{$mine->id}")->assertForbidden();
    $this->actingAs($owner)->delete("/ads/materials/{$theirs->id}")->assertForbidden();
    $this->actingAs($owner)->delete("/ads/materials/{$mine->id}")->assertRedirect();
    $this->actingAs(matUser(UserRole::Supervisor))->delete("/ads/materials/{$theirs->id}")->assertRedirect();
    expect(AdMaterial::count())->toBe(0);
});

it('renders the create and edit forms with their props', function () {
    $buyer = MediaBuyer::factory()->create(['name' => 'B1']);
    MediaBuyer::factory()->create(['is_active' => false]);
    $c = AdMaterialCollection::factory()->create(['name' => 'Live']);
    $inactive = AdMaterialCollection::factory()->create(['name' => 'Old', 'is_active' => false]);
    $m = AdMaterial::factory()->create(['title' => 'Edit me']);
    $m->collections()->attach($inactive);
    $content = matUser(UserRole::Content);
    $this->actingAs($content)->put("/ads/materials/{$m->id}", matPayload(['title' => 'Edit me', 'collection_ids' => [$inactive->id], 'files' => [UploadedFile::fake()->image('a.jpg')]]));

    $this->actingAs($content)->get('/ads/materials/create')->assertInertia(fn (Assert $p) => $p
        ->component('Ads/Materials/Form')->where('material', null)->has('collections', 1)
        ->where('types', ['reel', 'carousel', 'post', 'story', 'image', 'video'])->has('buyers', 1)->where('buyers.0.id', $buyer->id));

    $this->actingAs($content)->get("/ads/materials/{$m->id}/edit")->assertInertia(fn (Assert $p) => $p
        ->where('material.title', 'Edit me')->has('material.files', 1)->has('material.files.0', fn (Assert $f) => $f
        ->has('id')->has('url')->has('thumb_url')->where('mime', 'image/jpeg')->where('original_name', 'a.jpg')->has('size'))
        ->has('collections', 2));
});

it('lists collections with their counts and totals, and manages them', function () {
    $content = matUser(UserRole::Content);
    $a = AdMaterialCollection::factory()->create(['name' => 'A', 'sort' => 1]);
    $b = AdMaterialCollection::factory()->create(['name' => 'B', 'sort' => 2, 'is_active' => false]);
    $m1 = AdMaterial::factory()->create(['status' => 'activated', 'need_stop_at' => now()]);
    $m2 = AdMaterial::factory()->create(['status' => 'not_started']);
    $m3 = AdMaterial::factory()->create(['status' => 'done']);
    AdMaterial::factory()->create(['status' => 'not_started']); // in no collection
    $a->materials()->attach([$m1->id, $m2->id, $m3->id]);
    $b->materials()->attach([$m1->id]);

    $this->actingAs($content)->get('/ads/collections')->assertInertia(fn (Assert $p) => $p
        ->component('Ads/Materials/Collections')
        ->where('collections.0', ['id' => $a->id, 'name' => 'A', 'is_active' => true, 'materials' => 3, 'activated' => 1, 'not_started' => 1, 'need_stop' => 1, 'done' => 1])
        ->where('collections.1', ['id' => $b->id, 'name' => 'B', 'is_active' => false, 'materials' => 1, 'activated' => 1, 'not_started' => 0, 'need_stop' => 1, 'done' => 0])
        ->where('totals', ['collections' => 2, 'materials' => 4, 'activated' => 1, 'not_started' => 2, 'need_stop' => 1, 'done' => 1]));

    $this->actingAs($content)->post('/ads/collections', ['name' => 'New'])->assertRedirect();
    $new = AdMaterialCollection::where('name', 'New')->firstOrFail();
    expect($new->is_active)->toBeTrue()->and($new->sort)->toBe(3);
    $this->actingAs($content)->put("/ads/collections/{$new->id}", ['name' => 'Renamed', 'is_active' => false])->assertRedirect();
    expect($new->refresh()->name)->toBe('Renamed')->and($new->is_active)->toBeFalse();
    $this->actingAs($content)->post('/ads/collections', ['name' => ''])->assertSessionHasErrors('name');
    $this->actingAs($content)->delete("/ads/collections/{$a->id}")->assertRedirect();
    expect(AdMaterialCollection::count())->toBe(2)->and(AdMaterial::count())->toBe(4)->and($m1->collections()->count())->toBe(1);

    $w = matBuyerWorld();
    $this->actingAs($w['user'])->get('/ads/collections')->assertOk();
    $this->actingAs($w['user'])->delete("/ads/collections/{$b->id}")->assertForbidden();
});

it('lists materials by product inventory on the stock page and applies the manual override', function () {
    $p1 = Product::factory()->create(['title' => 'Abaya']);
    ProductVariant::factory()->create(['product_id' => $p1->id, 'title' => 'S', 'price' => 100, 'inventory_quantity' => 4, 'sku' => 'A-S']);
    ProductVariant::factory()->create(['product_id' => $p1->id, 'title' => 'M', 'price' => 150, 'inventory_quantity' => 6, 'sku' => 'A-M']);
    $p2 = Product::factory()->create(['title' => 'Scarf']);
    ProductVariant::factory()->create(['product_id' => $p2->id, 'price' => 50, 'inventory_quantity' => 0]);
    $col = AdMaterialCollection::factory()->create(['name' => 'Eid']);
    $m1 = AdMaterial::factory()->create(['title' => 'Abaya reel', 'product_id' => $p1->id]);
    $m1->collections()->attach($col);
    $m2 = AdMaterial::factory()->create(['title' => 'Scarf reel', 'product_id' => $p2->id]);
    AdMaterial::factory()->create(['title' => 'No product']);

    $content = matUser(UserRole::Content);
    $this->actingAs($content)->get('/ads/stock')->assertInertia(fn (Assert $p) => $p
        ->component('Ads/Materials/Stock')
        ->where('filters', ['min_qty' => null, 'availability' => 'all'])
        ->has('rows.data', 2)->where('rows.total', 2)
        ->where('rows.data.1.material_id', $m1->id)->where('rows.data.1.quantity', 10)->where('rows.data.1.availability', true)
        ->where('rows.data.1.product', ['id' => $p1->id, 'title' => 'Abaya'])->where('rows.data.1.price', ['min' => 100, 'max' => 150])
        ->has('rows.data.1.variants', 2)->where('rows.data.1.collections.0.name', 'Eid')
        ->where('rows.data.0.material_id', $m2->id)->where('rows.data.0.availability', false));

    $this->actingAs($content)->get('/ads/stock?availability=out')->assertInertia(fn (Assert $p) => $p->has('rows.data', 1)->where('rows.data.0.material_id', $m2->id));
    $this->actingAs($content)->get('/ads/stock?min_qty=5')->assertInertia(fn (Assert $p) => $p->has('rows.data', 1)->where('rows.data.0.material_id', $m1->id)->where('filters.min_qty', 5));

    $this->actingAs($content)->post("/ads/stock/{$m2->id}/availability", ['available' => true])->assertRedirect();
    expect($m2->refresh()->stock_override)->toBeTrue();
    $this->actingAs($content)->get('/ads/stock?availability=in')->assertInertia(fn (Assert $p) => $p->has('rows.data', 2));
    $this->actingAs($content)->get('/ads/materials?stock=in')->assertInertia(fn (Assert $p) => $p->has('materials.data', 2));

    $this->actingAs($content)->post("/ads/stock/{$m1->id}/availability", ['available' => false])->assertRedirect();
    expect($m1->refresh()->stock_override)->toBeFalse();
    $this->actingAs($content)->get('/ads/stock?availability=out')->assertInertia(fn (Assert $p) => $p->has('rows.data', 1)->where('rows.data.0.material_id', $m1->id));

    $this->actingAs($content)->post("/ads/stock/{$m1->id}/availability", ['available' => null])->assertRedirect();
    expect($m1->refresh()->stock_override)->toBeNull();

    $this->actingAs($content)->post("/ads/stock/{$m1->id}/availability", [])->assertSessionHasErrors('available');
    $this->actingAs(matBuyerWorld()['user'])->post("/ads/stock/{$m1->id}/availability", ['available' => true])->assertForbidden();
});

it('paginates the stock page by 10 and the library by 15', function () {
    $product = Product::factory()->create();
    ProductVariant::factory()->create(['product_id' => $product->id, 'inventory_quantity' => 3]);
    AdMaterial::factory()->count(17)->create(['product_id' => $product->id]);
    $admin = matUser(UserRole::Admin);

    $this->actingAs($admin)->get('/ads/stock?page=2')->assertInertia(fn (Assert $p) => $p->has('rows.data', 7)->where('rows.last_page', 2));
    $this->actingAs($admin)->get('/ads/materials?page=2')->assertInertia(fn (Assert $p) => $p->has('materials.data', 2)->where('filters.page', 2));
});

it('searches active products for the form', function () {
    $p = Product::factory()->create(['title' => 'Silk abaya', 'image_url' => 'https://x/s.jpg']);
    ProductVariant::factory()->create(['product_id' => $p->id, 'inventory_quantity' => 3]);
    ProductVariant::factory()->create(['product_id' => $p->id, 'inventory_quantity' => 4]);
    Product::factory()->create(['title' => 'Silk hidden', 'status' => 'draft']);
    Product::factory()->create(['title' => 'Other']);

    $res = $this->actingAs(matUser(UserRole::Content))->getJson('/ads/products/search?q=silk')->assertOk();
    expect($res->json())->toBe([['id' => $p->id, 'title' => 'Silk abaya', 'image_url' => 'https://x/s.jpg', 'inventory' => 7]]);
    $this->actingAs(matUser(UserRole::Moderator))->getJson('/ads/products/search?q=silk')->assertForbidden();
});

it('makes a thumbnail for any stored image by path and keeps non-images out', function () {
    $disk = config('crm.media.disk');
    Storage::disk($disk)->put('x/a.jpg', UploadedFile::fake()->image('a.jpg', 1200, 900)->getContent());
    $path = app(Thumbnailer::class)->thumbnailForPath($disk, 'x/a.jpg', 'image/jpeg', 'custom');

    expect($path)->toStartWith('custom/');
    Storage::disk($disk)->assertExists($path);
    [$w, $h] = getimagesizefromstring(Storage::disk($disk)->get($path));
    expect(max($w, $h))->toBe(480)
        ->and(app(Thumbnailer::class)->thumbnailForPath($disk, 'x/a.jpg', 'video/mp4'))->toBeNull()
        ->and(app(Thumbnailer::class)->thumbnailForPath($disk, 'missing.jpg', 'image/jpeg'))->toBeNull();
});

it('asks ffmpeg for a video poster only when it is configured', function () {
    Process::fake();
    config(['crm.media.ffmpeg_path' => 'ffmpeg']);
    $this->actingAs(matUser(UserRole::Content))->post('/ads/materials', matPayload(['files' => [matVideo('v.mov', 16)]]))->assertRedirect();

    Process::assertRan(fn ($p) => is_array($p->command) && $p->command[0] === 'ffmpeg' && in_array('-ss', $p->command, true));
    expect(AdMaterialFile::firstOrFail()->thumb_path)->toBeNull(); // the fake ffmpeg wrote no frame
});

it('keeps links the update request does not send, and cleans the blob when the file row fails', function () {
    $content = matUser(UserRole::Content);
    $m = AdMaterial::factory()->create(['drive_links' => ['https://d/1'], 'website_links' => ['https://w/1']]);

    $this->actingAs($content)->put("/ads/materials/{$m->id}", ['title' => 'T', 'types' => ['post']])->assertRedirect();
    expect($m->refresh()->drive_links)->toBe(['https://d/1'])->and($m->website_links)->toBe(['https://w/1']);

    AdMaterialFile::creating(fn () => throw new RuntimeException('db down'));
    expect(fn () => app(MaterialFileStorage::class)->store($m, UploadedFile::fake()->image('a.jpg')))->toThrow(RuntimeException::class);
    expect($this->disk->allFiles())->toBe([]);
});
