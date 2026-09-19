@php
    // Terminology-aware labels, so a company reads "Contributions" instead of
    // "Deposits" and a dorm reads "Students". Falls back gracefully when the
    // institution has no custom terms.
    $terms = $terms ?? [];
    $memberWord = $terms['member'] ?? 'member';
    $depositWord = strtolower($terms['deposit'] ?? 'deposit');
    $mealWord = strtolower($terms['meal'] ?? 'meal');
@endphp
@component('mail.layout', [
    'subject' => $subject ?? 'Welcome aboard',
    'institutionName' => $institutionName ?? null,
    'accent' => $accent ?? '#4f46e5',
    'headerSubtitle' => 'Account activated',
])

<h1 style="margin:0 0 12px;font-size:20px;font-weight:700;color:#0f172a;letter-spacing:-0.01em;">
    Welcome aboard, {{ $name }}! 🎉
</h1>

<p style="margin:0 0 16px;font-size:14px;line-height:1.65;color:#475569;">
    @if($institutionName)
        Your account for <strong style="color:#0f172a;">{{ $institutionName }}</strong> is now active.
    @else
        Your account is now active.
    @endif
    You can sign in any time with your email
    (<strong style="color:#0f172a;">{{ $email }}</strong>) and the password you just set.
</p>

<p style="margin:0 0 12px;font-size:14px;line-height:1.65;color:#475569;">
    Here is what you can do from your dashboard:
</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
    <tr><td style="padding:4px 0;font-size:14px;color:#475569;">🍽️ &nbsp;Track your {{ $mealWord }} entries day by day</td></tr>
    <tr><td style="padding:4px 0;font-size:14px;color:#475569;">💰 &nbsp;View your {{ $depositWord }} history and current balance</td></tr>
    <tr><td style="padding:4px 0;font-size:14px;color:#475569;">📝 &nbsp;Submit claims and disputes for your manager to review</td></tr>
    <tr><td style="padding:4px 0;font-size:14px;color:#475569;">📊 &nbsp;See your personal analytics and month summaries</td></tr>
</table>

{{-- Bulletproof CTA button --}}
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 24px;">
    <tr>
        <td align="center" style="border-radius:10px;background-color:{{ $accent ?? '#4f46e5' }};">
            <a href="{{ $loginUrl }}"
               target="_blank"
               style="display:inline-block;padding:13px 28px;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px;">
                Go to my dashboard
            </a>
        </td>
    </tr>
</table>

<p style="margin:0;font-size:12px;line-height:1.6;color:#94a3b8;">
    If you did not create this account, please contact your institution administrator
    immediately.
</p>

@endcomponent
