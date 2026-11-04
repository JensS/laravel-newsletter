# Integration guide

Two step-by-step recipes for adding the package to an existing community site. Both assume Laravel 12+, an existing member model, and shared hosting with a limited cron.

- [Recipe A: existing newsletter, separate subscribers and members, weekly recap](#recipe-a-replacing-an-existing-newsletter)
- [Recipe B: member site with a synchronous queue and an account page](#recipe-b-adding-a-newsletter-to-a-member-site)
- [Choosing a background trigger](#choosing-a-background-trigger)

---

## Recipe A: replacing an existing newsletter

Starting point: a `newsletter_subscribers`-style table for the public, newsletter drafts, Blade email templates, a job that queues emails, and a separate weekly recap for approved members with its own preference column.

### 1. Install

```bash
composer require jenss/laravel-newsletter
php artisan newsletter:install --views
```

If your existing tables already use the `newsletter_` prefix, set `'table_prefix' => 'nl_'` in `config/newsletter.php` **before** running the migration.

### 2. Define two separate audiences

```php
// config/newsletter.php
'audiences' => [
    'newsletter' => [
        'label' => 'Newsletter',
        'source' => 'subscriptions',
        'double_opt_in' => true,
        'public_signup' => true,
    ],
    'weekly-recap' => [
        'label' => 'Weekly recap',
        'source' => App\Newsletter\WeeklyRecapRecipients::class,
    ],
],
'members' => [
    'model' => App\Models\User::class,
    'map' => ['email' => 'email', 'name' => 'name', 'locale' => null],
],
```

The public newsletter and the weekly recap stay independent: unsubscribing from one never affects the other, and being an approved member does not add anyone to the newsletter.

### 3. Keep the weekly-recap preference where it is

```php
namespace App\Newsletter;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use JensS\Newsletter\Contracts\HandlesUnsubscribes;
use JensS\Newsletter\Recipients\EloquentRecipientProvider;

class WeeklyRecapRecipients extends EloquentRecipientProvider implements HandlesUnsubscribes
{
    protected function query(): Builder
    {
        // Adjust to your schema: approved members who want the recap.
        return User::query()->whereNotNull('approved_at')->where('weekly_recap', true);
    }

    public function unsubscribed(string $email, ?Model $member): void
    {
        User::where('email', $email)->update(['weekly_recap' => false]);
    }
}
```

When a member re-enables the recap in their settings, clear any package opt-out:

```php
$user->update(['weekly_recap' => true]);
Newsletter::subscriptions()->clearOptOut('weekly-recap', $user->email);
```

### 4. Import existing subscribers

Only import people who actually consented (confirmed subscribers). Keep their original timestamps:

```php
// e.g. in a one-off command or tinker
use App\Models\NewsletterSubscriber;
use JensS\Newsletter\Facades\Newsletter;

$rows = NewsletterSubscriber::query()->whereNotNull('confirmed_at')->cursor()->map(fn ($s) => [
    'email' => $s->email,
    'name' => $s->name,
    'consent_at' => $s->created_at,
    'confirmed_at' => $s->confirmed_at,
    'source' => 'legacy',
]);

Newsletter::subscriptions()->import('newsletter', $rows);
```

Or export a CSV and run `php artisan newsletter:import newsletter subscribers.csv`.

### 5. Move drafts and templates

- Your Blade email template becomes the layout: copy its markup into `resources/views/vendor/newsletter/mail/layout.blade.php` (published in step 1). Keep `{{ $unsubscribeUrl }}` in the footer, because the package refuses to send without it.
- Existing drafts become campaigns: `Newsletter::draft(['audience' => 'newsletter', 'subject' => ..., 'content_html' => ...])`.

### 6. Replace the sending job

The old job marked a newsletter as *sent* as soon as the emails were queued. With the package, the campaign status follows the real deliveries:

```php
// Before
SendNewsletterJob::dispatch($newsletter); // marked "sent" immediately

// After
Newsletter::send($campaign);           // status: sending
$campaign->progress()->toArray();      // queued / accepted / delivered / failed / bounced ...
// status becomes "sent" once no delivery is pending
```

### 7. Automate the weekly recap

Render recap content as a content block and create a campaign from the scheduler:

```php
// app/Newsletter/Blocks/WeeklyHighlights.php implements ContentBlock
// config: 'content' => ['blocks' => ['weekly-highlights' => App\Newsletter\Blocks\WeeklyHighlights::class]]

// routes/console.php
Schedule::call(function () {
    $campaign = Newsletter::draft([
        'name' => 'Weekly recap '.now()->format('o-\WW'),
        'audience' => 'weekly-recap',
        'subject' => 'Your weekly recap',
        'preview_text' => 'What happened this week',
        'content_html' => '<p>Hi {{ first_name|there }},</p>',
        'blocks' => [['type' => 'weekly-highlights', 'data' => []]],
    ]);

    Newsletter::send($campaign);
})->weeklyOn(1, '07:00');
```

With an hourly cron the recap starts at the next cron run after Monday 07:00. Either schedule it on the hour, or use the HTTP tick for minute precision.

### 8. Resend

Resend is already installed, so you can keep using it through Laravel Mail, or switch to the direct driver for idempotency keys (recommended):

```dotenv
NEWSLETTER_FROM_ADDRESS=news@example.org
NEWSLETTER_FROM_NAME="Example Club"
NEWSLETTER_POSTAL_ADDRESS="Example Street 1, 10115 Berlin"
NEWSLETTER_RESEND_WEBHOOK_SECRET=whsec_...
NEWSLETTER_RATE_PER_SECOND=2
```

```php
'mail' => ['driver' => 'resend', /* ... */],
```

Then add the webhook `https://example.org/newsletter/webhooks/resend` in Resend and run `php artisan newsletter:doctor --dkim-selector=resend`.

---

## Recipe B: adding a newsletter to a member site

Starting point: members with an account page (including a `pm_notifications_enabled` setting for private-message notifications), an account controller that handles deletion and data export explicitly, a `sync` queue, and SMTP mail.

### 1. Install and give the newsletter its own queue connection

You do **not** have to change how the rest of the application queues jobs. Point only the newsletter at the database queue:

```bash
composer require jenss/laravel-newsletter
php artisan newsletter:install
php artisan make:queue-table   # if the jobs table does not exist yet
php artisan migrate
```

```php
// config/queue.php → 'connections'
'database' => [
    'driver' => 'database',
    'connection' => env('DB_QUEUE_CONNECTION'),
    'table' => env('DB_QUEUE_TABLE', 'jobs'),
    'queue' => env('DB_QUEUE', 'default'),
    'retry_after' => 120,
    'after_commit' => true,
],
```

```dotenv
QUEUE_CONNECTION=sync                    # unchanged for the rest of the app
NEWSLETTER_QUEUE_CONNECTION=database     # newsletter jobs only
NEWSLETTER_MAILER=smtp
NEWSLETTER_TICK_TOKEN=<random 40 chars>
```

### 2. One member audience with explicit opt-in

```php
'audiences' => [
    'members' => [
        'label' => 'Newsletter',
        'source' => 'subscriptions',
        'members' => true,
        'double_opt_in' => true,   // send a confirmation mail even to logged-in members
    ],
],
'default_audience' => 'members',
'members' => [
    'model' => App\User::class,
    'map' => ['email' => 'email', 'name' => 'display_name', 'locale' => 'locale'],
    'eligibility' => 'isActive',  // method or attribute on your model
],
```

Add `use HasNewsletterSubscriptions;` to the member model. Existing members stay unsubscribed until they opt in.

### 3. Account page

Add a separate checkbox. `pm_notifications_enabled` keeps controlling private-message notifications only:

```blade
<label>
    <input type="checkbox" name="newsletter" value="1" @checked($user->isSubscribedToNewsletter())>
    Send me the newsletter
</label>
```

```php
// AccountController@update
$user->update(['pm_notifications_enabled' => $request->boolean('pm_notifications_enabled')]);

if ($request->boolean('newsletter') && ! $user->isSubscribedToNewsletter()) {
    // double_opt_in => true: a confirmation mail is sent; pass confirmed: true to skip it
    $user->subscribeToNewsletter(source: 'account-settings', confirmed: false);
} elseif (! $request->boolean('newsletter')) {
    $user->unsubscribeFromNewsletter(source: 'account-settings');
}
```

### 4. Deletion and export in the account controller

The trait already erases newsletter data on hard delete. If deletion is a custom flow (anonymising instead of deleting, for example), call the hooks explicitly:

```php
// AccountController@destroy
Newsletter::forgetMember($user);   // skips pending sends, deletes subscriptions, anonymises history
// ... your existing deletion code

// AccountController@export
$export['newsletter'] = Newsletter::exportMember($user);
```

Email changes are picked up automatically by the trait. Without the trait, call `Newsletter::members()->changeEmail($user, $new, $old)`.

### 5. SMTP now, provider later

Bounces over plain SMTP arrive in a mailbox. Point your bounce processor (or a small script) at the generic webhook to suppress hard bounces, as described in the README section *Generic webhook*. Messages carry `Message-ID: <delivery-uuid@your-domain>` for matching. To move to a provider later, change `NEWSLETTER_MAILER` (any Laravel mailer works) and add that provider's webhook.

---

## Choosing a background trigger

| Host can… | Use |
| --- | --- |
| run cron hourly, processes may run ~1 hour | `0 * * * * php artisan newsletter:work --max-time=3300` |
| run cron hourly, long processes get killed | hourly `newsletter:work --max-time=50` **plus** the HTTP tick |
| run cron every minute | `* * * * * php artisan schedule:run` and `* * * * * php artisan newsletter:work --max-time=50` |
| no cron at all | HTTP tick only |

**HTTP tick:** a free external scheduler (cron-job.org, EasyCron, your uptime monitor) calls `POST /newsletter/tick` with `Authorization: Bearer $NEWSLETTER_TICK_TOKEN` every minute. Each call works for up to `tick_max_seconds` after responding. A Pusher-protocol realtime server is not a queue and is not needed here.

All triggers share one lock, so combining them is safe.
