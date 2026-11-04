# Laravel Newsletter

Self-hosted newsletters for Laravel 12 and 13: explicit audiences, double opt-in, login-free unsubscribe, frozen campaigns, chunked queued sending with one delivery record per recipient, provider webhooks, and an optional Filament 3 admin.

It is built for **shared hosting**: it works with the `database` queue, needs no Redis and no permanently running worker, and keeps making progress when cron only runs once an hour.

- Works with **your existing members**: map any Eloquent model or query through small interfaces. No `App\User` assumptions, no copying your member table.
- **Membership never implies a subscription.** Every campaign targets exactly one audience, and member audiences require an explicit opt-in.
- **Honest delivery reporting:** `accepted` (by the mail provider) is never reported as `delivered`.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Concepts](#concepts)
- [Audiences and recipients](#audiences-and-recipients)
- [Subscriptions](#subscriptions)
- [Campaigns](#campaigns)
- [Sending on shared hosting](#sending-on-shared-hosting)
- [Mail providers and webhooks](#mail-providers-and-webhooks)
- [Sending through your web host's SMTP](#sending-through-your-web-hosts-smtp)
- [Account lifecycle (GDPR)](#account-lifecycle-gdpr)
- [Filament admin](#filament-admin)
- [Customisation](#customisation)
- [Events](#events)
- [Going to production](#going-to-production)
- [Testing](#testing)
- [Integration guide](docs/integration-guide.md) · [netcup / shared-host SMTP guide](docs/netcup.md)

## Requirements

- PHP 8.2+ (CI runs 8.2, 8.3, 8.4 and 8.5)
- Laravel 12 or 13
- A queue connection other than `sync` (the `database` driver is fine)
- Optional: Filament 3.3+ for the admin panel; `resend/resend-php` for the idempotent Resend driver

## Installation

```bash
composer require jenss/laravel-newsletter
php artisan newsletter:install
```

The package is auto-discovered. `newsletter:install` publishes `config/newsletter.php` and the migration, optionally runs `migrate`, and prints the remaining checklist. Use `--views` to also publish the Blade templates.

Grant admin access (until you do, nobody can use the admin features):

```php
// app/Providers/AppServiceProvider.php
Gate::define('manage-newsletters', fn ($user) => $user->is_admin);
```

Or register a policy for `JensS\Newsletter\Models\Campaign`; it is used automatically when present.

Publishable tags: `newsletter-config`, `newsletter-migrations`, `newsletter-views`, `newsletter-translations` (English and German included).

## Concepts

| Thing | What it is |
| --- | --- |
| **Audience** | A named list of recipients. Either package-managed *subscriptions* or an *application provider* that returns recipients from your own tables. |
| **Subscription** | One address in one audience, with status `pending`, `subscribed` or `unsubscribed`, plus consent source, IP, text and timestamps. |
| **Campaign** | Subject, preview text, HTML and optional plain text, optional content blocks. `draft` → `scheduled` → `sending` → `sent` (or `cancelled`). |
| **Snapshot** | When sending starts, everything that could change (content, blocks, layout, sender, branding) is frozen into the campaign. Every recipient gets the same content. |
| **Delivery** | One record per campaign and recipient: `queued`, `sending`, `accepted`, `delivered`, `failed`, `bounced`, `complained`, `suppressed`, `skipped`, `cancelled`. |
| **Suppression** | Global do-not-send list, filled automatically by permanent bounces and complaints. |

## Audiences and recipients

Audiences live in `config/newsletter.php`:

```php
'audiences' => [
    // Public newsletter: package-managed subscriptions with double opt-in.
    'newsletter' => [
        'label' => 'Newsletter',
        'source' => 'subscriptions',
        'double_opt_in' => true,
        'public_signup' => true,
    ],

    // Members opt in explicitly (e.g. on their account page). Each subscription is
    // linked to the member model and re-checked for eligibility before every send.
    'members' => [
        'label' => 'Member newsletter',
        'source' => 'subscriptions',
        'members' => true,
        'double_opt_in' => false, // the member is logged in with a verified address
    ],

    // Your application owns the preference (e.g. a weekly_recap column).
    'weekly-recap' => [
        'label' => 'Weekly recap',
        'source' => App\Newsletter\WeeklyRecapRecipients::class,
    ],
],
```

### Mapping your member model

Either map attributes in config:

```php
'members' => [
    'model' => App\Models\User::class,
    'map' => ['email' => 'email', 'name' => 'display_name', 'locale' => 'locale'],
    'eligibility' => 'is_approved',   // boolean attribute or method; soft-deleted models are always ineligible
],
```

…or implement `JensS\Newsletter\Contracts\NewsletterMember` on the model (`getNewsletterEmail()`, `getNewsletterName()`, `getNewsletterLocale()`, `isNewsletterEligible()`). For full control, point `eligibility` at a class implementing `MemberResolver`.

Add the trait to get helpers and automatic email-change and deletion handling:

```php
use JensS\Newsletter\Concerns\HasNewsletterSubscriptions;

class User extends Authenticatable
{
    use HasNewsletterSubscriptions;
}

$user->subscribeToNewsletter('members', source: 'account-settings');
$user->unsubscribeFromNewsletter('members');
$user->isSubscribedToNewsletter('members');
```

### Application-provided audiences

Extend `EloquentRecipientProvider` and return a query containing **only currently eligible** records. The query is re-run for a single record right before each message is sent.

```php
namespace App\Newsletter;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use JensS\Newsletter\Contracts\HandlesUnsubscribes;
use JensS\Newsletter\Recipients\EloquentRecipientProvider;
use JensS\Newsletter\Support\Recipient;

class WeeklyRecapRecipients extends EloquentRecipientProvider implements HandlesUnsubscribes
{
    protected function query(): Builder
    {
        return User::query()->where('approved', true)->where('weekly_recap', true);
    }

    // Optional: custom mapping and extra personalization values.
    protected function toRecipient(Model $user): ?Recipient
    {
        return new Recipient(
            key: $this->keyFor($user),
            email: $user->email,
            name: $user->display_name,
            locale: $user->locale,
            attributes: ['city' => $user->city],
            subscribable: $user,
        );
    }

    // Keep your own column in sync when someone clicks "unsubscribe" in an email.
    public function unsubscribed(string $email, ?Model $member): void
    {
        User::where('email', $email)->update(['weekly_recap' => false]);
    }
}
```

Unsubscribes from application audiences are also stored by the package and honoured even if your column is never updated. To let someone back in, call `Newsletter::subscriptions()->clearOptOut('weekly-recap', $email)` when they re-enable the preference.

You can also register audiences at runtime with a closure-based provider:

```php
use JensS\Newsletter\Facades\Newsletter;
use JensS\Newsletter\Recipients\QueryRecipientProvider;

Newsletter::registerAudience('speakers', [
    'label' => 'Speakers',
    'source' => QueryRecipientProvider::make(fn () => Speaker::query()->where('active', true)),
]);
```

Or implement `JensS\Newsletter\Contracts\RecipientProvider` (`page()`, `count()`, `find()`) for non-Eloquent sources.

## Subscriptions

### Signup form

```blade
<x-newsletter::subscribe-form audience="newsletter" source="footer" :with-name="true" />
```

The form posts to `POST /newsletter/subscribe` (throttled, honeypot-protected). To add your own spam protection (captcha, Turnstile, a honeypot package), register middleware **for this endpoint only**:

```php
// config/newsletter.php
'routes' => [
    'signup_middleware' => [\Spatie\Honeypot\ProtectAgainstSpam::class],   // or ['turnstile'], [MyCaptcha::class.':field']
],
```

It runs after the throttle and never applies to confirmation, unsubscribe, preference, tick or webhook routes. Render the widget inside the form's slot: `<x-newsletter::subscribe-form>…</x-newsletter::subscribe-form>`. The response is identical whether or not the address already exists, so it cannot be used to enumerate subscribers. JSON requests get a JSON response.

Programmatically:

```php
Newsletter::subscribe('newsletter', 'ann@example.com', [
    'name' => 'Ann',
    'source' => 'checkout',
    'ip' => request()->ip(),
    'consent_text' => 'Yes, send me the newsletter.',
]);
```

### Double opt-in

For audiences with `double_opt_in`, a confirmation email is sent immediately, without waiting for the queue. The link opens a page with a **Confirm** button; only the POST confirms, so link-scanning security gateways cannot subscribe anyone. Links expire after `confirmation_expires_hours`. Consent is recorded when the subscription is confirmed.

### Unsubscribe and preferences without login

Every campaign email contains:

- a signed **unsubscribe link** (page with a button) and a **preferences link** (all audiences for that address, with checkboxes and "unsubscribe from everything");
- `List-Unsubscribe` and `List-Unsubscribe-Post: List-Unsubscribe=One-Click` headers (RFC 2369 / RFC 8058). The one-click endpoint has no session or CSRF middleware, as mailbox providers require.

Links are HMAC-signed with a key derived from `APP_KEY`, contain no database IDs, and do not expire. Rotating `APP_KEY` invalidates them.

### Importing existing subscribers

```bash
php artisan newsletter:import newsletter subscribers.csv --source=legacy
php artisan newsletter:import newsletter optouts.csv --status=unsubscribed
php artisan newsletter:import weekly-recap recap-optouts.csv --status=unsubscribed
```

The CSV needs an `email` column. Optional columns: `status`, `name`, `locale`, `consent_at`, `confirmed_at`, `requested_at`, `unsubscribed_at`, `source`, `ip`. Rows without a status use `--status` (default `subscribed`). Common export values such as `active`, `unconfirmed`, `opted_out` and `1`/`0` are understood.

| Status | Result |
| --- | --- |
| `subscribed` | active, with the original consent timestamps |
| `pending` | unconfirmed; **no confirmation mail is sent** (they never confirmed, so mailing them would be unsolicited). They get one when they sign up again; the record expires under `retention.unconfirmed_subscriptions_days`, counted from `requested_at`. |
| `unsubscribed` | recorded withdrawal with `unsubscribed_at`. It overrides an existing subscription unless that subscription's consent is newer. Also accepted for application-provided audiences, where it acts as an opt-out. |

Existing records are never re-activated by an import, and imports dispatch no events. Member-linked audiences cannot be imported because members opt in themselves. For programmatic imports, use `Newsletter::subscriptions()->import($audience, $rows, $source, $defaultStatus)`, which returns `['imported' => …, 'updated' => …, 'skipped' => …]`.

## Campaigns

```php
$campaign = Newsletter::draft([
    'audience' => 'newsletter',
    'subject' => 'Hello {{ first_name|there }}',
    'preview_text' => 'Three things worth knowing this month',
    'content_html' => '<p>Hi {{ name|friend }}, ...</p>',
    'content_text' => null, // optional; generated from the HTML when empty
    'blocks' => [['type' => 'latest-posts', 'data' => ['limit' => 5]]],
]);

Newsletter::campaigns()->sendTest($campaign, ['me@example.com']); // current draft, sent synchronously
Newsletter::campaigns()->preview($campaign);                       // HTML string
Newsletter::campaigns()->estimateRecipients($campaign);

Newsletter::schedule($campaign, now()->addDay());
Newsletter::send($campaign);    // freeze now and start
Newsletter::cancel($campaign);  // cancel everything still queued

$campaign->progress()->toArray(); // counts per delivery status
```

Or from the CLI: `php artisan newsletter:send {id|uuid}`.

**Personalization** uses `{{ token }}` and `{{ token|fallback }}`. Values are HTML-escaped. Campaign content is never compiled as Blade, so editors cannot run code. Available tokens: `name`, `first_name`, `email`, `unsubscribe_url`, `preferences_url`, `brand_name`, any key in the campaign's `data`, and any `attributes` your provider adds.

**Freezing:** `start()` freezes everything a message is made of: content, blocks, sender, branding and **the rendered layout**. The HTML and text layouts are rendered once per locale, with markers where per-recipient values go. Each delivery only fills in those values, so deploying a new template (or deleting one) never changes a campaign that is already sending. Locales come from the audience's subscriptions, `app.locale`, `app.fallback_locale` and `content.locales`. A regional locale without its own translations (`de_AT`) uses its language (`de`), and an unknown locale uses the default.

The snapshot is stored and the campaign switched to `sending` in a single conditional update, so double clicks and concurrent schedulers cannot start a campaign twice. After that, content fields cannot be changed: the model throws a `CampaignException`. Sending is refused if the layout does not output `$content` and `$unsubscribeUrl`.

### Content blocks

Let your application supply dynamic content. Blocks are rendered **once**, when the campaign is frozen.

```php
use JensS\Newsletter\Contracts\ContentBlock;
use JensS\Newsletter\Support\RenderedBlock;

class LatestPosts implements ContentBlock
{
    public function label(): string { return 'Latest posts'; }

    public function schema(): array
    {
        return [\Filament\Forms\Components\TextInput::make('limit')->numeric()->default(5)];
    }

    public function render(Campaign $campaign, array $options): RenderedBlock
    {
        $posts = Post::latest()->limit($options['limit'] ?? 5)->get();

        return new RenderedBlock(view('newsletter.blocks.posts', compact('posts'))->render());
    }
}
```

Register it in `config('newsletter.content.blocks')` or with `Newsletter::registerBlock('latest-posts', LatestPosts::class)`.

## Sending on shared hosting

When sending starts, recipients are prepared in pages of `chunk_size` (each page is its own short job, resumable after a crash). Every recipient gets a delivery record and a queued `SendCampaignDelivery` job on the `newsletter` queue. Each job:

1. **claims** the delivery with an atomic `queued → sending` update, so a duplicate job is a no-op;
2. **re-checks eligibility**: still subscribed, member still exists and is eligible, address not suppressed, current email address;
3. renders from the frozen snapshot and hands the message to the driver;
4. records `accepted` with the provider message ID, or retries temporary failures with backoff, or records permanent failures.

Rate limits (`per_second`, `per_minute`, `per_hour`) are enforced by job middleware. Short waits sleep in place; longer waits release the job without counting as a failure.

### Triggers

Use **any combination**. A cache lock makes sure only one newsletter worker runs at a time.

| Trigger | Setup | Latency |
| --- | --- | --- |
| Hourly cron | `0 * * * * php /path/artisan newsletter:work --max-time=3300` | keeps working for ~55 min per run |
| Minute cron | `* * * * * php /path/artisan schedule:run` plus a worker (below) | ~1 min |
| HTTP tick | `NEWSLETTER_TICK_TOKEN=…` and an external pinger | ~1 min |

`newsletter:work` starts due campaigns, recovers interrupted work and drains the queue until `--max-time` is used up, sleeping between empty polls. If your host kills long-running processes, use `--max-time=50` with a minute cron, or rely on the HTTP tick. `--once` runs a single pass.

**HTTP tick:** an external cron service (e.g. cron-job.org, or your uptime monitor) calls

```
POST https://your-site.example/newsletter/tick
Authorization: Bearer <NEWSLETTER_TICK_TOKEN>
```

once a minute. The endpoint responds `202` immediately and works for up to `tick_max_seconds` after the response has been flushed (PHP-FPM or LiteSpeed). Add `?sync=1` while debugging to wait for the result.

The package also registers `newsletter:maintain` (every minute) and `newsletter:prune` (daily) with Laravel's scheduler. Disable this with `worker.schedule = false`.

### Self-healing

`newsletter:maintain` (also part of every `work` run and tick):

- starts scheduled campaigns that are due;
- resumes preparation that stalled for more than 10 minutes;
- handles deliveries stuck in `sending` (the worker was killed mid-send). With an idempotent driver they are retried safely. Otherwise they are marked `failed` with *outcome unknown* rather than risking a duplicate;
- re-queues deliveries whose job disappeared;
- completes campaigns with no pending deliveries.

## Mail providers and webhooks

### Drivers

- `mail.driver = 'mailer'` (default) sends through Laravel Mail with `NEWSLETTER_MAILER` or your default mailer: SMTP, Resend, SES, Postmark and so on. The provider message ID is captured where the transport exposes it (Laravel's Resend transport does).
- `mail.driver = 'resend'` calls the Resend API directly with an **idempotency key per delivery** (`composer require resend/resend-php`, `RESEND_API_KEY` or `services.resend.key`). A retry after an interrupted attempt can never send twice, and deliveries are tagged so webhooks always match.
- Custom: any class implementing `JensS\Newsletter\Contracts\DeliveryDriver`.

Every message carries a deterministic `Message-ID` (`<delivery-uuid>@<your-domain>`) and `X-Newsletter-Delivery`, so bounce processors can match messages even over plain SMTP.

### Resend webhooks

Add an endpoint in the Resend dashboard:

```
https://your-site.example/newsletter/webhooks/resend
events: email.sent, email.delivered, email.bounced, email.complained, email.failed, email.suppressed
```

and set `NEWSLETTER_RESEND_WEBHOOK_SECRET=whsec_…`. Signatures (Svix) and timestamps are verified, and replays are ignored.

| Resend event | Delivery status | Suppression |
| --- | --- | --- |
| `email.sent` | `accepted` | |
| `email.delivered` | `delivered` | |
| `email.bounced` (Permanent) | `bounced` | yes |
| `email.bounced` (Transient) | unchanged; counted | after `soft_bounce_threshold` |
| `email.complained` | `complained` | yes |
| `email.failed` | `failed` | |
| `email.suppressed` | `suppressed` | yes |

Statuses only move forward: a late `delivered` never overwrites `bounced`.

### Generic webhook (SMTP, other providers)

`POST /newsletter/webhooks/generic` with `X-Newsletter-Signature: t=<unix>,v1=<hex HMAC-SHA256(secret, "<t>.<body>")>` and

```json
{"id": "evt-123", "type": "bounced", "message_id": "<uuid@example.com>", "permanent": true, "details": "5.1.1 user unknown"}
```

`type` is one of `bounced`, `complained`, `delivered`, `failed`. Identify the message with `delivery` (UUID), `message_id` or just `email`. Set `NEWSLETTER_WEBHOOK_SECRET`. `JensS\Newsletter\Webhooks\GenericSignature::sign()` creates the header.

## Sending through your web host's SMTP

Sending through a shared web host's mail server (netcup, Strato, IONOS, all-inkl …) works, but it has to be gentle. The package includes the safeguards for it:

- **Limits per second, minute, hour and day**, plus a **per-provider limit** (Gmail+Googlemail, GMX+web.de, Outlook+Hotmail … count as one).
- **Circuit breaker:** SMTP replies are classified as *recipient* problems (`550 5.1.1` → permanent), *temporary* ones (greylisting, mailbox full) or *systemic* ones (auth failure, quota or rate limit, connection refused). Systemic errors pause **all** sending for 5 → 15 → 60 → 240 minutes instead of failing the list, and it resumes on its own.
- **Reputation guard:** a campaign pauses itself above 5 % bounces or 0.3 % complaints (`guard.*`). Resume it in Filament or with `Newsletter::campaigns()->resume($campaign)`.
- **IMAP bounce processing** (`newsletter:process-mailbox`, pure PHP): RFC 3464 DSNs, non-standard bounces, ARF complaints and `mailto:` unsubscribes. Rejections caused by *your* reputation (DMARC, blocklists) never suppress the recipient.
- **Return-Path** (`NEWSLETTER_RETURN_PATH`), **Feedback-ID** for Gmail Postmaster Tools, a tokenised `mailto:` List-Unsubscribe next to the HTTPS one-click link.
- **Content checks** before sending (Gmail's ~102 KB clipping, http links, URL shorteners, missing alt text, image-only mails, shouting subjects), and relative URLs are made absolute.
- **`newsletter:doctor --smtp --mailbox`** checks SPF (single record, provider include), DKIM, DMARC, forward-confirmed reverse DNS, TLS port, From/mailbox alignment, and logs in to SMTP and IMAP.

**Step-by-step setup for netcup, including DNS records and `.env`: [docs/netcup.md](docs/netcup.md).**

## Account lifecycle (GDPR)

With the `HasNewsletterSubscriptions` trait, email changes move subscriptions automatically, and hard-deleting a member erases their newsletter data. Soft-deleted members are excluded at send time. To call these explicitly, for example from an account controller:

```php
Newsletter::members()->changeEmail($user, $newEmail, $oldEmail);
Newsletter::forgetMember($user);          // or an email address
$data = Newsletter::exportMember($user);  // subscriptions with consent history, deliveries, suppressions
```

`forget()` skips pending deliveries, deletes subscriptions and anonymises delivery history. Suppression entries are kept by default so a complaining or bouncing address is never mailed again. Pass `keepSuppression: false` to remove them.

Retention (`config('newsletter.retention')`, applied daily by `newsletter:prune`):

| Setting | Default |
| --- | --- |
| Unconfirmed subscriptions deleted after | 30 days |
| Unsubscribed records (subscription audiences) deleted after | 730 days (proof of withdrawal) |
| Delivery records anonymised after | 365 days |
| Webhook events deleted after | 30 days |

Opt-outs from application audiences are never pruned, because they are what keeps those people excluded.

## Filament admin

```php
use JensS\Newsletter\Filament\NewsletterPlugin;

$panel->plugin(
    NewsletterPlugin::make()
        ->navigationGroup('Community')
        ->subscriptions()   // subscriber list (default on)
        ->suppressions()    // suppression list (default on)
);
```

- **Campaigns:** compose (rich text, blocks, plain text, sender and template), preview with unsaved changes, send test, schedule or unschedule, send now with recipient estimate, cancel remaining. Includes live progress stats and a per-recipient delivery table.
- **Subscribers:** search and filter, consent details, add (goes through double opt-in), unsubscribe (single and bulk). Consent records cannot be edited.
- **Suppression list:** view, add manually, remove.

Authorization uses your `Campaign` / `Subscription` / `Suppression` policies when registered, otherwise the `manage-newsletters` gate.

## Customisation

| What | Where |
| --- | --- |
| Routes: prefix, domain, middleware, throttle | `routes.*` (or disable and register your own) |
| Spam protection for signups only | `routes.signup_middleware` |
| Authorization ability | `authorization.ability` |
| Queue connection, queue name, chunk size, tries, backoff | `queue.*` |
| Rate limits | `rate_limit.*` |
| Mailer, driver, sender, reply-to | `mail.*` |
| Brand name, logo, colour, postal address | `branding.*` |
| Email layout | `php artisan vendor:publish --tag=newsletter-views`, or per campaign via `content.templates` |
| Texts and translations | `--tag=newsletter-translations` |
| Table prefix | `table_prefix` |

Layouts receive `$content`, `$subject`, `$previewText`, `$branding`, `$unsubscribeUrl`, `$preferencesUrl`, `$recipient` (`email`, `name`, `firstName()`, `locale`, `key`) and `$data`. The rendered HTML and text must include `$content` and `$unsubscribeUrl` (`content.require_unsubscribe_link`).

Because the layout is rendered once per locale and frozen, **print per-recipient values unchanged**. `{{ $recipient->name }}` works; `{{ Str::upper($subject) }}` or `@if($recipient->name)` do not, and the first is refused when sending starts. Put conditional personal text in the content with `{{ name|fallback }}` instead. Everything else (`$branding`, `$data`, translations, `config()`) can be used freely.

## Events

| Event | When |
| --- | --- |
| `SubscriptionRequested` | double opt-in mail sent |
| `Subscribed` | subscription became active |
| `Unsubscribed` | withdrawal from an audience (with source: `link`, `one-click`, `preferences`, `admin`, …) |
| `AddressSuppressed` | address added to the suppression list |
| `CampaignScheduled`, `CampaignStarted`, `CampaignPrepared`, `CampaignCompleted`, `CampaignCancelled` | campaign lifecycle |
| `CampaignPaused`, `CampaignResumed` | reputation guard or admin paused / resumed a campaign (with reason) |
| `SendingMessage` | just before a message is handed to the driver (add headers here) |
| `DeliveryAccepted`, `DeliveryFailed`, `DeliverySkipped`, `DeliveryStatusChanged` | delivery lifecycle, including webhooks |
| `MemberForgotten` | newsletter data erased |

All events live in `JensS\Newsletter\Events`.

## Going to production

Run `php artisan newsletter:doctor --smtp` (add `--dkim-selector=resend` or your provider's selector; netcup's `key1`/`key2` are checked automatically). It checks:

- tables and an asynchronous queue (with its `jobs` table);
- a real mail transport, sender address and footer postal address;
- unsubscribe routes and an `https` `APP_URL`;
- a webhook secret when using Resend;
- **SPF, DKIM and DMARC** DNS records for the sending domain;
- for SMTP: TLS port, authentication, From/mailbox alignment, bounce mailbox, limits and reverse DNS of the mail server.

Bulk-sender requirements of the large mailbox providers (Google, Yahoo, Microsoft): authenticate the sending domain with SPF **and** DKIM, publish DMARC, align the From domain, keep spam complaints low, and offer one-click unsubscribe. This package handles unsubscribe, headers and suppression. The DNS side is up to you. See [Google's email sender guidelines](https://support.google.com/a/answer/81126).

## Testing

```bash
composer install
composer test
```

The suite covers subscriptions (double opt-in, scanners, expiry, enumeration, one-click, preferences), sending (freezing, chunking, personalization, eligibility re-checks, cancellation, scheduling), reliability (temporary and permanent failures, retry exhaustion, duplicate dispatch, re-run preparation, rate limits, crash recovery, lost jobs), webhooks (signatures, replays, bounces, complaints), the account lifecycle, installation, the worker and tick, and the Filament plugin.

## License

MIT. See [LICENSE](LICENSE).
