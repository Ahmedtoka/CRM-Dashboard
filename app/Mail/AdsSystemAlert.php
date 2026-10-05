<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Plain system alert for the admins: every ads check that went bad in one ads:health run. No customer data, no token. */
class AdsSystemAlert extends Mailable
{
    /** @param  list<array{reason: string, status: string, subject: string}>  $lines  subject = the account or queue name, may be empty */
    public function __construct(public array $lines) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'تنبيه الإعلانات / Ads alert ('.count($this->lines).')');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.ads-system-alert', with: [
            'lines' => $this->lines,
            'link' => rtrim((string) config('app.url'), '/').'/ads/sync',
        ]);
    }
}
