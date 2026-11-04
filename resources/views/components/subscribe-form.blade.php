@props(['audience' => config('newsletter.default_audience'), 'source' => 'form', 'withName' => false])
{{-- Usage: <x-newsletter::subscribe-form audience="newsletter" source="footer" /> --}}
<form method="post" action="{{ route('newsletter.subscribe') }}" {{ $attributes->merge(['class' => 'newsletter-subscribe-form']) }}>
    @csrf
    <input type="hidden" name="audience" value="{{ $audience }}">
    <input type="hidden" name="source" value="{{ $source }}">
    <div style="position:absolute;left:-10000px;" aria-hidden="true">
        <input type="text" name="{{ config('newsletter.subscriptions.honeypot_field', 'website') }}" tabindex="-1" autocomplete="off">
    </div>
    @if($withName)
        <label>
            <span>{{ __('newsletter::messages.form.name') }}</span>
            <input type="text" name="name" autocomplete="name">
        </label>
    @endif
    <label>
        <span>{{ __('newsletter::messages.form.email') }}</span>
        <input type="email" name="email" required autocomplete="email">
    </label>
    <small>{{ __('newsletter::messages.form.consent', ['brand' => config('newsletter.branding.name')]) }}</small>
    <button type="submit">{{ __('newsletter::messages.form.submit') }}</button>
    {{ $slot }}
</form>
