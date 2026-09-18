<?php

namespace Database\Seeders;

use App\Commerce\OrderService;
use App\Enums\ConversationStatus;
use App\Enums\OrderType;
use App\Enums\Platform;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Inbox\ConversationActions;
use App\Inbox\OutboundService;
use App\Inbox\WindowClosedException;
use App\Models\BotRule;
use App\Models\BotSetting;
use App\Models\ChannelAccount;
use App\Models\City;
use App\Models\Conversation;
use App\Models\CustomerIdentity;
use App\Models\ProductVariant;
use App\Models\QuickReply;
use App\Models\Shipment;
use App\Models\Tag;
use App\Models\User;
use App\Models\UserPlatform;
use App\Shipping\ShipmentService;
use App\Shopify\Connection\ShopifyIntegration;
use App\Shopify\Sync\BulkImporter;
use App\Simulator\Simulator;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Seeders\Demo\ArabicCorpus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Replays a realistic 30-day history through the real services (spec §10),
 * clock frozen per event, so conversations/messages/bot decisions/comments/
 * orders/shipments/activity logs are all internally consistent — as if every
 * platform had been live for the last month. Safe to re-run via
 * `migrate:fresh --seed` (each run starts from an empty database).
 */
class DemoSeeder extends Seeder
{
    private Simulator $simulator;

    /** @var array<string, User> mod1..mod6 => User */
    private array $moderators = [];

    /** @var array<string, array<int, Platform>> mod1..mod6 => allowed platforms */
    private array $moderatorPlatforms = [];

    private User $admin;

    private User $supervisor;

    /** @var array<int, City> */
    private array $cities = [];

    /** @var array<int, int> ProductVariant ids */
    private array $variantIds = [];

    /** @var array<int, Tag> */
    private array $tags = [];

    /**
     * A fixed-size pool of ~350 "people" (spec §10), each with one primary
     * platform and, for ~15% of them, a second platform too. Every
     * conversation/comment customer is drawn from here (see pickCustomer())
     * instead of minted ad hoc, so the distinct-customer count converges to
     * the pool size regardless of how much traffic is replayed.
     *
     * @var array<int, array{name: string, phone: string, primary_platform: Platform, primary_key: string, secondary_platform: ?Platform, secondary_key: ?string}>
     */
    private array $peoplePool = [];

    /** @var array<string, array<int, array{key: string, name: string, person_index: int}>> platform value => pool entries usable on that platform */
    private array $poolByPlatform = [];

    private CarbonImmutable $realNow;

    /**
     * Every driver switch the demo touches, including the Shopify transport
     * (not one of `crm.drivers.*`, but just as dangerous to leave live).
     * `seedShopifyCatalog()` persists a `connected` `ShopifyIntegration` row —
     * if any of these were ever live, a later scheduled reconcile/webhooks-check
     * run (or any other Shopify call) could use that row's bogus token against
     * a real store.
     */
    private const DRIVER_CONFIG_KEYS = [
        'crm.drivers.channels',
        'crm.drivers.commerce',
        'crm.drivers.shipping',
        'crm.drivers.ai',
        'crm.shopify.driver',
    ];

    public function run(): void
    {
        // Refuse outright rather than silently proceeding: this must never be
        // a matter of "seedShopifyCatalog() happens to force crm.shopify.driver
        // around one call and everything else is scoped correctly" — the whole
        // run refuses to start unless every driver was already `fake`.
        $this->assertFakeDriversOnly();

        // Reseeding must be reproducible: array_rand()/shuffle()/mt_rand()
        // all draw from the same Mersenne Twister state, so seeding it once
        // up front makes every scenario choice below deterministic. (Only
        // Str::random()/Str::uuid() calls — used purely for opaque unique id
        // suffixes, never for scenario decisions — remain non-deterministic.)
        mt_srand(20260912);

        $this->simulator = app(Simulator::class);
        $this->realNow = CarbonImmutable::now();

        $originalBroadcast = config('broadcasting.default');
        $originalQueue = config('queue.default');
        $originalDrivers = collect(self::DRIVER_CONFIG_KEYS)->mapWithKeys(fn (string $key) => [$key => config($key)])->all();

        // Historical replay must never hit a real broadcast server, and every
        // queued job (bot runs, comment bot runs) must execute inline so the
        // data produced is fully consistent by the time the seeder returns.
        // Every driver is pinned to `fake` for the whole run (not just around
        // the Shopify import below) — belt and suspenders on top of the guard
        // above, since nothing here should ever need anything else.
        config(['broadcasting.default' => 'null', 'queue.default' => 'sync']);
        config(array_fill_keys(self::DRIVER_CONFIG_KEYS, 'fake'));

        try {
            $this->seedUsers();
            $this->seedChannelAccounts();
            $this->seedCities();
            $this->seedShopifyCatalog();
            $this->seedBotSettingsAndRules();
            $this->seedQuickReplies();
            $this->seedTags();
            $this->seedCustomerPool();

            $this->replayConversations();
            $this->replayComments();
            $this->finalizeCrossPlatformIdentities();

            // Everything from here happens "now" for a live-looking inbox.
            $this->travelTo(null);
            $this->seedLiveInbox();

            $this->rollupPastDays();
        } finally {
            $this->travelTo(null);
            config(['broadcasting.default' => $originalBroadcast, 'queue.default' => $originalQueue]);
            config($originalDrivers);
        }
    }

    /** @throws RuntimeException when any driver isn't already `fake` */
    private function assertFakeDriversOnly(): void
    {
        foreach (self::DRIVER_CONFIG_KEYS as $key) {
            $value = config($key);

            if ($value !== 'fake') {
                throw new RuntimeException("DemoSeeder runs only with fake drivers ({$key} is \"{$value}\", not \"fake\").");
            }
        }
    }

    // -----------------------------------------------------------------
    // Reference data
    // -----------------------------------------------------------------

    private function seedUsers(): void
    {
        $this->admin = User::create([
            'name' => 'أحمد فتحي',
            'email' => 'admin@crm.test',
            'password' => 'password',
            'role' => UserRole::Admin,
            'locale' => 'ar',
            'is_active' => true,
            'color' => '#7C3AED',
            'email_verified_at' => now(),
        ]);

        $this->supervisor = User::create([
            'name' => 'منى يوسف',
            'email' => 'supervisor@crm.test',
            'password' => 'password',
            'role' => UserRole::Supervisor,
            'locale' => 'ar',
            'is_active' => true,
            'color' => '#059669',
            'email_verified_at' => now(),
        ]);

        $modDefs = [
            'mod1' => ['name' => 'سارة إبراهيم', 'color' => '#DB2777', 'platforms' => [Platform::Facebook, Platform::Instagram]],
            'mod2' => ['name' => 'كريم سامي', 'color' => '#2563EB', 'platforms' => [Platform::Facebook, Platform::Instagram]],
            'mod3' => ['name' => 'هبة الله عادل', 'color' => '#D97706', 'platforms' => [Platform::WhatsApp]],
            'mod4' => ['name' => 'محمود صلاح', 'color' => '#0891B2', 'platforms' => [Platform::WhatsApp, Platform::Facebook]],
            'mod5' => ['name' => 'ياسمين خالد', 'color' => '#DC2626', 'platforms' => [Platform::TikTok, Platform::Instagram]],
            'mod6' => ['name' => 'عمر شريف', 'color' => '#4D7C0F', 'platforms' => [Platform::Facebook, Platform::Instagram, Platform::WhatsApp, Platform::TikTok]],
        ];

        foreach ($modDefs as $key => $def) {
            $user = User::create([
                'name' => $def['name'],
                'email' => $key.'@crm.test',
                'password' => 'password',
                'role' => UserRole::Moderator,
                'locale' => 'ar',
                'is_active' => true,
                'color' => $def['color'],
                'email_verified_at' => now(),
            ]);

            foreach ($def['platforms'] as $p) {
                UserPlatform::create(['user_id' => $user->id, 'platform' => $p]);
            }

            $this->moderators[$key] = $user;
            $this->moderatorPlatforms[$key] = $def['platforms'];
        }
    }

    private function seedChannelAccounts(): void
    {
        foreach (Platform::cases() as $p) {
            ChannelAccount::create([
                'platform' => $p->value,
                'name' => 'Demo '.$p->label(),
                'external_id' => 'demo-'.$p->value,
                'driver' => 'fake',
                'status' => 'connected',
                'last_webhook_at' => now(),
            ]);
        }
    }

    private function seedCities(): void
    {
        foreach (ArabicCorpus::cities() as $c) {
            $this->cities[] = City::create([
                'name_ar' => $c['name_ar'],
                'name_en' => $c['name_en'],
                'shipping_fee' => $c['fee'],
            ]);
        }
    }

    /**
     * Task 10: rather than inserting `Product`/`ProductVariant` rows by hand,
     * the demo connects a `ShopifyIntegration` to the fake Shopify driver and
     * runs the real `BulkImporter` against it, so the demo's products,
     * shipping zones (27 governorates) and a first batch of customers all
     * flow through the same mappers a real store's initial import would use.
     * `FakeShopifyTransport` generates its bulk export from this same
     * `ArabicCorpus` catalog, so the imported products match what the
     * simulator/seeder expect. `queue.default` is already forced to `sync`
     * above, so `BulkImporter::start()`'s job chain (shipping → products →
     * customers → orders) runs to completion synchronously here.
     *
     * The 'orders' stage of that same import also seeds a batch of
     * storefront-only orders (no matching CRM conversation), which
     * `OrderMapper` records with `source = store` — the demo's ~20%
     * store-origin share once combined with the chat orders created later
     * by replayConversations().
     */
    private function seedShopifyCatalog(): void
    {
        $this->travelTo($this->realNow->subDays(31));

        // `run()` already asserted and pinned every driver (including
        // `crm.shopify.driver`) to `fake` for the whole seeder, so this row is
        // never read by anything but `FakeShopifyTransport`. Its shop_domain is
        // also the shared `ShopifyIntegration::DEMO_SHOP_DOMAIN` constant that
        // the live transport and the scheduled Shopify jobs refuse to call out
        // for, as a second, independent safety net.
        ShopifyIntegration::create([
            'shop_domain' => ShopifyIntegration::DEMO_SHOP_DOMAIN,
            'shop_name' => 'متجر تجريبي',
            'currency' => 'EGP',
            'access_token' => 'demo-fake-token',
            'api_secret' => 'demo-fake-secret',
            'api_version' => config('crm.shopify.api_version'),
            'granted_scopes' => config('crm.shopify.required_scopes', []),
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        app(BulkImporter::class)->start();

        $this->variantIds = ProductVariant::pluck('id')->all();
        $this->travelTo(null);
    }

    private function seedBotSettingsAndRules(): void
    {
        BotSetting::current()->update([
            'enabled' => true,
            'ai_enabled' => true,
            'min_confidence' => 0.60,
            'max_bot_turns' => 6,
            'handover_keywords' => ['عايز اكلم حد', 'موظف', 'اتكلم مع حد', 'حد بشري', 'مسؤول'],
            'working_hours' => null,
            'comment_reply_delay_min' => 5,
            'comment_reply_delay_max' => 30,
        ]);

        $rules = [
            ['name' => 'السعر', 'priority' => 50, 'keywords' => ['بكام', 'السعر', 'كام سعره', 'سعره كام'], 'public_replies' => ['ردينا في الخاص بالسعر 💌'], 'private_reply' => 'السعر موضح في الكتالوج، تحب تعرف سعر منتج معين؟'],
            ['name' => 'التوفر', 'priority' => 45, 'keywords' => ['متاح', 'موجود', 'فاضل عندكم'], 'public_replies' => ['ردينا عليك في الخاص 💌'], 'private_reply' => 'أيوة متاح، تحب تأكد المقاس واللون؟'],
            ['name' => 'المقاسات', 'priority' => 40, 'keywords' => ['مقاس', 'مقاسات'], 'public_replies' => ['المقاسات في التفاصيل 📏'], 'private_reply' => 'المقاسات المتاحة من S لحد XL'],
            ['name' => 'مدة التوصيل', 'priority' => 35, 'keywords' => ['التوصيل بياخد', 'هيوصل امتى', 'مدة الشحن', 'واصل امتى'], 'public_replies' => [], 'private_reply' => 'التوصيل بياخد من يومين لـ٥ أيام عمل حسب المحافظة'],
            ['name' => 'مصاريف الشحن', 'priority' => 34, 'keywords' => ['مصاريف الشحن', 'رسوم التوصيل', 'الشحن بكام'], 'public_replies' => [], 'private_reply' => 'مصاريف الشحن بتختلف حسب المحافظة من ٦٠ لـ١١٠ جنيه'],
            ['name' => 'طرق الدفع', 'priority' => 30, 'keywords' => ['الدفع', 'كاش', 'فيزا', 'طرق الدفع', 'تحويل'], 'public_replies' => [], 'private_reply' => 'بنقبل الدفع عند الاستلام أو أونلاين بالفيزا'],
            ['name' => 'الفروع', 'priority' => 25, 'keywords' => ['فرعكم فين', 'عندكم فرع', 'لوكيشن', 'الفرع'], 'public_replies' => [], 'private_reply' => 'بنبيع أونلاين بس دلوقتي، مفيش فروع فعلية'],
            ['name' => 'سياسة الاسترجاع', 'priority' => 20, 'keywords' => ['استرجاع', 'استبدال', 'ارجاع'], 'public_replies' => [], 'private_reply' => 'ممكن ترجعي أو تستبدلي خلال ١٤ يوم من الاستلام'],
            ['name' => 'الترحيب', 'priority' => 5, 'keywords' => ['السلام عليكم', 'مساء الخير', 'صباح الخير', 'هاي', 'هلا'], 'public_replies' => ['أهلاً بيك! 🌸'], 'private_reply' => 'أهلاً بيك، اتفضل استفسارك؟'],
            ['name' => 'حذف السبام', 'priority' => 100, 'scope' => 'comment', 'action' => 'hide', 'keywords' => ['اربح', 'اشتغل من البيت', 'http', 'www.', 'دخل يومي'], 'public_replies' => [], 'private_reply' => null],
        ];

        foreach ($rules as $r) {
            BotRule::create(array_merge([
                'is_active' => true,
                'scope' => 'both',
                'platforms' => [],
                'match_type' => 'any_keyword',
                'action' => 'reply',
            ], $r));
        }
    }

    private function seedQuickReplies(): void
    {
        $items = [
            ['shortcut' => '/price', 'title' => 'السعر', 'body' => 'السعر موضح في الكتالوج، تحب تعرف سعر منتج معين؟'],
            ['shortcut' => '/size', 'title' => 'المقاسات', 'body' => 'المقاسات المتاحة من S لحد XL'],
            ['shortcut' => '/delivery', 'title' => 'مدة التوصيل', 'body' => 'التوصيل بياخد من يومين لـ٥ أيام عمل حسب المحافظة'],
            ['shortcut' => '/fee', 'title' => 'مصاريف الشحن', 'body' => 'مصاريف الشحن بتختلف حسب المحافظة من ٦٠ لـ١١٠ جنيه'],
            ['shortcut' => '/pay', 'title' => 'طرق الدفع', 'body' => 'بنقبل الدفع عند الاستلام أو أونلاين بالفيزا'],
            ['shortcut' => '/branch', 'title' => 'الفروع', 'body' => 'بنبيع أونلاين بس دلوقتي، مفيش فروع فعلية'],
            ['shortcut' => '/return', 'title' => 'الاسترجاع', 'body' => 'ممكن ترجعي أو تستبدلي خلال ١٤ يوم من الاستلام'],
            ['shortcut' => '/thanks', 'title' => 'شكرا', 'body' => 'شكرا لتواصلك معانا، في خدمتك دايما 🌸'],
            ['shortcut' => '/welcome', 'title' => 'ترحيب', 'body' => 'أهلاً بيك، تحت أمرك 🌸'],
            ['shortcut' => '/confirm', 'title' => 'تأكيد الأوردر', 'body' => 'تم تأكيد الأوردر، هيوصلك خلال يومين لـ٣ أيام'],
            ['shortcut' => '/apology', 'title' => 'اعتذار عن التأخير', 'body' => 'آسفين جدا على التأخير، هتابع مع الشحن دلوقتي'],
            ['shortcut' => '/discount', 'title' => 'خصم الجملة', 'body' => 'عندنا خصم للكميات الكبيرة، كام قطعة محتاجة؟'],
        ];

        foreach ($items as $item) {
            QuickReply::create(array_merge($item, ['platforms' => null, 'created_by' => $this->admin->id]));
        }
    }

    private function seedTags(): void
    {
        $defs = [
            ['name' => 'VIP', 'color' => '#F59E0B'],
            ['name' => 'مرتجع', 'color' => '#EF4444'],
            ['name' => 'شكوى', 'color' => '#DC2626'],
            ['name' => 'جملة', 'color' => '#2563EB'],
            ['name' => 'متابعة', 'color' => '#0891B2'],
            ['name' => 'تم التواصل', 'color' => '#059669'],
            ['name' => 'أولوية', 'color' => '#7C3AED'],
            ['name' => 'عميل جديد', 'color' => '#DB2777'],
        ];

        foreach ($defs as $d) {
            $this->tags[] = Tag::create($d);
        }
    }

    /**
     * Builds the fixed ~350-person pool (spec §10: "~350 customers, some on
     * several platforms"): each person gets one primary platform (evenly
     * distributed across the four), and ~15% also get a second, different
     * platform reserved for them.
     *
     * The reserved secondary platform is deliberately NOT added to
     * poolByPlatform — if it were, ordinary conversation/comment replay could
     * pick that (platform, external_id) pair before finalizeCrossPlatformIdentities()
     * runs, and the real ingestion pipeline would then mint it as a brand new,
     * unrelated Customer (since no CustomerIdentity exists for it yet),
     * defeating the whole point. Only finalizeCrossPlatformIdentities() is
     * allowed to create that second identity, explicitly tied to the same
     * customer_id as the primary one.
     */
    private function seedCustomerPool(): void
    {
        $target = 350;
        $crossPlatformShare = 0.15;
        $names = ArabicCorpus::names();
        $platforms = Platform::cases();

        $crossFlags = array_fill(0, (int) round($target * $crossPlatformShare), true);
        $crossFlags = array_pad($crossFlags, $target, false);
        shuffle($crossFlags);

        for ($i = 0; $i < $target; $i++) {
            $primary = $platforms[$i % count($platforms)];
            $name = $names[$i % count($names)];
            $phone = $this->randomEgyptianPhone();
            $primaryKey = 'demo-cust-'.$primary->value.'-p'.$i;

            $person = [
                'name' => $name,
                'phone' => $phone,
                'primary_platform' => $primary,
                'primary_key' => $primaryKey,
                'secondary_platform' => null,
                'secondary_key' => null,
            ];

            if ($crossFlags[$i]) {
                $others = array_values(array_filter($platforms, fn (Platform $p) => $p !== $primary));
                $secondary = $others[array_rand($others)];
                $person['secondary_platform'] = $secondary;
                $person['secondary_key'] = 'demo-cust-'.$secondary->value.'-s'.$i;
            }

            $this->peoplePool[$i] = $person;
            $this->poolByPlatform[$primary->value][] = ['key' => $primaryKey, 'name' => $name, 'person_index' => $i];
        }
    }

    /**
     * For every pool member with a reserved second platform, makes sure
     * their primary identity actually exists (warming it up with one message
     * if the random replay never happened to pick them) and then creates the
     * second `CustomerIdentity` directly on the same customer — real data
     * for the multi-identity/merge UI, per the same name and phone.
     */
    private function finalizeCrossPlatformIdentities(): void
    {
        $openers = ArabicCorpus::customerOpeners();

        foreach ($this->peoplePool as $person) {
            if ($person['secondary_platform'] === null) {
                continue;
            }

            $primaryIdentity = CustomerIdentity::where('platform', $person['primary_platform']->value)
                ->where('external_id', $person['primary_key'])
                ->first();

            if ($primaryIdentity === null) {
                // A fresh-ish historical moment rather than whatever instant
                // replayComments() last left the frozen clock at.
                $warmupAt = $this->realNow->timezone('Africa/Cairo')
                    ->subDays(mt_rand(1, 29))
                    ->setTime($this->weightedHour(), mt_rand(0, 59))
                    ->utc();

                $this->travelTo($warmupAt);

                try {
                    $this->simulator->customerMessage(
                        $person['primary_platform'],
                        $person['primary_key'],
                        $person['name'],
                        $openers[array_rand($openers)],
                        $warmupAt,
                    );
                } catch (Throwable) {
                    continue;
                }

                $primaryIdentity = CustomerIdentity::where('platform', $person['primary_platform']->value)
                    ->where('external_id', $person['primary_key'])
                    ->first();
            }

            if ($primaryIdentity === null) {
                continue;
            }

            $alreadyLinked = CustomerIdentity::where('platform', $person['secondary_platform']->value)
                ->where('external_id', $person['secondary_key'])
                ->exists();

            if ($alreadyLinked) {
                continue;
            }

            $customer = $primaryIdentity->customer;

            if ($customer !== null && blank($customer->phone)) {
                $customer->forceFill(['phone' => $person['phone']])->save();
            }

            try {
                CustomerIdentity::create([
                    'customer_id' => $primaryIdentity->customer_id,
                    'platform' => $person['secondary_platform']->value,
                    'external_id' => $person['secondary_key'],
                    'display_name' => $person['name'],
                ]);
            } catch (Throwable) {
                // Best-effort demo data: this one person just won't show as cross-platform.
                continue;
            }

            // Give the second platform genuine activity too (not just a bare
            // identity row): the multi-identity/merge UI should show two real
            // conversation threads that resolve to the same customer. Since
            // the identity above already exists, this resolves to the same
            // customer_id rather than minting a new one.
            $secondAt = $this->realNow->timezone('Africa/Cairo')
                ->subDays(mt_rand(1, 29))
                ->setTime($this->weightedHour(), mt_rand(0, 59))
                ->utc();

            $this->travelTo($secondAt);

            try {
                $this->simulator->customerMessage(
                    $person['secondary_platform'],
                    $person['secondary_key'],
                    $person['name'],
                    $openers[array_rand($openers)],
                    $secondAt,
                );
            } catch (Throwable) {
                // Best-effort demo data: the identity link still stands even without a conversation.
            }
        }
    }

    // -----------------------------------------------------------------
    // Timeline replay
    // -----------------------------------------------------------------

    private function replayConversations(): void
    {
        $cairoNow = $this->realNow->timezone('Africa/Cairo');

        for ($dayOffset = 30; $dayOffset >= 1; $dayOffset--) {
            $day = $cairoNow->copy()->subDays($dayOffset)->startOfDay();
            $count = mt_rand(24, 36); // spec §10: ~30 conversations/day

            for ($i = 0; $i < $count; $i++) {
                $this->simulateConversation($day, $dayOffset);
            }
        }
    }

    private function simulateConversation(CarbonImmutable $day, int $dayOffset): void
    {
        $platform = Platform::cases()[array_rand(Platform::cases())];
        $at = $day->copy()->setTime($this->weightedHour(), mt_rand(0, 59))->utc();

        [$customerKey, $name] = $this->pickCustomer($platform);
        $openers = ArabicCorpus::customerOpeners();

        $this->travelTo($at);
        $message = $this->simulator->customerMessage($platform, $customerKey, $name, $openers[array_rand($openers)], $at);
        $conversation = $message->conversation;

        $moderators = $this->moderatorsFor($platform);
        $cursor = $at;
        $turns = mt_rand(1, 3);

        for ($t = 0; $t < $turns; $t++) {
            $cursor = $cursor->addSeconds(mt_rand(30, 1500));

            if ($cursor->greaterThan($this->realNow)) {
                break;
            }

            $this->travelTo($cursor);
            $mod = $moderators[array_rand($moderators)];
            $replies = ArabicCorpus::moderatorReplies();

            try {
                app(OutboundService::class)->sendHuman($conversation->fresh(), $mod, $replies[array_rand($replies)]);
            } catch (WindowClosedException) {
                break;
            }

            if ($t < $turns - 1 && mt_rand(1, 100) <= 45) {
                $cursor = $cursor->addSeconds(mt_rand(30, 600));

                if ($cursor->greaterThan($this->realNow)) {
                    break;
                }

                $this->travelTo($cursor);
                $followUps = ArabicCorpus::followUps();
                $this->simulator->customerMessage($platform, $customerKey, $name, $followUps[array_rand($followUps)], $cursor);
            }
        }

        if (mt_rand(1, 100) <= 35) {
            $orderAt = $cursor->addSeconds(mt_rand(60, 900));

            if ($orderAt->lessThanOrEqualTo($this->realNow)) {
                $this->travelTo($orderAt);
                $this->createDemoOrder($conversation->fresh(), $moderators[array_rand($moderators)], $orderAt, $dayOffset);
                $cursor = $orderAt;
            }
        }

        $conversation = $conversation->fresh();

        if (mt_rand(1, 100) <= 60) {
            $resolveAt = $cursor->addSeconds(mt_rand(30, 300));

            if ($resolveAt->lessThanOrEqualTo($this->realNow)) {
                $this->travelTo($resolveAt);

                try {
                    app(ConversationActions::class)->resolve($conversation, $moderators[array_rand($moderators)]);
                } catch (Throwable) {
                    // Best-effort demo data: leave it open rather than abort the seed.
                }
            }
        }

        if ($dayOffset >= 2 && mt_rand(1, 100) <= 15) {
            $this->maybeFollowUpNextDay($conversation->fresh(), $day, $moderators);
        }

        if (mt_rand(1, 100) <= 15) {
            $tag = $this->tags[array_rand($this->tags)];

            try {
                app(ConversationActions::class)->syncTags($conversation->fresh(), [$tag->id]);
            } catch (Throwable) {
                // Best-effort demo data: tagging is cosmetic, never worth aborting the seed.
            }
        }
    }

    /**
     * @param  array<int, User>  $moderators
     */
    private function maybeFollowUpNextDay(Conversation $conversation, CarbonImmutable $day, array $moderators): void
    {
        $followUpAt = $day->copy()->addDay()->setTime(mt_rand(11, 22), mt_rand(0, 59))->utc();

        if ($followUpAt->greaterThan($this->realNow)) {
            return;
        }

        $this->travelTo($followUpAt);

        $others = array_values(array_filter($moderators, fn (User $m) => $m->id !== $conversation->last_responder_id));
        $followMod = $others !== [] ? $others[array_rand($others)] : $moderators[array_rand($moderators)];

        try {
            if ($conversation->status === ConversationStatus::Resolved) {
                try {
                    app(ConversationActions::class)->reopen($conversation, $followMod);
                    $conversation = $conversation->fresh();
                } catch (Throwable) {
                    return; // couldn't reopen: no point trying to reply into a still-resolved thread
                }
            }

            app(OutboundService::class)->sendHuman($conversation, $followMod, 'تمام، حصل خير؟ محتاجة أي حاجة تانية؟');

            if (mt_rand(1, 100) <= 50) {
                try {
                    app(ConversationActions::class)->resolve($conversation->fresh(), $followMod);
                } catch (Throwable) {
                    // Best-effort demo data: leave it open rather than abort the seed.
                }
            }
        } catch (WindowClosedException) {
            // Window closed for this platform (e.g. WhatsApp/TikTok after 24-48h
            // with no human-agent extension) — a real agent couldn't send either.
        }
    }

    private function createDemoOrder(Conversation $conversation, User $moderator, CarbonImmutable $at, int $dayOffset): void
    {
        $itemCount = min(mt_rand(1, 3), count($this->variantIds));
        $variantIds = (array) array_rand(array_flip($this->variantIds), $itemCount);

        $items = array_map(fn ($variantId) => ['variant_id' => $variantId, 'qty' => mt_rand(1, 2)], $variantIds);

        $city = $this->cities[array_rand($this->cities)];
        $names = ArabicCorpus::names();
        $type = mt_rand(1, 100) <= 70 ? OrderType::Cod : OrderType::PaymentLink;

        $data = [
            'type' => $type->value,
            'items' => $items,
            'shipping' => [
                'name' => $names[array_rand($names)],
                'phone' => $this->randomEgyptianPhone(),
                // New shape (province_code/city/address1): Task 10's Shopify import seeds
                // real ShippingZone rows before any order is created, so the legacy
                // city_id fee path (only used while no zones exist) no longer applies —
                // this keeps demo orders on the same varied per-governorate rates.
                'province_code' => $this->provinceCode($city->name_ar),
                'city' => $city->name_ar,
                'address1' => 'شارع '.mt_rand(1, 40).'، '.$city->name_ar,
            ],
        ];

        try {
            $order = app(OrderService::class)->create($conversation, $moderator, $data);
        } catch (Throwable) {
            return;
        }

        $cursor = $at;

        if ($type === OrderType::PaymentLink && mt_rand(1, 100) <= 80) {
            $cursor = $cursor->addHours(mt_rand(1, 20));

            if ($cursor->lessThanOrEqualTo($this->realNow)) {
                $this->travelTo($cursor);

                try {
                    app(OrderService::class)->markPaid($order->fresh());
                } catch (Throwable) {
                    // Best-effort demo data: leave it awaiting payment rather than abort.
                }
            }
        }

        $order = $order->fresh(['shipment']);

        if ($order->shipment) {
            $this->advanceShipment($order->shipment, $dayOffset, $cursor);
        }
    }

    private function advanceShipment(Shipment $shipment, int $dayOffset, CarbonImmutable $cursor): void
    {
        $maxSteps = match (true) {
            $dayOffset >= 6 => 4,
            $dayOffset >= 3 => mt_rand(1, 3),
            default => mt_rand(0, 1),
        };

        $sequence = [ShipmentStatus::PickedUp, ShipmentStatus::InTransit, ShipmentStatus::OutForDelivery, ShipmentStatus::Delivered];

        foreach (array_slice($sequence, 0, $maxSteps) as $status) {
            $cursor = $cursor->addHours(mt_rand(4, 30));

            if ($cursor->greaterThan($this->realNow)) {
                return;
            }

            $this->travelTo($cursor);

            try {
                app(ShipmentService::class)->applyEvent($shipment, $status, ucfirst(str_replace('_', ' ', $status->value)), null, $cursor);
            } catch (Throwable) {
                return; // Best-effort demo data: stop advancing this one shipment rather than abort the seed.
            }
        }

        // 8% of fully-delivered shipments are later returned by the customer.
        if ($maxSteps >= 4 && mt_rand(1, 100) <= 8) {
            $cursor = $cursor->addHours(mt_rand(4, 48));

            if ($cursor->lessThanOrEqualTo($this->realNow)) {
                $this->travelTo($cursor);

                try {
                    app(ShipmentService::class)->applyEvent($shipment, ShipmentStatus::Returned, 'Customer returned the item', null, $cursor);
                } catch (Throwable) {
                    // Best-effort demo data.
                }
            }
        }
    }

    private function replayComments(): void
    {
        $totalPosts = 25;
        $adCount = 8;
        $cairoNow = $this->realNow->timezone('Africa/Cairo');
        $commentTexts = ArabicCorpus::comments();

        for ($i = 1; $i <= $totalPosts; $i++) {
            $isAd = $i <= $adCount;
            $platform = Platform::cases()[array_rand(Platform::cases())];
            $dayOffset = mt_rand(4, 29);
            $postCreatedAt = $cairoNow->copy()->subDays($dayOffset)->setTime(mt_rand(10, 20), mt_rand(0, 59))->utc();
            $postKey = 'demo-post-'.$i;
            // spec §10: ~1,200 comments across 25 posts (8 ads), ads get more.
            $commentCount = $isAd ? mt_rand(65, 95) : mt_rand(24, 42);
            $spanSeconds = max(3600, $dayOffset * 86400);

            for ($j = 0; $j < $commentCount; $j++) {
                $commentAt = $postCreatedAt->addSeconds(mt_rand(60, $spanSeconds));

                if ($commentAt->greaterThan($this->realNow)) {
                    $commentAt = $this->realNow->subMinutes(mt_rand(1, 500));
                }

                $this->travelTo($commentAt);

                // Commenters are drawn from the same customer pool as message
                // threads (spec §10), so a recurring commenter on a platform
                // resolves to the same Customer/CustomerIdentity as their
                // conversations there, instead of minting a one-off customer
                // per comment.
                [$custKey, $name] = $this->pickCustomer($platform);
                $text = $commentTexts[array_rand($commentTexts)];

                try {
                    $this->simulator->comment($platform, $postKey, $custKey, $name, $text, $isAd, $commentAt);
                } catch (Throwable) {
                    // Best-effort demo data: skip a single comment rather than abort the whole seed.
                }
            }
        }
    }

    // -----------------------------------------------------------------
    // Live inbox (current time)
    // -----------------------------------------------------------------

    private function seedLiveInbox(): void
    {
        $openers = ArabicCorpus::customerOpeners();

        for ($i = 0; $i < 15; $i++) {
            $platform = Platform::cases()[array_rand(Platform::cases())];
            [$key, $name] = $this->pickCustomer($platform);
            $this->simulator->customerMessage($platform, $key, $name, $openers[array_rand($openers)]);
        }

        // Each of these contains a configured handover keyword verbatim, so
        // the bot hands them all over deterministically (needs_human = true).
        $handoverTexts = [
            'عايز اكلم حد بشري مش بوت',
            'ممكن موظف يرد عليا؟',
            'محتاجة اتكلم مع حد يفهم في الموضوع',
            'حد بشري يرد عليا لو سمحتوا',
            'عايزة اكلم مسؤول عن الشكوى دي',
            'تاني مرة برجع اقول عايز اكلم حد النهارده',
        ];

        $names = ArabicCorpus::names();

        foreach ($handoverTexts as $text) {
            $platform = Platform::cases()[array_rand(Platform::cases())];
            // A brand-new customer/conversation, never a pooled one that might
            // already be human-handled (which would skip the bot entirely and
            // never flip needs_human) — this bucket must land on a fresh,
            // bot-handled conversation so the handover keyword always fires.
            $key = 'demo-cust-'.$platform->value.'-live-'.Str::random(6);
            $this->simulator->customerMessage($platform, $key, $names[array_rand($names)], $text);
        }
    }

    private function rollupPastDays(): void
    {
        $cairoNow = $this->realNow->timezone('Africa/Cairo');

        for ($dayOffset = 30; $dayOffset >= 0; $dayOffset--) {
            Artisan::call('crm:rollup', ['date' => $cairoNow->copy()->subDays($dayOffset)->toDateString()]);
        }
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function weightedHour(): int
    {
        $pool = [];

        foreach (range(0, 23) as $h) {
            $weight = ($h >= 12 || $h === 0) ? 6 : 1;

            for ($w = 0; $w < $weight; $w++) {
                $pool[] = $h;
            }
        }

        return $pool[array_rand($pool)];
    }

    /**
     * Draws a customer for $platform from the fixed ~350-person pool
     * (seedCustomerPool()) — never mints an ad hoc one — so total distinct
     * customers stay converged around the pool size no matter how much
     * traffic gets replayed (spec §10).
     *
     * @return array{0: string, 1: string} [customerKey, name]
     */
    private function pickCustomer(Platform $platform): array
    {
        $entries = $this->poolByPlatform[$platform->value] ?? [];
        $entry = $entries[array_rand($entries)];

        return [$entry['key'], $entry['name']];
    }

    /**
     * @return array<int, User>
     */
    private function moderatorsFor(Platform $platform): array
    {
        $list = [];

        foreach ($this->moderatorPlatforms as $key => $platforms) {
            if (in_array($platform, $platforms, true)) {
                $list[] = $this->moderators[$key];
            }
        }

        return $list !== [] ? $list : [$this->supervisor];
    }

    private function randomEgyptianPhone(): string
    {
        $prefixes = ['010', '011', '012', '015'];

        return $prefixes[array_rand($prefixes)].mt_rand(10000000, 99999999);
    }

    /** ISO 3166-2:EG code for one of `ArabicCorpus::cities()`' Arabic names (`config('crm.eg_provinces')` is the reverse map). */
    private function provinceCode(string $nameAr): ?string
    {
        static $codeByName = null;
        $codeByName ??= array_flip(config('crm.eg_provinces', []));

        return $codeByName[$nameAr] ?? null;
    }

    private function travelTo(?CarbonInterface $at): void
    {
        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
    }
}
