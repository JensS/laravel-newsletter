@extends('newsletter::pages.layout', ['title' => __('newsletter::messages.preferences.page_title')])

@section('content')
    <h1>{{ __('newsletter::messages.preferences.page_title') }}</h1>
    <p>{{ __('newsletter::messages.preferences.intro', ['email' => $email]) }}</p>

    @if(session('newsletter_status'))
        <p class="status" role="status">{{ session('newsletter_status') }}</p>
    @endif

    <form method="post" action="{{ $action }}">
        @csrf
        @forelse($audiences as $key => $item)
            <label>
                <input type="checkbox" name="audiences[]" value="{{ $key }}" @checked($item['subscribed'])>
                <span>
                    {{ $item['label'] }}
                    @if($item['description'])<small>{{ $item['description'] }}</small>@endif
                </span>
            </label>
        @empty
            <p>{{ __('newsletter::messages.preferences.none') }}</p>
        @endforelse

        @if(count($audiences))
            <p style="margin-top:1rem;display:flex;gap:.5rem;flex-wrap:wrap;">
                <button type="submit">{{ __('newsletter::messages.preferences.save') }}</button>
                <button type="submit" name="unsubscribe_all" value="1" class="secondary">{{ __('newsletter::messages.preferences.unsubscribe_all') }}</button>
            </p>
        @endif
    </form>
@endsection
