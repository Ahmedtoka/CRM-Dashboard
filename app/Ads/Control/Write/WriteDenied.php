<?php

namespace App\Ads\Control\Write;

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
        'rate_limited' => 429,
        'writes_disabled' => 503,
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

        return is_string($message) && $message !== 'ads.errors.'.$code ? $message : (string) __('ads.errors.failed');
    }

    /** @return array{code: string, message: string, details: array<string, mixed>|object, errors: array{status: list<string>}} */
    public function body(): array
    {
        return [
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
            'details' => $this->details === [] ? (object) [] : $this->details,
            'errors' => ['status' => [$this->getMessage()]],
        ];
    }

    public function render(): JsonResponse
    {
        return response()->json($this->body(), $this->status, $this->headers);
    }
}
