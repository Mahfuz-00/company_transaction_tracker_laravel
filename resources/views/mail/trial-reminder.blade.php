@component('mail.layout', [
    'subject' => $subject ?? 'Your trial ends soon',
    'institutionName' => $institutionName ?? null,
    'accent' => $accent ?? '#4f46e5',
    'headerSubtitle' => 'Subscription reminder',
])

<h1 style="margin:0 0 12px;font-size:20px;font-weight:700;color:#0f172a;letter-spacing:-0.01em;">
    {{ $state === 'expired' ? 'Your free trial has ended' : 'Your free trial ends soon' }}
</h1>

<p style="margin:0 0 16px;font-size:14px;line-height:1.65;color:#475569;">
    Hi {{ $adminName }},
</p>

@if($state === 'expired')
<p style="margin:0 0 16px;font-size:14px;line-height:1.65;color:#475569;">
    The 7-day free trial for <strong style="color:#0f172a;">{{ $institutionName }}</strong>
    has now ended. Your data is safe and has not been deleted - upgrade to a permanent
    subscription to keep full access to every module.
</p>
@else
<p style="margin:0 0 16px;font-size:14px;line-height:1.65;color:#475569;">
    Your free trial for <strong style="color:#0f172a;">{{ $institutionName }}</strong> is
    almost over.
    @if($daysLeft !== null)
        You have <strong style="color:#0f172a;">{{ $daysLeft }} day{{ $daysLeft === 1 ? '' : 's' }}</strong> left
    @endif
    @if(!empty($trialEndsAt))
        (until {{ $trialEndsAt->format('j F Y') }})
    @endif
    . Upgrade now to avoid any interruption to your workspace.
@endif

{{-- Benefit panel --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;border-radius:12px;background-color:#f8fafc;border:1px solid #e2e8f0;">
    <tr>
        <td style="padding:18px 20px;">
            <p style="margin:0 0 10px;font-size:13px;font-weight:700;color:#0f172a;">
                A permanent subscription keeps everything unlocked:
            </p>
            <p style="margin:0 0 4px;font-size:14px;color:#475569;">✅ &nbsp;Unlimited members and meals</p>
            <p style="margin:0 0 4px;font-size:14px;color:#475569;">✅ &nbsp;Full reports, exports and analytics</p>
            <p style="margin:0 0 4px;font-size:14px;color:#475569;">✅ &nbsp;Subsidy, claims and multi-vendor modules</p>
            <p style="margin:0;font-size:14px;color:#475569;">✅ &nbsp;Priority support and updates</p>
        </td>
    </tr>
</table>

{{-- Bulletproof CTA button --}}
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
    <tr>
        <td align="center" style="border-radius:10px;background-color:{{ $accent ?? '#4f46e5' }};">
            <a href="{{ $planUrl }}"
               target="_blank"
               style="display:inline-block;padding:13px 28px;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px;">
                Upgrade to a permanent subscription
            </a>
        </td>
    </tr>
</table>

<p style="margin:0;font-size:12px;line-height:1.6;color:#94a3b8;">
    Questions about plans or pricing? Simply reply to this email and our team will help you
    choose the right fit for {{ $institutionName }}.
</p>

@endcomponent
