<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Activation e-mail. Sent with ->locale(): the text follows the invited person's language. */
class UserInvitationMail extends Mailable
{
    public function __construct(public string $name, public string $organization, public ?string $inviter, public string $url, public int $days) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('invitation.subject', ['app' => config('app.name')]));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.invitation');
    }
}
