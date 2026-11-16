<?php

namespace App\Mail;

use App\Models\ContactMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ContactMessageReceived extends Mailable
{
    public function __construct(public readonly ContactMessage $contact) {}

    public function envelope(): Envelope
    {
        $who = $this->contact->company ?: $this->contact->name;

        return new Envelope(
            subject: "Nouvelle demande de contact — {$who}",
            replyTo: [new Address($this->contact->email, $this->contact->name)],
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.contact-message-received');
    }
}
