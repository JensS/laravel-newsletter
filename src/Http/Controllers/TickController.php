<?php

namespace JensS\Newsletter\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use JensS\Newsletter\Sending\Worker;

/**
 * HTTP trigger for hosts without frequent cron. An external pinger calls this
 * every minute; the response is returned immediately and the work runs after
 * the response has been flushed (PHP-FPM / LiteSpeed fastcgi_finish_request).
 */
class TickController extends Controller
{
    public function __invoke(Request $request, Worker $worker): JsonResponse
    {
        $expected = (string) config('newsletter.worker.tick_token');
        $given = (string) ($request->bearerToken() ?? $request->header('X-Newsletter-Token') ?? '');

        if ($expected === '' || $given === '' || ! hash_equals($expected, $given)) {
            return new JsonResponse(['status' => 'unauthorized'], 401);
        }

        if ($request->boolean('sync')) {
            return new JsonResponse(['status' => 'done', 'result' => $worker->tick()]);
        }

        app()->terminating(function () use ($worker) {
            $worker->tick();
        });

        return new JsonResponse(['status' => 'accepted'], 202);
    }
}
