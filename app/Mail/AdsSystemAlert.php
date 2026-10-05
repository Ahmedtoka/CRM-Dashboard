<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Plain system alert for the admins: which ads check failed. No customer data, no token. */
class AdsSystemAlert extends Mailable
{
    /** @param  string  $subject_  the account or queue name the check is about, may be empty */
    public function __construct(public string $reason, public string $status, public string $subject_ = '') {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'تنبيه الإعلانات / Ads alert: '.$this->reason);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.ads-system-alert', with: [
            'reason' => $this->reason,
            'status' => $this->status,
            'subject_' => $this->subject_,
            'link' => rtrim((string) config('app.url'), '/').'/ads/sync',
        ]);
    }
}
