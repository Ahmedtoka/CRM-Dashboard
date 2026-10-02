<?php

namespace App\Ads\Buyers;

use App\Models\AdAccount;
use App\Models\AdAccountAssignment;
use App\Models\MediaBuyer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Dated ownership of ad accounts: one buyer per account per day, no overlaps. */
final class AssignmentService
{
    /**
     * Hand the account to $buyer from $startsOn (null buyer = unassigned from that date, which just closes the open row).
     * Re-assigning the current holder is a no-op; a start equal to the open row's start corrects that row.
     */
    public function assign(AdAccount $account, ?MediaBuyer $buyer, CarbonImmutable $startsOn): ?AdAccountAssignment
    {
        return DB::transaction(function () use ($account, $buyer, $startsOn) {
            $day = $startsOn->toDateString();
            $open = AdAccountAssignment::where('ad_account_id', $account->id)->whereNull('ends_on')->orderByDesc('starts_on')->first();

            if ($open === null) {
                $lastEnd = AdAccountAssignment::where('ad_account_id', $account->id)->max('ends_on');
                if ($lastEnd !== null && $day <= substr((string) $lastEnd, 0, 10)) {
                    throw ValidationException::withMessages(['starts_on' => __('ads.assignment_overlaps')]);
                }

                return $buyer === null ? null : $this->open($account, $buyer, $day);
            }

            $openStart = $open->starts_on->toDateString();
            if ($day < $openStart) {
                throw ValidationException::withMessages(['starts_on' => __('ads.assignment_before_open')]);
            }

            if ($buyer !== null && $open->media_buyer_id === $buyer->id) {
                return $open;
            }

            if ($day === $openStart) {
                if ($buyer === null) {
                    $open->delete();

                    return null;
                }
                $open->update(['media_buyer_id' => $buyer->id]);

                return $open;
            }

            $open->update(['ends_on' => $startsOn->subDay()->toDateString()]);

            return $buyer === null ? null : $this->open($account, $buyer, $day);
        });
    }

    /** @return list<array{buyer_id:int|null,buyer:string|null,starts_on:string,ends_on:string|null}> newest first */
    public function history(AdAccount $account): array
    {
        return AdAccountAssignment::with('buyer')
            ->where('ad_account_id', $account->id)
            ->orderByDesc('starts_on')->orderByDesc('id')
            ->get()
            ->map(fn (AdAccountAssignment $a) => [
                'buyer_id' => $a->media_buyer_id,
                'buyer' => $a->buyer?->name,
                'starts_on' => $a->starts_on->toDateString(),
                'ends_on' => $a->ends_on?->toDateString(),
            ])->all();
    }

    private function open(AdAccount $account, MediaBuyer $buyer, string $day): AdAccountAssignment
    {
        return AdAccountAssignment::create([
            'ad_account_id' => $account->id,
            'media_buyer_id' => $buyer->id,
            'starts_on' => $day,
            'ends_on' => null,
        ]);
    }
}
