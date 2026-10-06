<?php

namespace App\Ads\Launch;

/** The launch lifecycle (L 2.1 / 2.2). Transitions are enforced by LaunchService::transition (compare-and-set). */
enum LaunchState: string
{
    case Draft = 'draft';
    case ChangesRequested = 'changes_requested';
    case BuyerReview = 'buyer_review';
    case CreatingPaused = 'creating_paused';
    case CreateFailed = 'create_failed';
    case AwaitingApproval = 'awaiting_approval';
    case OnHold = 'on_hold';
    case Launching = 'launching';
    case Live = 'live';
    case Stopped = 'stopped';
    case Retired = 'retired';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Withdrawn = 'withdrawn';

    public const TERMINAL_VALUES = ['retired', 'rejected', 'expired', 'withdrawn'];

    /** States a stock-out may hold (T11): everything before launching, except a launch already on hold. */
    public const HOLDABLE_VALUES = ['draft', 'changes_requested', 'buyer_review', 'creating_paused', 'create_failed', 'awaiting_approval'];

    public const NON_TERMINAL_VALUES = ['draft', 'changes_requested', 'buyer_review', 'creating_paused', 'create_failed', 'awaiting_approval', 'on_hold', 'launching', 'live', 'stopped'];

    public function isTerminal(): bool
    {
        return in_array($this->value, self::TERMINAL_VALUES, true);
    }

    public function isPreLive(): bool
    {
        return in_array($this->value, [...self::HOLDABLE_VALUES, 'on_hold'], true);
    }

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Draft => [self::Draft, self::BuyerReview, self::Withdrawn, self::OnHold],
            self::ChangesRequested => [self::ChangesRequested, self::BuyerReview, self::Withdrawn, self::OnHold],
            self::BuyerReview => [self::BuyerReview, self::ChangesRequested, self::CreatingPaused, self::Withdrawn, self::OnHold],
            self::CreatingPaused => [self::AwaitingApproval, self::CreateFailed, self::BuyerReview, self::OnHold],
            self::CreateFailed => [self::CreatingPaused, self::BuyerReview, self::Withdrawn, self::OnHold],
            self::AwaitingApproval => [self::Launching, self::BuyerReview, self::Rejected, self::Expired, self::OnHold],
            self::OnHold => [self::Draft, self::ChangesRequested, self::BuyerReview, self::CreatingPaused, self::CreateFailed, self::AwaitingApproval, self::Expired],
            self::Launching => [self::Live, self::AwaitingApproval],
            self::Live => [self::Stopped, self::Retired],
            self::Stopped => [self::Live, self::Retired],
            self::Retired, self::Rejected, self::Expired, self::Withdrawn => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }
}
