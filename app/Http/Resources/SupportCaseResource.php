<?php

namespace App\Http\Resources;

use App\Cases\CaseSummary;
use App\Media\MediaUrls;
use App\Models\MessageAttachment;
use App\Models\SupportCase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SupportCase */
class SupportCaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $ids = array_map('intval', (array) $this->photo_attachment_ids);
        $photos = $ids === [] ? collect() : MessageAttachment::query()->whereIn('id', $ids)->get()
            ->sortBy(fn (MessageAttachment $a) => array_search($a->id, $ids, true));
        $assignee = $this->assigned_to_id !== null ? $this->assignedTo : null;
        $customer = $this->customer_id !== null ? $this->customer : null;

        return [
            'id' => $this->id,
            'type' => $this->type,
            'type_label' => $this->typeLabel(),
            'status' => $this->status,
            'priority' => $this->priority,
            'order_id' => $this->order_id,
            'order_number' => $this->order_number,
            'summary_header' => CaseSummary::header($this->resource),
            'summary_sections' => CaseSummary::sections($this->resource),
            'data' => $this->data ?? [],
            'items' => CaseSummary::selectedItems(is_array($this->data) ? $this->data : []),
            // 2026-09-19: the return/exchange flow's answer and the replacement she linked.
            'request_kind' => in_array($this->type, ['return', 'exchange'], true) ? $this->type : null,
            'reason' => is_array($this->data) && is_scalar($this->data['reason_title'] ?? $this->data['reason'] ?? null) ? (string) ($this->data['reason_title'] ?? $this->data['reason']) : null,
            'exchange_product' => CaseSummary::exchangeProduct(is_array($this->data) ? $this->data : []),
            'photos' => $photos->map(fn (MessageAttachment $a) => ['id' => $a->id, 'url' => MediaUrls::show($a)])->values()->all(),
            'policy_notes' => $this->policy_notes ?? [],
            'assigned_to' => $assignee ? ['id' => $assignee->id, 'name' => $assignee->name] : null,
            'conversation_id' => $this->conversation_id,
            'customer' => $customer ? ['id' => $customer->id, 'name' => $customer->name, 'phone' => $customer->phone] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
        ];
    }
}
