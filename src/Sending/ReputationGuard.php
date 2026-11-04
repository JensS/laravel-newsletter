<?php

namespace JensS\Newsletter\Sending;

use JensS\Newsletter\Enums\CampaignStatus;
use JensS\Newsletter\Enums\DeliveryStatus;
use JensS\Newsletter\Models\Campaign;

/**
 * Pauses a campaign whose bounce or complaint rate shows a list-quality
 * problem, before the sending domain or the hosting account gets blocked.
 */
final class ReputationGuard
{
    public function __construct(private readonly CampaignDispatcher $dispatcher) {}

    public function evaluate(?Campaign $campaign): ?string
    {
        if ($campaign === null || ! config('newsletter.guard.enabled', true)) {
            return null;
        }

        $campaign->refresh();

        if ($campaign->status !== CampaignStatus::Sending) {
            return null;
        }

        $p = $campaign->progress();
        $failed = $p->count(DeliveryStatus::Failed);
        $sample = $p->handedOff() + $failed;

        if ($sample < (int) config('newsletter.guard.min_sample', 50)) {
            return null;
        }

        $bounceRate = ($p->count(DeliveryStatus::Bounced) + $failed) / $sample;
        $complaintRate = $p->count(DeliveryStatus::Complained) / $sample;
        $reason = null;

        if ($complaintRate > (float) config('newsletter.guard.max_complaint_rate', 0.003)) {
            $reason = sprintf('Complaint rate %.2f%% exceeds %.2f%%', $complaintRate * 100, config('newsletter.guard.max_complaint_rate') * 100);
        } elseif ($bounceRate > (float) config('newsletter.guard.max_bounce_rate', 0.05)) {
            $reason = sprintf('Bounce/failure rate %.1f%% exceeds %.1f%%', $bounceRate * 100, config('newsletter.guard.max_bounce_rate') * 100);
        }

        if ($reason !== null) {
            $this->dispatcher->pause($campaign, $reason);
        }

        return $reason;
    }
}
