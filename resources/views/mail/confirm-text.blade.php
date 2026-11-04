{!! $branding['name'] ?? config('app.name') !!}

{!! __('newsletter::messages.confirm.intro', ['audience' => $audienceLabel]) !!}

{!! __('newsletter::messages.confirm.button') !!}: {!! $confirmUrl !!}

{!! __('newsletter::messages.confirm.ignore') !!}
