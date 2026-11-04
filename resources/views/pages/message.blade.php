@extends('newsletter::pages.layout', ['title' => $title])

@section('content')
    <h1>{{ $title }}</h1>
    <p>{{ $message }}</p>
    @isset($preferencesUrl)
        <p><a href="{{ $preferencesUrl }}">{{ __('newsletter::messages.footer.preferences') }}</a></p>
    @endisset
@endsection
