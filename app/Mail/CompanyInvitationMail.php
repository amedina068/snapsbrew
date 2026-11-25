<?php

namespace App\Mail;

use App\Models\CompanyInvitation;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CompanyInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public CompanyInvitation $invitation,
        public User $inviter
    ) {}

    public function build(): self
    {
        return $this->subject('You are invited to join Snapsbrew')
            ->markdown('emails.company-invitation')
            ->with([
                'url' => $this->invitation->url(),
            ]);
    }
}
