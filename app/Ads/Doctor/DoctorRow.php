<?php

namespace App\Ads\Doctor;

/** One line of the ads:doctor report. status: ok | warn | fail | skip. */
final class DoctorRow
{
    public function __construct(
        public readonly string $section,
        public readonly string $check,
        public readonly string $status,
        public readonly string $value = '',
        public readonly string $hint = '',
    ) {}

    public static function ok(string $section, string $check, string $value = '', string $hint = ''): self
    {
        return new self($section, $check, 'ok', $value, $hint);
    }

    public static function warn(string $section, string $check, string $value = '', string $hint = ''): self
    {
        return new self($section, $check, 'warn', $value, $hint);
    }

    public static function fail(string $section, string $check, string $value = '', string $hint = ''): self
    {
        return new self($section, $check, 'fail', $value, $hint);
    }

    public static function skip(string $section, string $check, string $value = '', string $hint = ''): self
    {
        return new self($section, $check, 'skip', $value, $hint);
    }

    /** ok when $good, else $badStatus with the hint. */
    public static function by(bool $good, string $badStatus, string $section, string $check, string $value = '', string $hint = ''): self
    {
        return new self($section, $check, $good ? 'ok' : $badStatus, $value, $good ? '' : $hint);
    }
}
