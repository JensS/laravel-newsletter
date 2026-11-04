<?php

namespace JensS\Newsletter\Http\Controllers;

use Illuminate\Routing\Controller;
use JensS\Newsletter\Subscriptions\SubscriptionManager;
use JensS\Newsletter\Support\AudienceRegistry;
use JensS\Newsletter\Support\LinkToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET shows a confirmation button; only POST confirms. Mail security scanners
 * that prefetch links therefore cannot confirm subscriptions on their own.
 */
class ConfirmController extends Controller
{
    public function show(string $token, SubscriptionManager $subscriptions, AudienceRegistry $audiences): Response
    {
        $subscription = $subscriptions->findByConfirmationToken($token);

        if ($subscription === null) {
            return $this->expired();
        }

        return response()->view('newsletter::pages.confirm', [
            'email' => $subscription->email,
            'audienceLabel' => $audiences->has($subscription->audience) ? $audiences->get($subscription->audience)->label() : $subscription->audience,
            'action' => route('newsletter.confirm.store', ['token' => $token]),
        ]);
    }

    public function store(string $token, SubscriptionManager $subscriptions, AudienceRegistry $audiences): Response
    {
        $subscription = $subscriptions->confirm($token);

        if ($subscription === null) {
            return $this->expired();
        }

        $label = $audiences->has($subscription->audience) ? $audiences->get($subscription->audience)->label() : $subscription->audience;

        return response()->view('newsletter::pages.message', [
            'title' => __('newsletter::messages.confirm.done_title'),
            'message' => __('newsletter::messages.confirm.done', ['audience' => $label]),
            'preferencesUrl' => route('newsletter.preferences', ['token' => LinkToken::preferences($subscription->email)]),
        ]);
    }

    private function expired(): Response
    {
        return response()->view('newsletter::pages.message', [
            'title' => __('newsletter::messages.confirm.expired_title'),
            'message' => __('newsletter::messages.confirm.expired'),
        ], 404);
    }
}
