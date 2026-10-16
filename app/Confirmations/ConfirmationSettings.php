<?php

namespace App\Confirmations;

use App\Models\OrderConfirmationSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Owner settings for WhatsApp order confirmation (spec 2026-10-16). Off by default.
 */
final class ConfirmationSettings
{
    public const DEFAULTS = [
        'enabled' => false,
        'delay_minutes' => 3,
        'reminder_after_hours' => 6,
        'no_response_after_hours' => 24,
        'template_confirm' => 'order_confirm_ar',
        'template_reminder' => 'order_reminder_ar',
        'template_language' => 'ar',
        'skip_chat_orders' => true,
        'max_order_age_minutes' => 120,
    ];

    private const CACHE_KEY = 'order_confirmation_settings';

    /** @return array<string, mixed> every setting, defaults filled in */
    public function all(): array
    {
        $stored = Cache::remember(self::CACHE_KEY, 300, fn () => OrderConfirmationSetting::query()->pluck('value', 'key')->all());

        return array_merge(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /** @param  array<string, mixed>  $values  unknown keys are ignored */
    public function save(array $values): void
    {
        foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
            OrderConfirmationSetting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        Cache::forget(self::CACHE_KEY);
    }

    public function enabled(): bool
    {
        return (bool) $this->get('enabled');
    }

    public function delayMinutes(): int
    {
        return max(0, (int) $this->get('delay_minutes'));
    }

    public function reminderAfterHours(): int
    {
        return max(1, (int) $this->get('reminder_after_hours'));
    }

    public function noResponseAfterHours(): int
    {
        return max($this->reminderAfterHours() + 1, (int) $this->get('no_response_after_hours'));
    }

    public function maxOrderAgeMinutes(): int
    {
        return max(1, (int) $this->get('max_order_age_minutes'));
    }

    public function skipChatOrders(): bool
    {
        return (bool) $this->get('skip_chat_orders');
    }

    public function template(string $kind): string
    {
        return (string) $this->get($kind === 'reminder' ? 'template_reminder' : 'template_confirm');
    }

    public function templateLanguage(): string
    {
        return (string) $this->get('template_language');
    }
}
