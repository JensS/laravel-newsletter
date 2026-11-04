@extends('newsletter::pages.layout', ['title' => __('newsletter::messages.unsubscribe.page_title')])

@section('content')
    <h1>{{ __('newsletter::messages.unsubscribe.page_title') }}</h1>
    <p>{{ __('newsletter::messages.unsubscribe.page_intro', ['audience' => $audienceLabel, 'email' => $email]) }}</p>
    <form method="post" action="{{ $action }}">
        @csrf
        <button type="submit">{{ __('newsletter::messages.unsubscribe.button') }}</button>
    </form>
    <p style="margin-top:1rem;"><a href="{{ $preferencesUrl }}">{{ __('newsletter::messages.footer.preferences') }}</a></p>
@endsection
