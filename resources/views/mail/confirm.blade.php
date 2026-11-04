<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('newsletter::messages.confirm.subject', ['audience' => $audienceLabel]) }}</title></head>
<body style="margin:0;padding:24px 12px;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#18181b;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;background:#ffffff;border-radius:8px;">
        <tr><td style="padding:32px 36px;font-size:16px;line-height:1.6;">
            <p style="margin:0 0 16px;font-size:20px;font-weight:bold;">{{ $branding['name'] ?? config('app.name') }}</p>
            <p>{{ __('newsletter::messages.confirm.intro', ['audience' => $audienceLabel]) }}</p>
            <p style="margin:28px 0;">
                <a href="{{ $confirmUrl }}" style="display:inline-block;background:{{ $branding['accent_color'] ?? '#111827' }};color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:6px;font-weight:bold;">{{ __('newsletter::messages.confirm.button') }}</a>
            </p>
            <p style="font-size:14px;color:#52525b;">{{ __('newsletter::messages.confirm.ignore') }}</p>
            <p style="font-size:12px;color:#71717a;word-break:break-all;">{{ $confirmUrl }}</p>
        </td></tr>
    </table>
    @if(! empty($branding['postal_address']))
        <p style="font-size:12px;color:#71717a;">{{ $branding['postal_address'] }}</p>
    @endif
</td></tr></table>
</body>
</html>
