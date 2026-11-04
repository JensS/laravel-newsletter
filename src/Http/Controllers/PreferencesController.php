<?php

namespace JensS\Newsletter\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use JensS\Newsletter\Subscriptions\SubscriptionManager;
use JensS\Newsletter\Support\LinkToken;
use Symfony\Component\HttpFoundation\Response;

class PreferencesController extends Controller
{
    public function show(string $token, SubscriptionManager $subscriptions): Response
    {
        $payload = LinkToken::parse($token, LinkToken::PREFERENCES);

        if ($payload === null) {
            return $this->invalid();
        }

        return response()->view('newsletter::pages.preferences', [
            'email' => $payload['e'],
            'audiences' => $subscriptions->preferences((string) $payload['e']),
            'action' => route('newsletter.preferences.update', ['token' => $token]),
        ]);
    }

    public function update(Request $request, string $token, SubscriptionManager $subscriptions): Response
    {
        $payload = LinkToken::parse($token, LinkToken::PREFERENCES);

        if ($payload === null) {
            return $this->invalid();
        }

        $selected = $request->boolean('unsubscribe_all') ? [] : array_values(array_filter((array) $request->input('audiences', []), 'is_string'));

        $subscriptions->updatePreferences((string) $payload['e'], $selected, $request->ip());

        return redirect()
            ->route('newsletter.preferences', ['token' => $token])
            ->with('newsletter_status', __('newsletter::messages.preferences.saved'));
    }

    private function invalid(): Response
    {
        return response()->view('newsletter::pages.message', [
            'title' => __('newsletter::messages.invalid_link.title'),
            'message' => __('newsletter::messages.invalid_link.message'),
        ], 404);
    }
}
