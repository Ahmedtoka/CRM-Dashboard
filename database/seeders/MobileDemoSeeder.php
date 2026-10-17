<?php

namespace Database\Seeders;

use App\Enums\ConversationSource;
use App\Enums\ConversationStatus;
use App\Enums\Handler;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\Platform;
use App\Enums\QuickReplyScope;
use App\Enums\SenderType;
use App\Enums\UserRole;
use App\Models\ChannelAccount;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerIdentity;
use App\Models\Message;
use App\Models\QueueEntry;
use App\Models\QueueSetting;
use App\Models\QuickReply;
use App\Models\Shift;
use App\Models\SupportCase;
use App\Models\User;
use App\Queue\Data\HandoverContext;
use App\Queue\QueueService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Local demo backend for the moderator mobile app (tools/mobile-e2e/run-demo-backend.ps1 in the
 * outer repo). Runs after DemoSeeder on a throwaway sqlite file and makes sure the app has
 * something to do: the moderator `mod@demo.test` (all platforms), the queue on with a shift open
 * now, customers waiting in the lounge, a support case and a saved reply with a placeholder.
 *
 * The password comes from MOBILE_DEMO_PASSWORD (a local test value set by the script), never code.
 * Local environment and sqlite only: it refuses anything else.
 */
class MobileDemoSeeder extends Seeder
{
    public const EMAIL = 'mod@demo.test';

    public function run(): void
    {
        if (! app()->environment('local') || DB::connection()->getDriverName() !== 'sqlite') {
            throw new RuntimeException('MobileDemoSeeder runs only in the local environment on a sqlite database.');
        }

        $password = (string) env('MOBILE_DEMO_PASSWORD', '');
        if (strlen($password) < 8) {
            throw new RuntimeException('Set MOBILE_DEMO_PASSWORD (8+ characters) for the demo moderator.');
        }

        config(['broadcasting.default' => 'null', 'queue.default' => 'sync']);

        $mod = User::query()->updateOrCreate(['email' => self::EMAIL], [
            'name' => 'سلمى (ديمو)', 'password' => $password, 'role' => UserRole::Moderator,
            'locale' => 'ar', 'is_active' => true, 'color' => '#1877F2', 'last_seen_at' => now(),
        ]);
        $mod->forceFill(['email_verified_at' => now()])->save();
        foreach (Platform::cases() as $p) {
            $mod->userPlatforms()->firstOrCreate(['platform' => $p->value]);
        }

        $leader = User::query()->where('role', UserRole::Supervisor)->first() ?? $mod;

        $settings = QueueSetting::current();
        $settings->forceFill(['enabled' => true])->save();

        // One open shift covering now (the scheduler does not run for the demo).
        Shift::query()->where('status', 'open')->update(['status' => 'closed', 'closed_at' => now()]);
        Shift::query()->create([
            'date' => now('Africa/Cairo')->toDateString(), 'shift_key' => 'demo', 'name' => 'شيفت الديمو',
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(12), 'status' => 'open',
            'opened_at' => now(), 'leader_user_id' => $leader->id,
        ]);

        $this->queueCustomers();

        $conversation = Conversation::query()->whereIn('platform', ['whatsapp', 'facebook'])->latest('id')->first();
        if ($conversation !== null && ! SupportCase::query()->where('conversation_id', $conversation->id)->exists()) {
            SupportCase::query()->create([
                'conversation_id' => $conversation->id, 'customer_id' => $conversation->customer_id,
                'platform' => $conversation->platform->value, 'type' => 'complaint', 'status' => 'new', 'priority' => 'medium',
                'data' => ['complaint_type' => 'service', 'complaint_type_title' => 'خدمة العملاء'],
                'photo_attachment_ids' => [], 'policy_notes' => [], 'summary' => '• نوع الشكوى: خدمة العملاء',
            ]);
        }

        QuickReply::query()->firstOrCreate(['shortcut' => 'demo-hi'], [
            'title' => 'ترحيب (ديمو)', 'body' => 'أهلاً يا {customer_name}، معاكي سلمى. أقدر أساعدك إزاي؟',
            'platforms' => null, 'scope' => QuickReplyScope::Shared, 'created_by' => $mod->id,
        ]);
    }

    /**
     * Two customers asking for a person on WhatsApp and Facebook, so check-in hands her windows.
     * Built on DemoSeeder's normal demo channel accounts (`demo-<platform>`): the Simulator would put
     * them on the load-test accounts, and the app would tag both windows «تيست».
     */
    private function queueCustomers(): void
    {
        $queue = app(QueueService::class);

        foreach ([[Platform::WhatsApp, 'نور علي'], [Platform::Facebook, 'هبة محمود']] as $i => [$platform, $name]) {
            $account = ChannelAccount::query()->where('external_id', 'demo-'.$platform->value)->firstOrFail();
            $customer = Customer::query()->create(['name' => $name, 'orders_count' => 0, 'total_spent' => 0]);
            CustomerIdentity::query()->create([
                'customer_id' => $customer->id, 'platform' => $platform->value,
                'external_id' => 'mobile-demo-'.$platform->value.'-'.$i, 'display_name' => $name,
            ]);
            $at = now()->subMinutes(2 - $i);
            $conversation = Conversation::query()->forceCreate([
                'customer_id' => $customer->id, 'channel_account_id' => $account->id, 'platform' => $platform->value,
                'status' => ConversationStatus::Open, 'handler' => Handler::Human, 'needs_human' => true,
                'source' => ConversationSource::Direct, 'unread_count' => 1,
                'last_customer_message_at' => $at, 'last_message_at' => $at,
            ]);
            Message::query()->forceCreate([
                'conversation_id' => $conversation->id, 'platform' => $platform->value,
                'direction' => MessageDirection::In, 'sender_type' => SenderType::Customer,
                'body' => 'عايزة اكلم حد بشري لو سمحتي', 'status' => MessageStatus::Sent, 'is_template' => false,
                'external_id' => 'mobile-demo-msg-'.$platform->value.'-'.$i, 'created_at' => $at, 'updated_at' => $at,
            ]);

            $queue->enqueue($conversation, new HandoverContext('طلب موظفة', 'product_question', 'normal', 'سؤال عن مقاس', ['عايزة تكلم موظفة'], 'inquiry'));
        }

        if (QueueEntry::query()->where('status', 'waiting')->count() === 0) {
            throw new RuntimeException('No customer is waiting in the queue after seeding.');
        }
    }
}
