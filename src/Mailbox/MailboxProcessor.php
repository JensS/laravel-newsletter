<?php

namespace JensS\Newsletter\Mailbox;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use JensS\Newsletter\Subscriptions\SubscriptionManager;
use JensS\Newsletter\Support\AudienceRegistry;
use JensS\Newsletter\Support\LinkToken;
use JensS\Newsletter\Webhooks\DeliveryEventRecorder;

/**
 * Reads the bounce mailbox over IMAP and applies what it finds:
 * hard bounces and complaints suppress the address, soft bounces are counted,
 * mailto unsubscribes are honoured. Handled mails are marked read (or moved);
 * anything unrecognised is left untouched for a human.
 */
final class MailboxProcessor
{
    private const STATE_KEY = 'newsletter:mailbox:state';

    private const INTERVAL_KEY = 'newsletter:mailbox:last_run';

    /** @var (Closure(): ImapClient)|null */
    private ?Closure $connector = null;

    public function __construct(
        private readonly MessageClassifier $classifier,
        private readonly DeliveryEventRecorder $recorder,
        private readonly SubscriptionManager $subscriptions,
        private readonly AudienceRegistry $audiences,
        private readonly Cache $cache,
    ) {}

    public static function configured(): bool
    {
        return (bool) config('newsletter.mailbox.enabled') && filled(config('newsletter.mailbox.host')) && filled(config('newsletter.mailbox.username'));
    }

    /** Use a custom connection (tests, or a pre-configured client). */
    public function using(Closure $connector): self
    {
        $this->connector = $connector;

        return $this;
    }

    /** Run if configured and the interval has passed (used by maintenance). */
    public function runIfDue(): ?array
    {
        if (! self::configured()) {
            return null;
        }

        $minutes = max(1, (int) config('newsletter.mailbox.interval_minutes', 10));

        if (! $this->cache->add(self::INTERVAL_KEY, now()->getTimestamp(), $minutes * 60)) {
            return null;
        }

        try {
            return $this->run();
        } catch (ImapException $e) {
            report($e);

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * @return array{checked: int, bounces: int, soft_bounces: int, complaints: int, unsubscribes: int, ignored: int, unknown: int}
     */
    public function run(bool $dryRun = false, ?Closure $log = null): array
    {
        $stats = ['checked' => 0, 'bounces' => 0, 'soft_bounces' => 0, 'complaints' => 0, 'unsubscribes' => 0, 'ignored' => 0, 'unknown' => 0];
        $client = $this->client();
        $folder = (string) config('newsletter.mailbox.folder', 'INBOX');
        $target = config('newsletter.mailbox.processed_folder');

        try {
            $info = $client->select($folder);
            $state = (array) $this->cache->get(self::STATE_KEY, []);
            $sameMailbox = ($state['validity'] ?? null) === $info['uidvalidity'] && ($state['folder'] ?? null) === $folder;
            $lastUid = $sameMailbox ? (int) ($state['last_uid'] ?? 0) : 0;

            $uids = $lastUid > 0
                ? array_values(array_filter($client->search('UID '.($lastUid + 1).':*'), fn ($uid) => $uid > $lastUid))
                : $client->search('UNSEEN');

            $uids = array_slice($uids, 0, max(1, (int) config('newsletter.mailbox.max_messages', 200)));

            if ($target && ! $dryRun) {
                $client->ensureFolder((string) $target);
            }

            foreach ($uids as $uid) {
                $raw = $client->fetch($uid);
                $stats['checked']++;

                if ($raw === null) {
                    continue;
                }

                $message = MimePart::parse($raw);
                $event = $this->classifier->classify($message);
                $key = $this->statKey($event);
                $stats[$key]++;

                if ($log) {
                    $log(sprintf('#%d %s %s %s', $uid, $key, $event->email ?? '-', $event->details ?? ''));
                }

                if ($dryRun) {
                    continue;
                }

                if ($event->handled()) {
                    $this->apply($event, $message, $raw);
                    $target ? $client->move($uid, (string) $target) : $client->markSeen($uid);
                }

                $lastUid = max($lastUid, $uid);
            }

            if (! $dryRun) {
                $this->cache->forever(self::STATE_KEY, ['validity' => $info['uidvalidity'], 'folder' => $folder, 'last_uid' => $lastUid]);
            }
        } finally {
            $client->logout();
        }

        return $stats;
    }

    /** Check the connection and credentials without processing anything. */
    public function test(): array
    {
        $client = $this->client();

        try {
            return $client->select((string) config('newsletter.mailbox.folder', 'INBOX'));
        } finally {
            $client->logout();
        }
    }

    private function apply(MailboxEvent $event, MimePart $message, string $raw): void
    {
        $eventId = trim((string) $message->header('message-id'), '<> ') ?: sha1($raw);

        if (! $this->recorder->claimEvent('mailbox', $eventId, $event->type, [
            'email' => $event->email,
            'status' => $event->status,
            'details' => $event->details,
            'delivery' => $event->deliveryUuid,
        ])) {
            return; // processed before (e.g. the mailbox state was reset)
        }

        if ($event->type === MailboxEvent::UNSUBSCRIBE) {
            $payload = $event->token ? LinkToken::parse($event->token, LinkToken::UNSUBSCRIBE) : null;

            if ($payload && $this->audiences->has((string) $payload['a'])) {
                $this->subscriptions->unsubscribe((string) $payload['a'], (string) $payload['e'], 'mailto');
            }
        } elseif (in_array($event->type, [MailboxEvent::BOUNCE, MailboxEvent::COMPLAINT], true)) {
            $delivery = $this->recorder->findDelivery(deliveryUuid: $event->deliveryUuid, messageId: $event->originalMessageId);

            $this->recorder->record(
                $delivery,
                $event->email ?? $delivery?->email,
                $event->type === MailboxEvent::BOUNCE ? DeliveryEventRecorder::BOUNCED : DeliveryEventRecorder::COMPLAINED,
                permanent: $event->permanent,
                details: $event->details,
                source: 'mailbox',
            );
        }

        $this->recorder->markProcessed('mailbox', $eventId);
    }

    private function statKey(MailboxEvent $event): string
    {
        return match ($event->type) {
            MailboxEvent::BOUNCE => $event->permanent ? 'bounces' : 'soft_bounces',
            MailboxEvent::COMPLAINT => 'complaints',
            MailboxEvent::UNSUBSCRIBE => 'unsubscribes',
            MailboxEvent::IGNORE => 'ignored',
            default => 'unknown',
        };
    }

    private function client(): ImapClient
    {
        if ($this->connector) {
            return ($this->connector)();
        }

        $c = (array) config('newsletter.mailbox');
        $client = ImapClient::connect((string) $c['host'], (int) ($c['port'] ?? 993), (string) ($c['encryption'] ?? 'ssl'), (int) ($c['timeout'] ?? 20));
        $client->login((string) $c['username'], (string) $c['password']);

        return $client;
    }
}
