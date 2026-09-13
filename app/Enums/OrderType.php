<?php

namespace App\Enums;

enum OrderType: string
{
    case Cod = 'cod';
    case PaymentLink = 'payment_link';
}
