<?php

namespace App\Ads\Doctor\Checks;

use App\Ads\Doctor\DoctorContext;
use App\Ads\Doctor\DoctorRow;
use App\Ads\Platforms\SecretScrubber;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/** A section of ads:doctor. Read-only by contract: SELECTs, cache reads, file reads and GET requests only. */
abstract class DoctorCheck
{
    public function __construct(protected readonly DoctorContext $ctx) {}

    /** @return list<DoctorRow> */
    abstract public function run(): array;

    abstract protected function section(): string;

    /**
     * Runs the check and turns a crash into one fail row, so one broken section never hides the others.
     *
     * @return list<DoctorRow>
     */
    final public function safely(): array
    {
        try {
            return $this->run();
        } catch (Throwable $e) {
            return [DoctorRow::fail($this->section(), 'check crashed', class_basename($e), self::clean($e->getMessage()))];
        }
    }

    /** Limits an ad_accounts query to the --account ids or external ids, when given. */
    protected function scopeAccounts(Builder $q): Builder
    {
        if ($this->ctx->accounts !== []) {
            $ids = array_values(array_filter($this->ctx->accounts, 'is_numeric'));
            $q->where(fn ($w) => $w->whereIn('id', $ids)->orWhereIn('external_id', $this->ctx->accounts));
        }

        return $q;
    }

    /** One line, credentials stripped, bounded. */
    public static function clean(?string $text, int $max = 160): string
    {
        $one = trim((string) preg_replace('/\s+/', ' ', SecretScrubber::scrub((string) $text)));

        return mb_strlen($one) > $max ? mb_substr($one, 0, $max - 3).'...' : $one;
    }
}
