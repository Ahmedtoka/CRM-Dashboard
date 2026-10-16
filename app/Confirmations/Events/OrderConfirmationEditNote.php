<?php

namespace App\Confirmations\Events;

use App\Models\OrderConfirmation;
use Illuminate\Foundation\Events\Dispatchable;

/** The customer sent the free-text edit after tapping "edit": it is on the row's edit_note. */
final class OrderConfirmationEditNote
{
    use Dispatchable;

    public function __construct(public readonly OrderConfirmation $confirmation) {}
}
