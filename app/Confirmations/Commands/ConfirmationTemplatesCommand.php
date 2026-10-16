<?php

namespace App\Confirmations\Commands;

use App\Channels\Adapters\MetaGraphClient;
use App\Confirmations\WhatsApp\TemplateDefinitions;
use App\Models\ChannelAccount;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;

/**
 * Checks, and with --submit creates, the order-confirmation templates on the WhatsApp
 * Business Account (`/{waba-id}/message_templates`). Only a template missing on the WABA
 * is submitted: an approved, pending or rejected one is left alone (a rejected one is
 * fixed by editing it in WhatsApp Manager).
 */
class ConfirmationTemplatesCommand extends Command
{
    use ResolvesWhatsAppAccount;

    protected $signature = 'whatsapp:confirmation-templates
        {--submit : Create the templates that are missing on the WhatsApp Business Account}
        {--account= : ChannelAccount id of the WhatsApp number (default: the connected live one)}';

    protected $description = 'Show (or submit) the WhatsApp order-confirmation templates on Meta';

    private const NOTES = [
        'MISSING' => 'run with --submit to create it',
        'PENDING' => 'Meta is reviewing it',
        'PAUSED' => 'paused by Meta for low quality',
        'DISABLED' => 'disabled by Meta',
    ];

    public function handle(MetaGraphClient $graph): int
    {
        $account = $this->whatsAppAccount();

        if ($account === null) {
            return self::FAILURE;
        }

        $wabaId = $account->wabaId();

        if ($wabaId === null) {
            $this->error("WhatsApp account {$account->id} has no WhatsApp Business Account id. Reconnect it in Settings > Integrations.");

            return self::FAILURE;
        }

        $this->line("WhatsApp account {$account->id} ({$account->name}), WABA {$wabaId}");

        $statuses = [];

        foreach (TemplateDefinitions::all() as $name => $definition) {
            $row = $this->lookup($graph, $account, $wabaId, $name, $definition['language']);

            if ($row === false) {
                return self::FAILURE;
            }

            $statuses[$name] = $row;
        }

        $this->table(['Template', 'Language', 'Status', 'Note'], array_map(
            fn (string $name) => [$name, TemplateDefinitions::LANGUAGE, $this->status($statuses[$name]), $this->note($statuses[$name])],
            array_keys($statuses),
        ));

        if (! $this->option('submit')) {
            return self::SUCCESS;
        }

        $missing = array_keys(array_filter($statuses, fn ($row) => $row === null));

        if ($missing === []) {
            $this->info('Nothing to submit.');

            return self::SUCCESS;
        }

        $ok = true;

        foreach ($missing as $name) {
            $ok = $this->submit($graph, $account, $wabaId, $name) && $ok;
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The template row on the WABA (exact name and language; Meta's ?name= is a partial
     * match), null when it does not exist, false when Meta could not be asked.
     *
     * @return array<string, mixed>|null|false
     */
    private function lookup(MetaGraphClient $graph, ChannelAccount $account, string $wabaId, string $name, string $language): array|null|false
    {
        try {
            $response = $graph->get($account, "{$wabaId}/message_templates", [
                'name' => $name,
                'fields' => 'id,name,language,status,category,rejected_reason',
                'limit' => 50,
            ]);
        } catch (ConnectionException $e) {
            $this->error("Could not reach Meta: {$e->getMessage()}");

            return false;
        }

        if (! $response->successful()) {
            $this->error("Meta refused the template lookup for {$name}: ".($response->json('error.message') ?? 'HTTP '.$response->status()));

            return false;
        }

        foreach ((array) $response->json('data') as $row) {
            if (is_array($row) && ($row['name'] ?? null) === $name && ($row['language'] ?? null) === $language) {
                return $row;
            }
        }

        return null;
    }

    private function submit(MetaGraphClient $graph, ChannelAccount $account, string $wabaId, string $name): bool
    {
        try {
            $result = $graph->post($account, "{$wabaId}/message_templates", TemplateDefinitions::find($name));
        } catch (ConnectionException $e) {
            $this->error("{$name}: failed (could not reach Meta: {$e->getMessage()})");

            return false;
        }

        if (! $result->success) {
            $this->error("{$name}: failed ({$result->error})");

            return false;
        }

        // SendResult keeps only the id; a new template always starts in review.
        $this->info("{$name}: submitted (id {$result->externalId}, PENDING)");

        return true;
    }

    /** @param  array<string, mixed>|null  $row */
    private function status(?array $row): string
    {
        return $row === null ? 'MISSING' : strtoupper((string) ($row['status'] ?? 'UNKNOWN'));
    }

    /** @param  array<string, mixed>|null  $row */
    private function note(?array $row): string
    {
        $status = $this->status($row);

        if ($status === 'REJECTED') {
            $reason = (string) ($row['rejected_reason'] ?? '');

            return ($reason !== '' && $reason !== 'NONE' ? $reason.': ' : '').'edit it in WhatsApp Manager';
        }

        return self::NOTES[$status] ?? '';
    }
}
