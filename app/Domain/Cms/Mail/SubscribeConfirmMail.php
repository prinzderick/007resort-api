<?php

namespace App\Domain\Cms\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/** Double opt-in confirmation (queued). The plain confirmation token only exists in this message; the DB keeps its hash. */
class SubscribeConfirmMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $confirmUrl, public readonly string $unsubscribeUrl, public readonly ?string $name = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Please confirm your subscription to 007 Resort & Spa');
    }

    public function content(): Content
    {
        return new Content(view: 'cms::mail.subscribe-confirm', text: 'cms::mail.subscribe-confirm-text');
    }

    public function headers(): Headers
    {
        return new Headers(text: ['List-Unsubscribe' => '<'.$this->unsubscribeUrl.'>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']);
    }
}
