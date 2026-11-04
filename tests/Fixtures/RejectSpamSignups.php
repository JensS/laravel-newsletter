<?php

namespace JensS\Newsletter\Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Stand-in for a host app's captcha/spam middleware. */
class RejectSpamSignups
{
    public static int $calls = 0;

    public function handle(Request $request, Closure $next, string $field = 'captcha'): mixed
    {
        static::$calls++;

        if ($request->input($field) !== 'human') {
            throw ValidationException::withMessages([$field => 'Please prove you are human.']);
        }

        return $next($request);
    }
}
