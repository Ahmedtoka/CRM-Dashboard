<?php

namespace App\Legal\Commands;

use App\Models\DataDeletionRequest;
use Illuminate\Console\Command;

/**
 * Meta Data Deletion Callback requests still open — pending, or needs_manual_review
 * when Meta could not map the app-scoped id to our pages' ids (DeleteMetaUserData).
 * Shows the app-scoped id so staff can follow up; --all includes finished ones.
 */
class DeletionRequestsCommand extends Command
{
    protected $signature = 'legal:deletion-requests {--all : Include completed and not_found requests}';

    protected $description = 'List Meta data deletion requests that still need action';

    public function handle(): int
    {
        $rows = DataDeletionRequest::query()
            ->when(! $this->option('all'), fn ($q) => $q->whereIn('status', [
                DataDeletionRequest::STATUS_PENDING,
                DataDeletionRequest::STATUS_NEEDS_REVIEW,
            ]))
            ->orderBy('requested_at')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No open deletion requests.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Code', 'Status', 'Platform', 'App-scoped user id', 'Requested', 'Completed'],
            $rows->map(fn (DataDeletionRequest $r) => [
                $r->id,
                $r->confirmation_code,
                $r->status,
                $r->platform,
                $r->external_user_id,
                $r->requested_at?->toDateTimeString(),
                $r->completed_at?->toDateTimeString() ?? '—',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
