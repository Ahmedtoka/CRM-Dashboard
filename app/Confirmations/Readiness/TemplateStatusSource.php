<?php

namespace App\Confirmations\Readiness;

use App\Models\ChannelAccount;

/**
 * Where the approval state of the WhatsApp templates comes from (round 2026-10-24): Meta's
 * `/{waba-id}/message_templates` for a live number, a canned answer for a fake one and in tests.
 */
interface TemplateStatusSource
{
    /**
     * @param  list<string>  $names  template names to look up
     * @return array<string, array{status:string, reason:?string, id:?string}> keyed by template name; a template Meta
     *                                                                         does not have reads `MISSING`
     *
     * @throws TemplateLookupFailed when Meta could not be asked (network, token refused)
     */
    public function statuses(ChannelAccount $account, array $names, string $language): array;
}
