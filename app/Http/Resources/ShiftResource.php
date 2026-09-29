<?php

namespace App\Http\Resources;

use App\Models\Shift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A shift on the live board (today's templates made real). Members are included when loaded,
 * without the ones who left.
 *
 * @mixin Shift
 */
class ShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Shift $s */
        $s = $this->resource;
        $leader = $s->leader;

        return [
            'id' => $s->id,
            'date' => $s->date?->toDateString(),
            'shift_key' => $s->shift_key,
            'name' => $s->name,
            'status' => $s->status,
            'starts_at' => $s->starts_at?->toIso8601String(),
            'ends_at' => $s->ends_at?->toIso8601String(),
            'opened_at' => $s->opened_at?->toIso8601String(),
            'closed_at' => $s->closed_at?->toIso8601String(),
            'leader' => $leader ? ['id' => $leader->id, 'name' => $leader->name, 'color' => $leader->color] : null,
            'settings_snapshot' => $s->settings_snapshot,
            'members' => $this->whenLoaded('members', fn () => ShiftMemberResource::collection(
                $s->members->where('status', '!=', 'left')->values()
            )->resolve($request)),
        ];
    }
}
