<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class NotificationMail extends Mailable
{
    public function __construct(public string $subjectLine, public string $bodyText, public ?string $actionUrl, public string $mailLocale = 'en') {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        app()->setLocale($this->mailLocale);
        return new Content(view: 'mail.notification');
    }
}
