<?php

namespace App\Ads\Launch;

use App\Ads\Audit\AdsAudit;
use App\Ads\Control\Write\WriteActionService;
use App\Ads\Control\Write\WriteDenied;
use App\Models\AdLaunch;
use App\Models\AdPublication;
use App\Models\AdWriteAction;
use App\Models\User;

/**
 * Manager Approve = the ads go LIVE from the CRM (D3): per ad, WriteActionService::propose + confirm by the same manager in
 * the same request (source launch_approval). The ad only, never a parent (D4). Four eyes except an admin (D5 / O1).
 */
final class ApproveLaunch
{
    public const SOURCE = 'launch_approval';

    public function __construct(
        private readonly LaunchService $launches,
        private readonly LaunchChecks $checks,
        private readonly WriteActionService $writes,
        private readonly LaunchNotifier $notify,
    ) {}

    /**
     * @param  list<string>  $ackWarnings  warning keys the approver ticked as seen
     * @return array{launch: AdLaunch, ads: list<array{publication_id: int, ad_name: string, outcome: string, code: ?string, message: ?string}>, self_approved: bool}
     *
     * @throws WriteDenied
     */
    public function approve(User $u, AdLaunch $l, int $revision, string $checksHash, array $ackWarnings): array
    {
        if (! LaunchPolicy::canApprove($u)) {
            throw WriteDenied::make('ads_authority_required');
        }
        $l->refresh();
        if ($l->state !== LaunchState::AwaitingApproval) {
            throw $this->notWaiting($l);
        }
        $self = in_array($u->id, array_values(array_filter([$l->prepared_by_id, $l->forwarded_by_id])), true);
        if ($self && ! $u->isAdmin()) {
            throw WriteDenied::make('self_approval');
        }
        $results = $this->checks->run($l, 'approve', $u);
        if ($l->revision !== $revision || ! hash_equals(LaunchChecks::hash($l, $results), $checksHash)) {
            throw WriteDenied::make('launch_changed', ['revision' => $l->revision]);
        }
        $results = LaunchChecks::merge($results, $this->checks->live($l, $u));
        $blocking = LaunchChecks::blocking($results);
        if ($blocking !== []) {
            $this->checks->store($l, $results);

            throw WriteDenied::make('checks_failed', [
                'keys' => array_map(fn (CheckResult $c) => $c->key, $blocking),
                'checks' => array_map(fn (CheckResult $c) => $c->toArray(), $results),
            ]);
        }
        $unacked = array_values(array_diff(array_map(fn (CheckResult $c) => $c->key, LaunchChecks::warnings($results)), $ackWarnings));
        if ($unacked !== []) {
            throw WriteDenied::make('warnings_unacknowledged', ['keys' => $unacked, 'checks' => array_map(fn (CheckResult $c) => $c->toArray(), $results)]);
        }

        try {
            $l = $this->launches->transition($l, [LaunchState::AwaitingApproval], LaunchState::Launching, [
                'approved_at' => now(), 'decided_by_id' => $u->id, 'decided_at' => now(), 'self_approved' => $self,
                'decision_code' => null, 'decision_reason' => null, 'last_error' => null,
            ], $revision, $u, ['self_approved' => $self, 'acknowledged' => array_values($ackWarnings)]);
        } catch (WriteDenied $e) {
            throw $e->errorCode === 'launch_state' ? $this->notWaiting($l->fresh() ?? $l) : $e;
        }

        $ads = [];
        foreach (AdPublication::query()->where('ad_launch_id', $l->id)->whereNull('archived_at')->where('status', AdPublication::DONE)->whereNotNull('external_ad_id')->orderBy('id')->get() as $p) {
            $ads[] = $this->runOne($u, $l, $p);
        }

        return ['launch' => $this->settle($u, $l, $ads), 'ads' => $ads, 'self_approved' => $self];
    }

    /** E5: someone else got there first → 409 launch_taken with their name; anything else → launch_state. */
    private function notWaiting(AdLaunch $l): WriteDenied
    {
        if (in_array($l->state, [LaunchState::Launching, LaunchState::Live, LaunchState::Stopped], true)) {
            return WriteDenied::make('launch_taken', ['by' => (string) $l->decider?->name]);
        }

        return WriteDenied::make('launch_state', ['state' => $l->state->value]);
    }

    /** @return array{publication_id: int, ad_name: string, outcome: string, code: ?string, message: ?string} */
    private function runOne(User $u, AdLaunch $l, AdPublication $p): array
    {
        $base = ['publication_id' => $p->id, 'ad_name' => (string) $p->ad_name];
        try {
            $x = $this->writes->propose($u, $l->account, 'ad', (string) $p->external_ad_id, 'active',
                __('ads.launch.approve_reason', ['id' => $l->public_id]), 'launch:'.$l->public_id.':'.$p->id.':r'.$l->revision,
                self::SOURCE, $l->public_id)['action'];
            if ($x->state === AdWriteAction::PROPOSED) {
                $x = $this->writes->confirm($u, $x, (string) $x->diff_hash);
            }
            $p->forceFill(['run_write_action_id' => $x->id])->save();

            return $base + match ($x->state) {
                AdWriteAction::SUCCEEDED => ['outcome' => 'succeeded', 'code' => null, 'message' => null],
                AdWriteAction::EXECUTING, AdWriteAction::UNKNOWN => ['outcome' => 'unknown', 'code' => null, 'message' => null],
                default => ['outcome' => 'failed', 'code' => $x->error_code, 'message' => WriteDenied::messageFor((string) $x->error_code)],
            };
        } catch (WriteDenied $e) {
            return $base + ['outcome' => 'failed', 'code' => $e->errorCode, 'message' => $e->getMessage()];
        }
    }

    /** T8: any Run succeeded → live; only unknown outcomes → stays launching (E8); every Run failed → back to awaiting (E7). */
    private function settle(User $u, AdLaunch $l, array $ads): AdLaunch
    {
        $count = fn (string $outcome) => count(array_filter($ads, fn (array $a) => $a['outcome'] === $outcome));
        $codes = implode(',', array_values(array_unique(array_filter(array_column(array_filter($ads, fn (array $a) => $a['outcome'] === 'failed'), 'code')))));
        try {
            if ($count('succeeded') > 0) {
                $l = $this->launches->transition($l, [LaunchState::Launching], LaunchState::Live,
                    ['live_at' => now(), 'stopped_at' => null, 'last_error' => $codes !== '' ? $codes : null], null, $u, ['ads' => $ads]);
                $this->notify->live($l, $count('failed'));

                return $l;
            }
            if ($count('unknown') > 0) {
                $l->forceFill(['last_error' => 'outcome_unknown'])->save();
                AdsAudit::record('launch.outcome_unknown', $l, null, null, ['ads' => $ads], $u);

                return $l;
            }

            return $this->launches->transition($l, [LaunchState::Launching], LaunchState::AwaitingApproval, [
                'approved_at' => null, 'decided_by_id' => null, 'decided_at' => null, 'self_approved' => false, 'last_error' => $codes !== '' ? $codes : 'failed',
            ], null, $u, ['ads' => $ads], 'launch.approve_failed');
        } catch (WriteDenied) {
            return $l->refresh(); // the sync moved it meanwhile (LaunchMonitor): its state is the truth
        }
    }
}
