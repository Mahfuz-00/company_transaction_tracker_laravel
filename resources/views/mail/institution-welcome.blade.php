@component('mail.layout', [
    'subject' => $subject ?? 'Welcome to the platform',
    'institutionName' => $institutionName ?? null,
    'accent' => $accent ?? '#4f46e5',
    'headerSubtitle' => $typeLabel ?? 'Institution workspace',
])

@php
    $platform = $platformName ?? \App\Support\PlatformBranding::name();
    $institution = $institutionName ?? 'your workspace';
@endphp
<h1 style="margin:0 0 12px;font-size:20px;font-weight:700;color:#0f172a;letter-spacing:-0.01em;">
    Welcome to {{ $platform }}! 🎉
</h1>

<p style="margin:0 0 16px;font-size:14px;line-height:1.65;color:#475569;">
    Hi {{ $adminName }},
</p>

<p style="margin:0 0 16px;font-size:14px;line-height:1.65;color:#475569;">
    Your workspace on {{ $platform }} - <strong style="color:#0f172a;">{{ $institution }}</strong> -
    has been created successfully, and you are its administrator. You can invite your team,
    add members, and start tracking meals, deposits and expenses right away.
</p>

{{-- Billing-status panel: the key detail the admin needs up front. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;border-radius:12px;background-color:#f8fafc;border:1px solid #e2e8f0;">
    <tr>
        <td style="padding:18px 20px;">
            @if($mode === 'trial')
                <p style="margin:0 0 6px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#0284c7;">
                    Free trial active
                </p>
                <p style="margin:0;font-size:14px;line-height:1.6;color:#334155;">
                    Your <strong>{{ $trialDays }}-day trial period has started</strong>.
                    @if(!empty($trialEndsAt))
                        It runs until
                        <strong style="color:#0f172a;">{{ $trialEndsAt->format('j F Y') }}</strong>.
                    @endif
                    You have full access during the trial - no card required. When you are ready,
                    you can upgrade to a permanent subscription at any time.
                </p>
            @else
                <p style="margin:0 0 6px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#059669;">
                    Subscription active
                </p>
                <p style="margin:0;font-size:14px;line-height:1.6;color:#334155;">
                    Your workspace is on a <strong>full, permanent subscription</strong>. There is no
                    trial limit - every module is unlocked from day one.
                </p>
            @endif
        </td>
    </tr>
</table>

<p style="margin:0 0 12px;font-size:14px;line-height:1.65;color:#475569;">
    What you can do now:
</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
    <tr><td style="padding:4px 0;font-size:14px;color:#475569;">✅ &nbsp;Invite members and send each a secure setup link</td></tr>
    <tr><td style="padding:4px 0;font-size:14px;color:#475569;">✅ &nbsp;Record meals, deposits and expenses across your team</td></tr>
    <tr><td style="padding:4px 0;font-size:14px;color:#475569;">✅ &nbsp;Track balances, subsidies and month-end reports</td></tr>
    <tr><td style="padding:4px 0;font-size:14px;color:#475569;">✅ &nbsp;Customise your theme, branding and terminology</td></tr>
</table>

{{-- Bulletproof CTA button --}}
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
    <tr>
        <td align="center" style="border-radius:10px;background-color:{{ $accent ?? '#4f46e5' }};">
            <a href="{{ $loginUrl }}"
               target="_blank"
               style="display:inline-block;padding:13px 28px;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px;">
                Sign in to your workspace
            </a>
        </td>
    </tr>
</table>

{{-- CREDENTIALS BLOCK ----------------------------------------------------
     The admin is never locked out: they get a temporary password AND a signed
     setup link. The password lets them in immediately; the link lets them set
     their own password securely. Both are shown up front, prominently. --}}
@if(!empty($temporaryPassword) || !empty($setupUrl))
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;border-radius:12px;background-color:#eef2ff;border:1px solid #c7d2fe;">
    <tr>
        <td style="padding:18px 20px;">
            <p style="margin:0 0 12px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#4338ca;">
                Your sign-in details
            </p>

            <p style="margin:0 0 4px;font-size:13px;color:#475569;">
                Email: <strong style="color:#0f172a;">{{ $adminEmail }}</strong>
            </p>

            @if(!empty($temporaryPassword))
            <p style="margin:0 0 14px;font-size:13px;color:#475569;">
                Temporary password:
                <strong style="display:inline-block;font-family:'Courier New',monospace;font-size:15px;letter-spacing:0.06em;color:#0f172a;background:#ffffff;border:1px solid #c7d2fe;border-radius:6px;padding:3px 8px;">{{ $temporaryPassword }}</strong>
            </p>
            @endif
            @if(!empty($setupUrl))
            {{-- Prefer setting your own password via the secure link --}}
            <p style="margin:0 0 10px;font-size:12px;color:#475569;">
                Or, for better security, set your own password now:
            </p>
            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0;">
                <tr>
                    <td align="center" style="border-radius:8px;background-color:{{ $accent ?? '#4f46e5' }};">
                        <a href="{{ $setupUrl }}"
                           target="_blank"
                           style="display:inline-block;padding:10px 22px;font-size:13px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:8px;">
                            Set my own password
                        </a>
                    </td>
                </tr>
            </table>
            @endif
        </td>
    </tr>
</table>
@endif
<p style="margin:0;font-size:12px;line-height:1.6;color:#94a3b8;">
    For your security, please change your password after your first sign-in.
    If you did not expect this email, you can safely ignore it.
</p>

@endcomponent
