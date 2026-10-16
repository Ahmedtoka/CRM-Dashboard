<?php

namespace App\Confirmations;

/**
 * Why a confirmation was not sent (`order_confirmations.skip_reason`, spec 2026-10-16 "Skip rules").
 * The trigger (W1) writes these values; the UI translates them as `order_confirmations.skip_reasons.<value>`.
 */
enum SkipReason: string
{
    case Disabled = 'disabled';
    case NoPhone = 'no_phone';
    case TooOld = 'too_old';
    case BeforeFloor = 'before_floor';
    case TestOrder = 'test_order';
    case Cancelled = 'cancelled';
    case ChatOrder = 'chat_order';
    case NoWhatsAppChannel = 'no_whatsapp_channel';
    case Duplicate = 'duplicate';
    case PosOrder = 'pos_order';
    case TooLate = 'too_late';
}
