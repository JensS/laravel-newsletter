{{--
    Default newsletter layout. Publish with:
        php artisan vendor:publish --tag=newsletter-views
    Available: $subject, $previewText, $content (HtmlString), $branding, $unsubscribeUrl,
    $preferencesUrl, $recipient, $data. The unsubscribe link must stay in the output.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>{{ $subject }}</title>
    <style>
        body { margin: 0; padding: 0; background: #f4f4f5; -webkit-text-size-adjust: 100%; }
        .nl-body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 16px; line-height: 1.6; color: #18181b; }
        .nl-body a { color: {{ $branding['accent_color'] ?? '#111827' }}; }
        .nl-body img { max-width: 100%; height: auto; }
        .nl-body h1, .nl-body h2, .nl-body h3 { line-height: 1.3; margin: 1.2em 0 .5em; }
        .nl-footer { font-size: 13px; line-height: 1.5; color: #71717a; }
        .nl-footer a { color: #52525b; }
        @media (prefers-color-scheme: dark) {
            body, .nl-outer { background: #18181b !important; }
            .nl-card { background: #27272a !important; }
            .nl-body { color: #f4f4f5 !important; }
            .nl-footer, .nl-footer a { color: #a1a1aa !important; }
        }
        @media (max-width: 620px) { .nl-card-pad { padding: 24px 20px !important; } }
    </style>
</head>
<body>
@if($previewText)
    <div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#f4f4f5;opacity:0;">
        {{ $previewText }}&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;
    </div>
@endif
<table role="presentation" class="nl-outer" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f4f5;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;">
                <tr>
                    <td align="center" style="padding:8px 0 20px;">
                        @if(! empty($branding['logo_url']))
                            <a href="{{ $branding['url'] ?? '#' }}"><img src="{{ $branding['logo_url'] }}" alt="{{ $branding['name'] ?? '' }}" style="max-height:48px;width:auto;border:0;"></a>
                        @elseif(! empty($branding['name']))
                            <a href="{{ $branding['url'] ?? '#' }}" style="font-family:Helvetica,Arial,sans-serif;font-size:20px;font-weight:bold;color:#18181b;text-decoration:none;">{{ $branding['name'] }}</a>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td class="nl-card nl-card-pad nl-body" style="background:#ffffff;border-radius:8px;padding:32px 40px;">
                        {{ $content }}
                    </td>
                </tr>
                <tr>
                    <td class="nl-footer" align="center" style="padding:24px 16px;font-family:Helvetica,Arial,sans-serif;">
                        <p style="margin:0 0 8px;">
                            {{ __('newsletter::messages.footer.reason', ['brand' => $branding['name'] ?? config('app.name')]) }}
                        </p>
                        <p style="margin:0 0 8px;">
                            <a href="{{ $unsubscribeUrl }}">{{ __('newsletter::messages.footer.unsubscribe') }}</a>
                            @if($preferencesUrl)
                                &nbsp;·&nbsp; <a href="{{ $preferencesUrl }}">{{ __('newsletter::messages.footer.preferences') }}</a>
                            @endif
                        </p>
                        @if(! empty($branding['postal_address']))
                            <p style="margin:0;">{{ $branding['postal_address'] }}</p>
                        @endif
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
