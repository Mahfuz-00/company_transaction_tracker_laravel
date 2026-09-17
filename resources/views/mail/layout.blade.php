@php
    /**
     * Branded, responsive HTML email shell.
     *
     * Written with table-based layout and inline styles so it renders correctly
     * in Gmail, Outlook, Apple Mail and the Mailtrap preview. Kept deliberately
     * dependency-free (no Vite/Tailwind build step) so what is shown in the
     * outbox preview is byte-for-byte what the recipient receives.
     */
    $accent = $accent ?? '#4f46e5';
    $appName = config('app.name', 'Meal & Expense Manager');
    $brandName = $institutionName ?? $appName;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<title>{{ $subject ?? $appName }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;-webkit-font-smoothing:antialiased;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f1f5f9;padding:32px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background-color:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 1px 3px rgba(15,23,42,0.08);">

                    {{-- Header --}}
                    <tr>
                        <td style="background-color:{{ $accent }};padding:28px 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="font-size:16px;font-weight:700;color:#ffffff;letter-spacing:-0.01em;">
                                        {{ $brandName }}
                                    </td>
                                </tr>
                                @isset($headerSubtitle)
                                <tr>
                                    <td style="font-size:12px;color:rgba(255,255,255,0.85);padding-top:3px;">
                                        {{ $headerSubtitle }}
                                    </td>
                                </tr>
                                @endisset
                            </table>
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td style="padding:32px;">
                            {!! $slot ?? '' !!}
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding:20px 32px;background-color:#f8fafc;border-top:1px solid #e2e8f0;">
                            <p style="margin:0;font-size:11px;line-height:1.6;color:#94a3b8;">
                                This message was sent by {{ $appName }} on behalf of {{ $brandName }}.
                                If you did not expect it, you can safely ignore this email.
                            </p>
                        </td>
                    </tr>

                </table>

                <p style="max-width:560px;margin:16px auto 0;font-size:11px;color:#94a3b8;text-align:center;">
                    &copy; {{ date('Y') }} {{ $appName }}
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
