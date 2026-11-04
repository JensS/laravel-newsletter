@extends('newsletter::pages.layout', ['title' => __('newsletter::messages.confirm.page_title')])

@section('content')
    <h1>{{ __('newsletter::messages.confirm.page_title') }}</h1>
    <p>{{ __('newsletter::messages.confirm.page_intro', ['audience' => $audienceLabel, 'email' => $email]) }}</p>
    <form method="post" action="{{ $action }}">
        @csrf
        <button type="submit">{{ __('newsletter::messages.confirm.button') }}</button>
    </form>
@endsection
