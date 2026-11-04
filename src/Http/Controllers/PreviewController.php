<?php

namespace JensS\Newsletter\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use JensS\Newsletter\Models\Campaign;
use JensS\Newsletter\Sending\CampaignDispatcher;
use Symfony\Component\HttpFoundation\Response;

class PreviewController extends Controller
{
    public function __invoke(Campaign $campaign, CampaignDispatcher $dispatcher): Response
    {
        $policy = Gate::getPolicyFor($campaign);

        abort_unless($policy ? Gate::allows('view', $campaign) : Gate::allows(config('newsletter.authorization.ability')), 403);

        return response($dispatcher->preview($campaign))
            ->header('Content-Type', 'text/html; charset=utf-8')
            ->header('X-Robots-Tag', 'noindex')
            ->header('Content-Security-Policy', "script-src 'none'; frame-ancestors 'self'");
    }
}
