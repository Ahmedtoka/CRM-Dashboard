<?php

namespace App\Ads\Control\Write;

use App\Ads\Platforms\SecretScrubber;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A write refused with a stable code (Phase B refusal table). Rendered as
 * {code, message, details, errors: {status: [message]}}: errors.status keeps the old axios handling working
 * (useApi reads errors.*[0] first), so the existing UI shows every code with no change.
 */
class WriteDenied extends RuntimeException
{
    /** HTTP status of every stable refusal code; anything unlisted is 422. */
    public const STATUS = [
        'out_of_scope' => 403,
        'ads_authority_required' => 403,
        'not_proposer' => 403,
        'not_found' => 404,
        'idempotency_key_reused' => 409,
        'action_in_progress' => 409,
        'stop_in_progress' => 409,
        'not_confirmable' => 409,
        'diff_changed' => 409,
        'precondition_failed' => 409,
        'proposal_expired' => 410,
        'password_confirmation_required' => 423,
        'rate_limited' => 429,
        'writes_disabled' => 503,
        'launch_state' => 409,
        'launch_changed' => 409,
        'launch_taken' => 409,
        'launch_forbidden' => 403,
        'self_approval' => 403,
        'approval_required' => 403,
    ];

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public function __construct(public string $errorCode, public int $status, public array $details = [], public array $headers = [])
    {
        parent::__construct(self::messageFor($errorCode, $details));
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public static function make(string $code, array $details = [], array $headers = []): self
    {
        return new self($code, self::STATUS[$code] ?? 422, $details, $headers);
    }

    /** @param  array<string, mixed>  $details  only scalar details are used as :placeholders */
    public static function messageFor(string $code, array $details = []): string
    {
        $replace = array_filter($details, fn ($v) => is_scalar($v));
        // A reason with its own message (e.g. budget_unreadable + no_budget) wins over the code's generic one.
        $reason = isset($details['reason']) && is_string($details['reason']) ? $details['reason'] : null;
        if ($reason !== null && preg_match('/^[a-z_]+$/', $reason) === 1) {
            $specific = __('ads.errors.'.$code.'_'.$reason, $replace);
            if (is_string($specific) && $specific !== 'ads.errors.'.$code.'_'.$reason) {
                return $specific;
            }
        }
        $message = __('ads.errors.'.$code, $replace);
        if (! is_string($message) || $message === 'ads.errors.'.$code) {
            return (string) __('ads.errors.failed');
        }
        // A stored refusal without its details (older rows) must never show a raw :placeholder: use the plain variant.
        if (self::hasPlaceholder($message)) {
            $plain = __('ads.errors.'.$code.'_plain');

            return is_string($plain) && $plain !== 'ads.errors.'.$code.'_plain' ? $plain : (string) __('ads.errors.failed');
        }

        return $message;
    }

    /** An unreplaced :placeholder (a word right after a colon that does not follow a letter, so "ads:writable" is not one). */
    public static function hasPlaceholder(string $message): bool
    {
        return preg_match('/(?<![\p{L}\p{N}]):[a-z][a-z_]*/u', $message) === 1;
    }

    /** "<message> (<platform's own text>)": the platform's reason, scrubbed and shortened, after the translated one. */
    public static function withPlatformMessage(string $message, mixed $platform): string
    {
        $platform = is_string($platform) ? trim(SecretScrubber::scrub($platform)) : '';

        return $platform === '' ? $message : $message.' ('.mb_strimwidth($platform, 0, 300, '…').')';
    }

    /** A refusal is an answer, not an application error: never reported to the error log. */
    public function report(): bool
    {
        return false;
    }

    /** @return array{code: string, message: string, details: array<string, mixed>|object, errors: array{status: list<string>}} */
    public function body(): array
    {
        return [
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
            'details' => $this->details === [] ? (object) [] : $this->details,
            'errors' => ['status' => [self::withPlatformMessage($this->getMessage(), $this->details['platform_message'] ?? null)]],
        ];
    }

    public function render(): JsonResponse
    {
        return response()->json($this->body(), $this->status, $this->headers);
    }
}
