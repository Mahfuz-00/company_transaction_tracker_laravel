@component('mail::message')
# You're invited

Hi{{ $name ? ' '.$name : '' }},

@if($institutionName)
**{{ $institutionName }}** has invited you to join as a **{{ $role }}**.
@else
You have been invited to join as a **{{ $role }}**.
@endif

Follow the button below to choose your own password and finish setting up your
account. For your security, no temporary password is ever issued.

@component('mail::button', ['url' => $acceptUrl])
Set my password
@endcomponent

@if($expiresAt)
This link expires on **{{ $expiresAt->format('j F Y, g:i A') }}**.
@endif

If you weren't expecting this invitation you can safely ignore this email.

Thanks,<br>
{{ $institutionName ?: config('app.name') }}
@endcomponent
