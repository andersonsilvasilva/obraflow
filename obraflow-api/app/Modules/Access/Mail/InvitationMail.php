<?php

namespace App\Modules\Access\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Convite por e-mail. O token em claro só existe aqui (e-mail); no banco fica apenas o hash. */
class InvitationMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $tenantName,
        public readonly string $inviterName,
        public readonly string $roleName,
        public readonly string $token,
        public readonly string $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Convite para {$this->tenantName} no ObraFlow");
    }

    public function content(): Content
    {
        return new Content(text: 'mail.invitation', with: [
            'url' => rtrim(config('obraflow.frontend_url'), '/').'/accept-invitation?token='.$this->token,
        ]);
    }
}
