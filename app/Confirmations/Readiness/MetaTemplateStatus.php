<?php

namespace App\Confirmations\Readiness;

use App\Channels\Adapters\MetaGraphClient;
use App\Models\ChannelAccount;
use Illuminate\Http\Client\ConnectionException;

/**
 * Asks Meta for the templates of the number's WhatsApp Business Account (the same call as
 * `whatsapp:confirmation-templates`): exact name and language, since Meta's `?name=` is a prefix match.
 */
final class MetaTemplateStatus implements TemplateStatusSource
{
    public function __construct(private readonly MetaGraphClient $graph) {}

    public function statuses(ChannelAccount $account, array $names, string $language): array
    {
        $wabaId = $account->wabaId();

        if ($wabaId === null) {
            throw new TemplateLookupFailed('no_waba');
        }

        $out = [];

        foreach ($names as $name) {
            try {
                $response = $this->graph->get($account, "{$wabaId}/message_templates", [
                    'name' => $name,
                    'fields' => 'id,name,language,status,category,rejected_reason',
                    'limit' => 50,
                ]);
            } catch (ConnectionException) {
                throw new TemplateLookupFailed('graph_unreachable');
            }

            if (! $response->successful()) {
                $code = (int) $response->json('error.code', 0);

                throw new TemplateLookupFailed(in_array($code, [190, 10, 200], true) ? 'token_refused' : 'graph_error', (string) $response->json('error.message', 'HTTP '.$response->status()));
            }

            $out[$name] = ['status' => 'MISSING', 'reason' => null, 'id' => null];

            foreach ((array) $response->json('data') as $row) {
                if (is_array($row) && ($row['name'] ?? null) === $name && ($row['language'] ?? null) === $language) {
                    $out[$name] = [
                        'status' => strtoupper((string) ($row['status'] ?? 'UNKNOWN')),
                        'reason' => isset($row['rejected_reason']) && $row['rejected_reason'] !== 'NONE' ? (string) $row['rejected_reason'] : null,
                        'id' => isset($row['id']) ? (string) $row['id'] : null,
                    ];
                }
            }
        }

        return $out;
    }
}
