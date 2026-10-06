<?php

namespace App\Ads\Launch;

/** One launch check row (contract: key, level block|warn|pass, message_ar, message_en). */
final readonly class CheckResult
{
    public const PASS = 'pass';

    public const WARN = 'warn';

    public const BLOCK = 'block';

    /** @param  array<string, mixed>  $details */
    public function __construct(
        public string $key,
        public string $level,
        public string $message_ar,
        public string $message_en,
        public array $details = [],
    ) {}

    /** @param  array<string, scalar>  $replace  @param  array<string, mixed>  $details */
    public static function pass(string $key, array $replace = [], array $details = []): self
    {
        return self::make($key, self::PASS, $replace, $details);
    }

    /** @param  array<string, scalar>  $replace  @param  array<string, mixed>  $details */
    public static function warn(string $key, array $replace = [], array $details = []): self
    {
        return self::make($key, self::WARN, $replace, $details);
    }

    /** @param  array<string, scalar>  $replace  @param  array<string, mixed>  $details */
    public static function block(string $key, array $replace = [], array $details = []): self
    {
        return self::make($key, self::BLOCK, $replace, $details);
    }

    /** @return array{key: string, level: string, message_ar: string, message_en: string, details: array<string, mixed>} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'level' => $this->level, 'message_ar' => $this->message_ar, 'message_en' => $this->message_en, 'details' => $this->details];
    }

    /** @param  array<string, mixed>  $a */
    public static function fromArray(array $a): self
    {
        return new self((string) $a['key'], (string) $a['level'], (string) ($a['message_ar'] ?? ''), (string) ($a['message_en'] ?? ''), (array) ($a['details'] ?? []));
    }

    /** @param  array<string, scalar>  $replace  @param  array<string, mixed>  $details */
    private static function make(string $key, string $level, array $replace, array $details): self
    {
        $line = 'ads.launch.checks.'.str_replace('.', '_', $key).'.'.$level;

        return new self($key, $level, (string) __($line, $replace, 'ar'), (string) __($line, $replace, 'en'), $details);
    }
}
