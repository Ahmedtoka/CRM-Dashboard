<?php

namespace App\Confirmations\Readiness;

use App\Models\ChannelAccount;

/**
 * The template states for a fake WhatsApp number and for tests: every template approved unless the test
 * says otherwise (`FakeTemplateStatus::$statuses`, or a reason to fail with).
 */
final class FakeTemplateStatus implements TemplateStatusSource
{
    /** @param  array<string, string>  $statuses  template name => status word */
    public function __construct(public array $statuses = [], public ?string $failWith = null) {}

    public function statuses(ChannelAccount $account, array $names, string $language): array
    {
        if ($this->failWith !== null) {
            throw new TemplateLookupFailed($this->failWith);
        }

        $out = [];

        foreach ($names as $name) {
            $status = strtoupper($this->statuses[$name] ?? 'APPROVED');
            $out[$name] = ['status' => $status, 'reason' => $status === 'REJECTED' ? 'INVALID_FORMAT' : null, 'id' => 'fake-'.$name];
        }

        return $out;
    }
}
