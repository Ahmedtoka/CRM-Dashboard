<?php

namespace App\Models;

use App\Enums\Platform;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsDaily extends Model
{
    /** @use HasFactory<\Database\Factories\AnalyticsDailyFactory> */
    use HasFactory;

    protected $table = 'analytics_daily';

    protected $fillable = [
        'date',
        'user_id',
        'platform',
        'messages_sent',
        'conversations_handled',
        'first_responses',
        'follow_ups',
        'resolved',
        'avg_first_response_sec',
        'avg_response_sec',
        'comments_handled',
        'orders_count',
        'orders_total',
        'online_minutes',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'platform' => Platform::class,
            'messages_sent' => 'integer',
            'conversations_handled' => 'integer',
            'first_responses' => 'integer',
            'follow_ups' => 'integer',
            'resolved' => 'integer',
            'avg_first_response_sec' => 'integer',
            'avg_response_sec' => 'integer',
            'comments_handled' => 'integer',
            'orders_count' => 'integer',
            'orders_total' => 'decimal:2',
            'online_minutes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
