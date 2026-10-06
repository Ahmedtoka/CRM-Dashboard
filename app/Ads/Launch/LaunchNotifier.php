<?php

namespace App\Ads\Launch;

use App\Inbox\UserNotifier;
use App\Models\AdLaunch;
use App\Models\User;
use App\Models\UserNotification;

/**
 * The ads.launch.* notifications (L section 4), in-app through UserNotifier with a deep link. A manager's
 * awaiting_approval turns into one digest row once more than 5 arrived within 30 minutes (spec 3.6).
 */
final class LaunchNotifier
{
    public const PREFIX = 'ads.launch.';

    public const DIGEST = 'ads.launch.awaiting_digest';

    public const GROUP_AFTER = 5;

    public const GROUP_MINUTES = 30;

    public function __construct(private readonly UserNotifier $notifier) {}

    public function submitted(AdLaunch $l): void
    {
        $this->send([$this->buyerUser($l)], 'submitted', $l);
    }

    public function changesRequested(AdLaunch $l): void
    {
        $this->send([$l->preparer], 'changes_requested', $l, $this->reason($l));
    }

    public function forwarded(AdLaunch $l): void
    {
        $this->send([$l->preparer], 'forwarded', $l);
    }

    public function createFailed(AdLaunch $l): void
    {
        $this->send([$l->preparer, $this->buyerUser($l)], 'create_failed', $l, ['error' => mb_substr((string) $l->last_error, 0, 300)]);
    }

    public function live(AdLaunch $l, int $failed = 0): void
    {
        $this->send([$l->preparer, $this->buyerUser($l)], 'live', $l, ['failed' => $failed]);
    }

    public function returned(AdLaunch $l): void
    {
        $this->send([$l->preparer, $this->buyerUser($l)], 'returned', $l, $this->reason($l));
    }

    public function rejected(AdLaunch $l): void
    {
        $this->send([$l->preparer, $this->buyerUser($l)], 'rejected', $l, $this->reason($l));
    }

    public function onHold(AdLaunch $l): void
    {
        $this->send([$l->preparer, $this->buyerUser($l), ...$this->managers()], 'on_hold', $l);
    }

    public function released(AdLaunch $l): void
    {
        $this->send([$l->preparer, $this->buyerUser($l)], 'released', $l);
    }

    public function expiring(AdLaunch $l): void
    {
        $this->send([$l->preparer, $this->buyerUser($l), ...$this->managers()], 'expiring', $l, ['expires_at' => $l->expires_at?->toIso8601String()]);
    }

    /** A launching launch handed back (approve never finished): the approver approves again. */
    public function approveFailed(AdLaunch $l, ?User $approver): void
    {
        $this->send([$approver], 'approve_failed', $l);
    }

    public function expired(AdLaunch $l): void
    {
        $this->send([$l->preparer, $this->buyerUser($l)], 'expired', $l);
    }

    public function awaitingApproval(AdLaunch $l): void
    {
        $data = $this->data($l);
        foreach ($this->managers() as $u) {
            $recent = UserNotification::query()->where('user_id', $u->id)->where('type', self::PREFIX.'awaiting_approval')
                ->where('created_at', '>=', now()->subMinutes(self::GROUP_MINUTES))->count();
            if ($recent < self::GROUP_AFTER) {
                $this->notifier->notify($u, self::PREFIX.'awaiting_approval', $data);

                continue;
            }
            $digest = UserNotification::query()->where('user_id', $u->id)->where('type', self::DIGEST)->whereNull('read_at')
                ->where('created_at', '>=', now()->subMinutes(self::GROUP_MINUTES))->latest('id')->first();
            if ($digest !== null) {
                $digest->update(['data' => array_merge((array) $digest->data, ['count' => (int) ($digest->data['count'] ?? 1) + 1])]);

                continue;
            }
            $this->notifier->notify($u, self::DIGEST, ['count' => 1, 'link' => '/ads/approvals']);
        }
    }

    public static function link(AdLaunch $l): string
    {
        $waiting = in_array($l->state, [LaunchState::AwaitingApproval, LaunchState::Launching], true)
            || ($l->state === LaunchState::OnHold && $l->hold_from_state === LaunchState::AwaitingApproval);

        return match (true) {
            $waiting => '/ads/approvals?launch='.$l->public_id,
            in_array($l->state, [LaunchState::BuyerReview, LaunchState::CreatingPaused, LaunchState::CreateFailed], true) => '/ads/launches?box=review&launch='.$l->public_id,
            default => '/ads/launches?launch='.$l->public_id,
        };
    }

    /** @return array<string, mixed> */
    private function data(AdLaunch $l, array $extra = []): array
    {
        return array_merge([
            'launch_id' => $l->public_id, 'title' => (string) $l->material?->title, 'name' => (string) $l->material?->title,
            'account' => (string) $l->account?->name, 'state' => $l->state->value, 'link' => self::link($l),
        ], $extra);
    }

    /** @return array{code: string, reason: string} */
    private function reason(AdLaunch $l): array
    {
        return ['code' => (string) $l->decision_code, 'reason' => (string) $l->decision_reason];
    }

    private function buyerUser(AdLaunch $l): ?User
    {
        return $l->reviewer?->user;
    }

    /** @return list<User> */
    private function managers(): array
    {
        return User::query()->where('is_active', true)->where('ads_authority', true)->get()
            ->filter(fn (User $u) => $u->hasAdsAuthority())->values()->all();
    }

    /** @param  list<?User>  $users */
    private function send(array $users, string $event, AdLaunch $l, array $extra = []): void
    {
        $data = $this->data($l, $extra);
        $seen = [];
        foreach ($users as $u) {
            if ($u === null || ! $u->is_active || isset($seen[$u->id])) {
                continue;
            }
            $seen[$u->id] = true;
            $this->notifier->notify($u, self::PREFIX.$event, $data);
        }
    }
}
