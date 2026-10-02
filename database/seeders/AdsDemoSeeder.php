<?php

namespace Database\Seeders;

use App\Ads\Attribution\OrderAttribution;
use App\Ads\Buyers\AssignmentService;
use App\Ads\Commands\ImportArenaTokenCommand;
use App\Ads\Materials\MaterialFileStorage;
use App\Ads\Materials\StockWatcher;
use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Ads\Sync\AdsSyncService;
use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Ad;
use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\AdDailyMetric;
use App\Models\AdMaterial;
use App\Models\AdMaterialCollection;
use App\Models\AdPlatformConnection;
use App\Models\BuyerTarget;
use App\Models\MediaBuyer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Ads Hub demo data on top of the base demo (DemoSeeder): three media buyers and a content user, fake
 * Meta / TikTok / Google connections synced 90 days through the fake driver, dated account assignments
 * (one hand-over to show history), monthly targets, the Le Voile material collections, 30 materials with
 * images, and a handful of ad-attributed orders.
 *
 * Idempotent: re-running updates rows instead of duplicating them. When a real Meta connection imported by
 * `ads:import-arena-token` exists, no fake Meta connection is created and the real accounts and their
 * live-synced data are left alone (only assignments are added to them when they have none).
 *
 * Demo users share the DemoSeeder password convention ("password"); local/testing only.
 */
class AdsDemoSeeder extends Seeder
{
    public const FAKE_PREFIX = 'Demo — ';

    public const BUYERS = [
        'ahmed' => ['email' => 'ahmed.gamal@crm.test', 'name' => 'أحمد جمال', 'color' => '#2563EB'],
        'mostafa' => ['email' => 'mostafa@crm.test', 'name' => 'مصطفى', 'color' => '#D97706'],
        'bakinam' => ['email' => 'bakinam@crm.test', 'name' => 'باكينام', 'color' => '#DB2777'],
    ];

    public const CONTENT_EMAIL = 'content@crm.test';

    /** Ad account external ids as the fake driver names them (obviously fake, never a real account id). */
    public const CLOTING = FakeAdsDriver::META_CLOTING;

    public const LV_MAIN = FakeAdsDriver::META_MAIN;

    public const LV_MAIN_22 = FakeAdsDriver::META_MAIN_22;

    public const TIKTOK = FakeAdsDriver::TIKTOK;

    public const GOOGLE = FakeAdsDriver::GOOGLE;

    public const COLLECTIONS = ['SS25', 'Slow Movers', 'Basics & Extensions', 'scarves', 'offers', 'Clothing', 'Isdal', 'accessories', 'Mrs. Sara', 'Summer 2026', 'Burkini 26', 'Ramadan 2026'];

    private const DAYS = 90;

    /** How many orders end up with a utm ad attribution. */
    private const ATTRIBUTED_ORDERS = 12;

    /** [title, types, collections (indexes into COLLECTIONS), status, buyer key|null] */
    private const MATERIALS = [
        ['إسدال كريب أسود - ريل التجربة', ['reel'], [6, 11], 'activated', 'ahmed'],
        ['بوركيني ٢٦ - كاروسيل الألوان', ['carousel'], [10, 9], 'activated', 'mostafa'],
        ['طرحة شيفون سادة - بوست', ['post'], [3], 'activated', 'bakinam'],
        ['فستان صيفي كتان - ريل', ['reel', 'video'], [9, 0], 'activated', 'ahmed'],
        ['تخفيضات آخر الموسم - ستوري', ['story'], [4, 1], 'done', 'mostafa'],
        ['كولكشن مسز سارة - فيديو', ['video'], [8], 'activated', 'ahmed'],
        ['إكستنشن بيزك - صورة', ['image'], [2], 'done', 'bakinam'],
        ['عباية رمضان مطرزة - ريل', ['reel'], [11, 6], 'done', 'ahmed'],
        ['شنطة جلد صغيرة - كاروسيل', ['carousel'], [7], 'not_started', null],
        ['طقم صيفي قطن - بوست', ['post', 'image'], [9, 5], 'activated', 'mostafa'],
        ['بادي بيزك كم طويل - ستوري', ['story'], [2], 'not_started', null],
        ['إسدال صلاة بجيوب - فيديو', ['video'], [6], 'activated', 'bakinam'],
        ['طرح جيرسيه ٦ ألوان - كاروسيل', ['carousel'], [3, 2], 'done', 'bakinam'],
        ['بوركيني بناتي - ريل', ['reel'], [10], 'not_started', null],
        ['جيبة بليسيه - صورة', ['image'], [5, 0], 'activated', 'ahmed'],
        ['عرض ٢ قطعة بسعر واحدة - ستوري', ['story'], [4], 'activated', 'mostafa'],
        ['كارديجان تريكو - ريل', ['reel'], [5, 1], 'not_started', null],
        ['بروش ودبابيس طرح - بوست', ['post'], [7], 'not_started', null],
        ['فستان سواريه رمضان - فيديو', ['video'], [11], 'done', 'ahmed'],
        ['بنطلون واسع كتان - كاروسيل', ['carousel'], [9, 5], 'activated', 'ahmed'],
        ['إسدال أطفال - صورة', ['image'], [6], 'not_started', null],
        ['طرحة ساتان - ريل', ['reel'], [3], 'done', 'bakinam'],
        ['قطع بطيئة الحركة - كاروسيل', ['carousel'], [1, 4], 'activated', 'mostafa'],
        ['بلوزة شيفون مشجرة - ستوري', ['story'], [0, 5], 'not_started', null],
        ['بوركيني كامل - فيديو', ['video'], [10, 9], 'done', 'mostafa'],
        ['اكسسوارات شعر - صورة', ['image'], [7], 'not_started', null],
        ['تونيك مشجر - ريل', ['reel'], [5], 'not_started', null],
        ['كولكشن مسز سارة الجديد - كاروسيل', ['carousel'], [8, 9], 'activated', 'bakinam'],
        ['بيزك تيشيرت قطن - بوست', ['post'], [2], 'not_started', null],
        ['إسدال شتوي صوف - ريل', ['reel'], [6, 4], 'not_started', null],
    ];

    /** Material indexes that are activated but whose stock is marked unavailable by hand. */
    private const OUT_OF_STOCK = [3, 15];

    /** @var array<string, MediaBuyer> */
    private array $buyers = [];

    public function run(AdsSyncService $sync, AssignmentService $assignments, OrderAttribution $attribution, StockWatcher $stockWatcher, MaterialFileStorage $files): void
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('Skipping AdsDemoSeeder: demo data is only seeded in local/testing environments.');

            return;
        }

        $today = CarbonImmutable::now('Africa/Cairo')->startOfDay();
        $content = $this->seedUsers();
        $this->syncFakeConnections($sync, $today);
        $this->seedAssignments($assignments, $today);
        $this->seedTargets($today);
        $collections = $this->seedCollections();
        $this->seedMaterials($collections, $content, $files, $today);
        $this->attributeOrders($attribution);
        $stockWatcher->run();
    }

    private function seedUsers(): User
    {
        foreach (self::BUYERS as $key => $b) {
            $user = User::updateOrCreate(['email' => $b['email']], [
                'name' => $b['name'], 'password' => 'password', 'role' => UserRole::MediaBuyer, 'locale' => 'ar',
                'is_active' => true, 'color' => $b['color'], 'email_verified_at' => now(),
            ]);
            $this->buyers[$key] = MediaBuyer::updateOrCreate(['user_id' => $user->id], ['name' => $b['name'], 'color' => $b['color'], 'is_active' => true]);
        }

        return User::updateOrCreate(['email' => self::CONTENT_EMAIL], [
            'name' => 'منة الله - المحتوى', 'password' => 'password', 'role' => UserRole::Content, 'locale' => 'ar',
            'is_active' => true, 'color' => '#0891B2', 'email_verified_at' => now(),
        ]);
    }

    /** Fake connections (Meta only when no real Arena-token connection exists), each account synced over 90 days with the fake driver. */
    private function syncFakeConnections(AdsSyncService $sync, CarbonImmutable $today): void
    {
        $platforms = ['tiktok' => 'TikTok', 'google' => 'Google Ads'];
        if (! AdPlatformConnection::where('platform', 'meta')->where('name', ImportArenaTokenCommand::CONNECTION_NAME)->exists()) {
            $platforms = ['meta' => 'Meta'] + $platforms;
        }

        $previous = config('crm.ads.drivers');
        config(['crm.ads.drivers' => ['meta' => 'fake', 'tiktok' => 'fake', 'google' => 'fake']]);
        try {
            foreach ($platforms as $platform => $label) {
                $connection = AdPlatformConnection::updateOrCreate(
                    ['platform' => $platform, 'name' => self::FAKE_PREFIX.$label],
                    ['credentials' => ['access_token' => 'demo-'.$platform], 'status' => 'connected', 'last_error' => null],
                );
                $sync->syncAccounts($connection);
                foreach (AdAccount::where('connection_id', $connection->id)->get() as $account) {
                    $sync->syncAccount($account, $today->subDays(self::DAYS - 1), $today, 'backfill');
                }
            }
        } finally {
            config(['crm.ads.drivers' => $previous]);
        }
    }

    /** Only for accounts with no assignment history, so a re-run (or a hand-made change) is never fought. */
    private function seedAssignments(AssignmentService $assignments, CarbonImmutable $today): void
    {
        $from = $today->subDays(self::DAYS - 1);
        $plan = [
            self::CLOTING => [['bakinam', $from]],
            self::LV_MAIN => [['ahmed', $from]],
            self::LV_MAIN_22 => [['mostafa', $from], ['ahmed', $today->subDays(20)]],
            self::TIKTOK => [['mostafa', $from]],
        ];

        foreach ($plan as $externalId => $steps) {
            $account = AdAccount::where('external_id', $externalId)->first();
            if ($account === null) {
                $this->command?->warn("Ad account {$externalId} not found: no assignment seeded.");

                continue;
            }
            if (AdAccountAssignment::where('ad_account_id', $account->id)->exists()) {
                continue;
            }
            foreach ($steps as [$buyer, $start]) {
                $assignments->assign($account, $this->buyers[$buyer], $start);
            }
        }
    }

    /** Monthly budgets sized from the spend of the accounts each buyer holds (fake or live data alike), a little above it. */
    private function seedTargets(CarbonImmutable $today): void
    {
        $plan = ['ahmed' => [1.1, 3.0], 'mostafa' => [0.9, 2.6], 'bakinam' => [1.25, 2.8]];
        foreach ($plan as $key => [$headroom, $roas]) {
            $accounts = AdAccountAssignment::where('media_buyer_id', $this->buyers[$key]->id)->whereNull('ends_on')->pluck('ad_account_id');
            $spend = (float) AdDailyMetric::whereIn('ad_account_id', $accounts)->where('date', '>', $today->subDays(30)->toDateString())->sum('spend');
            $budget = max(50000, round($spend * (1 + (float) config('crm.ads.tax_rate', 0.14)) * $headroom, -4));
            for ($i = 0; $i < 3; $i++) {
                $month = $today->startOfMonth()->subMonthsNoOverflow($i);
                $target = BuyerTarget::where('media_buyer_id', $this->buyers[$key]->id)->whereDate('month', $month->toDateString())->first()
                    ?? new BuyerTarget(['media_buyer_id' => $this->buyers[$key]->id, 'month' => $month->toDateString()]);
                $target->fill(['budget' => $budget, 'target_roas' => $roas])->save();
            }
        }
    }

    /** @return list<AdMaterialCollection> in COLLECTIONS order */
    private function seedCollections(): array
    {
        $out = [];
        foreach (self::COLLECTIONS as $sort => $name) {
            $out[] = AdMaterialCollection::updateOrCreate(['name' => $name], ['is_active' => true, 'sort' => $sort]);
        }

        return $out;
    }

    /** @param list<AdMaterialCollection> $collections */
    private function seedMaterials(array $collections, User $content, MaterialFileStorage $files, CarbonImmutable $today): void
    {
        $products = Product::query()->whereNull('deleted_at')->whereHas('variants')->orderBy('id')->limit(count(self::MATERIALS))->pluck('id')->all();

        foreach (self::MATERIALS as $i => [$title, $types, $collectionIdx, $status, $buyerKey]) {
            $buyer = $buyerKey !== null ? $this->buyers[$buyerKey] : null;
            $activatedAt = $status === 'not_started' ? null : $today->subDays(40 - $i)->setTime(11, 0)->utc();
            $slug = 'levoile-'.($i + 1);

            $material = AdMaterial::updateOrCreate(['title' => $title], [
                'product_id' => $products === [] ? null : $products[$i % count($products)],
                'types' => $types,
                'status' => $status,
                'website_links' => $i % 3 === 0 ? ['https://levoile.example/products/'.$slug] : [],
                'drive_links' => $i % 2 === 0 ? ['https://drive.google.com/drive/folders/demo-'.$slug] : [],
                'ig_links' => $i % 4 === 1 ? ['https://www.instagram.com/p/demo'.($i + 1).'/'] : [],
                'content_notes' => $i % 3 === 1 ? 'التصوير على خلفية فاتحة، ركزي على الخامة والتفاصيل.' : null,
                'media_buyer_id' => $buyer?->id,
                'created_by_id' => $content->id,
                'activated_at' => $activatedAt,
                'done_at' => $status === 'done' ? $today->subDays(max(1, 20 - $i))->setTime(16, 0)->utc() : null,
                'stock_override' => in_array($i, self::OUT_OF_STOCK, true) ? false : null,
            ]);

            $material->collections()->sync(array_map(fn ($k) => $collections[$k]->id, $collectionIdx));

            if ($buyer !== null && $status !== 'not_started') {
                $material->ads()->sync($this->adsFor($buyer, $i));
            }

            if (! $material->files()->exists()) {
                $this->attachImage($material, $files, $slug, $i);
            }
        }
    }

    /** Up to two ads (with spend) from the accounts the buyer holds today, spread by the material index. @return list<int> */
    private function adsFor(MediaBuyer $buyer, int $i): array
    {
        $accountIds = AdAccountAssignment::where('media_buyer_id', $buyer->id)->whereNull('ends_on')->pluck('ad_account_id');
        $ads = Ad::whereIn('ad_account_id', $accountIds)
            ->whereIn('id', AdDailyMetric::query()->whereIn('ad_account_id', $accountIds)->select('ad_id'))
            ->orderBy('id')->pluck('id')->all();
        if ($ads === [] || $i % 5 === 4) { // a few activated materials are not linked yet
            return [];
        }
        $n = count($ads);

        return array_values(array_unique([$ads[($i * 7) % $n], $ads[($i * 7 + 3) % $n]]));
    }

    /** A picsum photo (seeded, so stable); a generated placeholder when offline. */
    private function attachImage(AdMaterial $material, MaterialFileStorage $files, string $slug, int $i): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'adsdemo');
        try {
            $bytes = null;
            try {
                $response = Http::timeout(20)->get("https://picsum.photos/seed/{$slug}/720/900.jpg");
                $bytes = $response->successful() ? $response->body() : null;
            } catch (Throwable) {
                $bytes = null;
            }
            file_put_contents($tmp, $bytes ?: $this->placeholder($i));
            $files->store($material, new UploadedFile($tmp, $slug.'.jpg', 'image/jpeg', null, true));
        } catch (Throwable $e) {
            $this->command?->warn("Image for {$material->title} skipped: ".$e->getMessage());
        } finally {
            @unlink($tmp);
        }
    }

    private function placeholder(int $i): string
    {
        $img = imagecreatetruecolor(720, 900);
        $palette = [[219, 39, 119], [37, 99, 235], [217, 119, 6], [5, 150, 105], [124, 58, 237]];
        [$r, $g, $b] = $palette[$i % count($palette)];
        imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $b));
        ob_start();
        imagejpeg($img, null, 80);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    /** Gives a handful of the latest orders a utm ad id from ads with spend, then runs the real attribution over them. */
    private function attributeOrders(OrderAttribution $attribution): void
    {
        $missing = self::ATTRIBUTED_ORDERS - Order::where('utm_medium', 'paid')->where('utm_source', 'facebook')->count();
        if ($missing > 0) {
            $ads = Ad::whereIn('id', AdDailyMetric::query()->select('ad_id'))->orderBy('id')->pluck('external_id')->all();
            $orders = Order::whereNull('utm_source')->where('status', '!=', OrderStatus::Cancelled->value)
                ->orderByDesc('placed_at')->orderByDesc('id')->limit($missing)->get(['id', 'placed_at']);
            $now = CarbonImmutable::now();
            foreach ($orders as $k => $order) {
                if ($ads === []) {
                    break;
                }
                $placed = CarbonImmutable::parse((string) $order->placed_at);
                Order::whereKey($order->id)->toBase()->update([
                    'utm_source' => 'facebook', 'utm_medium' => 'paid', 'utm_content' => $ads[($k * 7) % count($ads)],
                    // The base demo's orders can be older than the report's default 30-day range: bring these into it.
                    'placed_at' => $placed->lessThan($now->subDays(28)) ? $now->subDays(2 + ($k * 3) % 26)->setTime(10 + $k % 10, 20) : $placed,
                ]);
            }
        }

        $first = Order::where('utm_medium', 'paid')->where('utm_source', 'facebook')->min('placed_at');
        if ($first !== null) {
            $attribution->run(CarbonImmutable::parse($first)->subDay(), CarbonImmutable::now());
        }
    }
}
