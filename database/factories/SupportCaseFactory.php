<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\SupportCase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportCase>
 */
class SupportCaseFactory extends Factory
{
    protected $model = SupportCase::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'customer_id' => fn (array $attrs) => Conversation::find($attrs['conversation_id'])?->customer_id,
            'platform' => fn (array $attrs) => Conversation::find($attrs['conversation_id'])?->platform?->value ?? 'facebook',
            'type' => 'complaint',
            'status' => 'new',
            'priority' => 'medium',
            'order_id' => null,
            'order_number' => null,
            'data' => ['complaint_type' => 'service', 'complaint_type_title' => 'خدمة العملاء'],
            'photo_attachment_ids' => [],
            'policy_notes' => [],
            'summary' => '• نوع الشكوى: خدمة العملاء',
        ];
    }

    public function returnExchange(): static
    {
        return $this->state(fn () => [
            'type' => 'return_exchange',
            'priority' => 'high',
            'data' => ['reason' => 'defective', 'reason_title' => 'بايظ / فيه عيب', 'request' => 'exchange', 'request_title' => 'استبدال'],
            'summary' => "• السبب: بايظ / فيه عيب\n• الطلب: استبدال",
        ]);
    }
}
