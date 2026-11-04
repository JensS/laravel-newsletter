{!! $content !!}

--
{!! __('newsletter::messages.footer.reason', ['brand' => $branding['name'] ?? config('app.name')]) !!}
{!! __('newsletter::messages.footer.unsubscribe') !!}: {!! $unsubscribeUrl !!}
@if($preferencesUrl)
{!! __('newsletter::messages.footer.preferences') !!}: {!! $preferencesUrl !!}
@endif
@if(! empty($branding['postal_address']))
{!! $branding['postal_address'] !!}
@endif
