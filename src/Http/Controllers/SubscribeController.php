<?php

namespace JensS\Newsletter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use JensS\Newsletter\Subscriptions\SubscriptionManager;
use JensS\Newsletter\Support\AudienceRegistry;
use Symfony\Component\HttpFoundation\Response;

class SubscribeController extends Controller
{
    public function __invoke(Request $request, SubscriptionManager $subscriptions, AudienceRegistry $audiences): Response
    {
        $public = array_keys(array_filter($audiences->all(), fn ($a) => $a->allowsPublicSignup()));

        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'name' => ['nullable', 'string', 'max:120'],
            'audience' => ['nullable', 'string', Rule::in($public)],
            'source' => ['nullable', 'string', 'max:60'],
        ]);

        $audience = $data['audience'] ?? config('newsletter.default_audience');
        $honeypot = (string) config('newsletter.subscriptions.honeypot_field', 'website');

        // Bots filling the honeypot get the same response as everybody else.
        if ($request->filled($honeypot) || ! in_array($audience, $public, true)) {
            return $this->respond($request, $audience, $audiences);
        }

        $subscriptions->subscribe($audience, $data['email'], [
            'name' => $data['name'] ?? null,
            'source' => 'form:'.($data['source'] ?? 'default'),
            'ip' => $request->ip(),
            'locale' => app()->getLocale(),
            'consent_text' => __('newsletter::messages.form.consent', ['brand' => config('newsletter.branding.name')]),
        ]);

        return $this->respond($request, $audience, $audiences);
    }

    /** Identical response whether or not the address was already subscribed (no enumeration). */
    private function respond(Request $request, string $audience, AudienceRegistry $audiences): Response
    {
        $doubleOptIn = $audiences->has($audience) && $audiences->get($audience)->requiresDoubleOptIn();
        $message = $doubleOptIn ? __('newsletter::messages.signup.check_inbox') : __('newsletter::messages.signup.done');

        if ($request->expectsJson()) {
            return new JsonResponse(['status' => 'ok', 'message' => $message]);
        }

        if ($redirect = config('newsletter.subscriptions.redirect_after_signup')) {
            return new RedirectResponse($redirect);
        }

        return response()->view('newsletter::pages.message', [
            'title' => $doubleOptIn ? __('newsletter::messages.signup.title') : __('newsletter::messages.signup.done_title'),
            'message' => $message,
        ]);
    }
}
