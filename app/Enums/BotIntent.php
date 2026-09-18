<?php

namespace App\Enums;

/**
 * Intents the message bot classifies an inbound customer message into
 * (spec §4.3). The comment bot keeps using {@see CommentIntent}.
 */
enum BotIntent: string
{
    case Greeting = 'greeting';
    case Price = 'price';
    case Availability = 'availability';
    case SizeChart = 'size_chart';
    case SizeRecommendation = 'size_recommendation';
    case Shipping = 'shipping';
    case ExchangeReturn = 'exchange_return';
    case Payment = 'payment';
    case OrderStatus = 'order_status';
    case Purchase = 'purchase';
    case Complaint = 'complaint';
    case Other = 'other';

    /** The bot answers questions only: these always go to a human (spec §4.1). */
    public function handsOver(): bool
    {
        return in_array($this, [self::Purchase, self::SizeRecommendation, self::OrderStatus, self::Complaint], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Greeting => 'تحية',
            self::Price => 'سؤال عن السعر',
            self::Availability => 'سؤال عن التوفر',
            self::SizeChart => 'جدول المقاسات',
            self::SizeRecommendation => 'ترشيح مقاس',
            self::Shipping => 'الشحن',
            self::ExchangeReturn => 'الاستبدال والاسترجاع',
            self::Payment => 'الدفع',
            self::OrderStatus => 'حالة أوردر',
            self::Purchase => 'عايزة تطلب',
            self::Complaint => 'شكوى',
            self::Other => 'أخرى',
        };
    }
}
