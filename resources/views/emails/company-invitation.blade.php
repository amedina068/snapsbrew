@component('mail::message')
# You're invited to join {{ \App\Support\MarkdownText::escape($inviter->company->name) }} on {{ config('app.name') }}

{{ \App\Support\MarkdownText::escape($inviter->name ?? $inviter->email) }} has invited you to collaborate on their {{ config('app.name') }} workspace.

@component('mail::button', ['url' => $url])
Accept invitation
@endcomponent

This invitation expires on {{ optional($invitation->expires_at)->toDayDateTimeString() ?? 'N/A' }}.

If you do not expect this invitation, you can safely ignore this message.

Thanks,<br>
The {{ config('app.name') }} team
@endcomponent
