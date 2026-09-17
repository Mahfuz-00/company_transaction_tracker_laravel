@component('mail.layout', [
    'subject' => $subject ?? 'You are invited',
    'institutionName' => $institutionName ?? null,
    'accent' => $accent ?? '#4f46e5',
    'headerSubtitle' => $isReset ? 'Password reset' : 'Account invitation',
])

<h1 style="margin:0 0 12px;font-size:20px;font-weight:700;color:#0f172a;letter-spacing:-0.01em;">
    {{ $isReset ? 'Reset your password' : "You're invited" }}
</h1>

<p style="margin:0 0 16px;font-size:14px;line-height:1.65;color:#475569;">
    Hi{{ $name ? ' '.$name : '' }},
</p>

@if($isReset)
<p style="margin:0 0 16px;font-size:14px;line-height:1.65;color:#475569;">
    An administrator has requested to set or reset the password for your
    {{ $institutionName ? $institutionName.' ' : '' }}account
    (<strong style="color:#0f172a;">{{ $email }}</strong>).
</p>
@else
<p style="margin:0 0 16px;font-size:14px;line-height:1.65;color:#475569;">
    @if($institutionName)
        <strong style="color:#0f172a;">{{ $institutionName }}</strong> has invited you to join as a
        <strong style="color:#0f172a;">{{ $role }}</strong>.
    @else
        You have been invited to join as a <strong style="color:#0f172a;">{{ $role }}</strong>.
    @endif
</p>
@endif
<p style="margin:0 0 24px;font-size:14px;line-height:1.65;color:#475569;">
    Follow the button below to choose your own password and finish setting up your account.
    For your security, no temporary password is ever issued by email.
</p>

{{-- Bulletproof CTA button --}}
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
    <tr>
        <td align="center" style="border-radius:10px;background-color:{{ $accent ?? '#4f46e5' }};">
            <a href="{{ $acceptUrl }}"
               target="_blank"
               style="display:inline-block;padding:13px 28px;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px;">
                {{ $isReset ? 'Set a new password' : 'Set my password' }}
            </a>
        </td>
    </tr>
</table>

<p style="margin:0 0 8px;font-size:12px;line-height:1.6;color:#94a3b8;">
    If the button doesn't work, copy and paste this link into your browser:
</p>
<p style="margin:0 0 20px;font-size:12px;line-height:1.6;color:#4f46e5;word-break:break-all;">
    {{ $acceptUrl }}
</p>

@if(!empty($expiresAt))
<p style="margin:0;font-size:12px;line-height:1.6;color:#94a3b8;">
    This link expires on <strong style="color:#475569;">{{ $expiresAt->format('j F Y, g:i A') }}</strong>.
</p>
@endif
@endcomponent
