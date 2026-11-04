<?php

namespace JensS\Newsletter\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use JensS\Newsletter\Subscriptions\SubscriptionManager;
use JensS\Newsletter\Support\AudienceRegistry;
use JensS\Newsletter\Support\LinkToken;
use Symfony\Component\HttpFoundation\Response;

class UnsubscribeController extends Controller
{
    public function show(string $token, AudienceRegistry $audiences): Response
    {
        $payload = LinkToken::parse($token, LinkToken::UNSUBSCRIBE);

        if ($payload === null || ! $audiences->has((string) $payload['a'])) {
            return $this->invalid();
        }

        return response()->view('newsletter::pages.unsubscribe', [
            'email' => $payload['e'],
            'audienceLabel' => $audiences->get((string) $payload['a'])->label(),
            'action' => route('newsletter.unsubscribe.store', ['token' => $token]),
            'preferencesUrl' => route('newsletter.preferences', ['token' => LinkToken::preferences((string) $payload['e'])]),
        ]);
    }

    public function store(string $token, SubscriptionManager $subscriptions, AudienceRegistry $audiences): Response
    {
        $payload = LinkToken::parse($token, LinkToken::UNSUBSCRIBE);

        if ($payload === null || ! $audiences->has((string) $payload['a'])) {
            return $this->invalid();
        }

        $subscriptions->unsubscribe((string) $payload['a'], (string) $payload['e'], 'link');

        return response()->view('newsletter::pages.message', [
            'title' => __('newsletter::messages.unsubscribe.done_title'),
            'message' => __('newsletter::messages.unsubscribe.done', ['audience' => $audiences->get((string) $payload['a'])->label()]),
            'preferencesUrl' => route('newsletter.preferences', ['token' => LinkToken::preferences((string) $payload['e'])]),
        ]);
    }

    /**
     * RFC 8058 one-click unsubscribe, POSTed by mailbox providers with the body
     * "List-Unsubscribe=One-Click". No CSRF token, no login, no confirmation.
     */
    public function oneClick(Request $request, string $token, SubscriptionManager $subscriptions, AudienceRegistry $audiences): Response
    {
        $payload = LinkToken::parse($token, LinkToken::UNSUBSCRIBE);

        if ($payload === null || ! $audiences->has((string) $payload['a'])) {
            return response('Invalid token', 404);
        }

        $subscriptions->unsubscribe((string) $payload['a'], (string) $payload['e'], 'one-click');

        return response('Unsubscribed', 200);
    }

    /** Some clients open the List-Unsubscribe URL with GET: show the normal page. */
    public function oneClickBrowser(string $token): Response
    {
        return redirect()->route('newsletter.unsubscribe', ['token' => $token]);
    }

    private function invalid(): Response
    {
        return response()->view('newsletter::pages.message', [
            'title' => __('newsletter::messages.invalid_link.title'),
            'message' => __('newsletter::messages.invalid_link.message'),
        ], 404);
    }
}
