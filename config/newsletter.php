<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Audiences
    |--------------------------------------------------------------------------
    |
    | Every campaign targets exactly one audience. Audiences never overlap
    | implicitly: being a member of the site does not subscribe anybody to
    | anything. Two kinds of audiences exist:
    |
    | - 'source' => 'subscriptions' (default): recipients are the package's own
    |   subscription records with status "subscribed". Use 'members' => true to
    |   link subscriptions to your member model; the member is then re-checked
    |   for eligibility (deleted, banned, unapproved ...) right before sending.
    |
    | - 'source' => SomeProvider::class: recipients come from your application
    |   (a class implementing JensS\Newsletter\Contracts\RecipientProvider,
    |   e.g. an EloquentRecipientProvider subclass). Your application owns the
    |   preference (for example a "weekly_recap" column). Unsubscribes made
    |   through the package are still honoured and reported via events.
    |
    */

    'audiences' => [
        'newsletter' => [
            'label' => 'Newsletter',
            'description' => 'News and announcements.',
            'source' => 'subscriptions',
            'double_opt_in' => true,
            'public_signup' => true,
            'members' => false,
        ],

        // 'members' => [
        //     'label' => 'Member newsletter',
        //     'source' => 'subscriptions',
        //     'double_opt_in' => false,   // members opt in from their (authenticated) account page
        //     'public_signup' => false,
        //     'members' => true,
        // ],

        // 'weekly-recap' => [
        //     'label' => 'Weekly recap',
        //     'source' => App\Newsletter\WeeklyRecapRecipients::class,
        // ],
    ],

    'default_audience' => 'newsletter',

    /*
    |--------------------------------------------------------------------------
    | Member integration
    |--------------------------------------------------------------------------
    |
    | The member model can be any Eloquent model. Either implement
    | JensS\Newsletter\Contracts\NewsletterMember on it, or map attributes here.
    | 'eligibility' may name a boolean method or attribute on the model, or a
    | class implementing JensS\Newsletter\Contracts\MemberResolver.
    |
    */

    'members' => [
        'model' => null, // e.g. App\Models\User::class
        'map' => [
            'email' => 'email',
            'name' => 'name',
            'locale' => null,
        ],
        'eligibility' => null, // e.g. 'isApproved' or App\Newsletter\MemberResolver::class
        'sync_email_changes' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sending
    |--------------------------------------------------------------------------
    */

    'mail' => [
        'mailer' => env('NEWSLETTER_MAILER'), // null = default mailer
        'driver' => 'mailer', // 'mailer' (Laravel Mail) or 'resend' (Resend API with idempotency keys)
        'from' => [
            'address' => env('NEWSLETTER_FROM_ADDRESS', env('MAIL_FROM_ADDRESS')),
            'name' => env('NEWSLETTER_FROM_NAME', env('MAIL_FROM_NAME')),
        ],
        'reply_to' => env('NEWSLETTER_REPLY_TO'),
        'unsubscribe_mailto' => env('NEWSLETTER_UNSUBSCRIBE_MAILTO'), // optional mailto: for List-Unsubscribe
        'message_id_domain' => env('NEWSLETTER_MESSAGE_ID_DOMAIN'), // defaults to the from-address domain
    ],

    'queue' => [
        'connection' => env('NEWSLETTER_QUEUE_CONNECTION'), // null = default connection
        'name' => env('NEWSLETTER_QUEUE', 'newsletter'),
        'chunk_size' => 200,     // recipients prepared per preparation job
        'tries' => 5,            // attempts for temporary failures
        'backoff' => [60, 300, 900, 3600], // seconds between attempts
        'retry_until_hours' => 48, // stop retrying a delivery after this long
        'stale_after_minutes' => 30, // a "sending" delivery older than this is considered interrupted
    ],

    'rate_limit' => [
        'per_second' => env('NEWSLETTER_RATE_PER_SECOND', 2),
        'per_minute' => env('NEWSLETTER_RATE_PER_MINUTE', 100),
        'per_hour' => env('NEWSLETTER_RATE_PER_HOUR'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Background processing on shared hosting
    |--------------------------------------------------------------------------
    |
    | Neither Redis nor a permanently running worker is required. Use any mix:
    | - hourly cron:      php artisan newsletter:work --max-time=3300
    | - Laravel scheduler (if cron runs every minute): registered automatically
    | - HTTP tick:        POST /newsletter/tick with "Authorization: Bearer <token>"
    |                     from an external pinger, every minute.
    |
    */

    'worker' => [
        'schedule' => true,           // register scheduler tasks (dispatch, recover, prune)
        'tick_token' => env('NEWSLETTER_TICK_TOKEN'), // null disables the HTTP tick
        'tick_max_seconds' => 40,      // work budget per HTTP tick
        'tick_max_jobs' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    */

    'routes' => [
        'enabled' => true,
        'prefix' => 'newsletter',
        'domain' => null,
        'middleware' => ['web'],
        'webhook_middleware' => [], // no session/CSRF: one-click unsubscribe, tick and webhooks
        'signup_throttle' => '6,1', // attempts,minutes for public signups
    ],

    /*
    |--------------------------------------------------------------------------
    | Subscriptions
    |--------------------------------------------------------------------------
    */

    'subscriptions' => [
        'confirmation_expires_hours' => 72,
        'queue_confirmation' => false, // send confirmation mails immediately (good for hourly cron)
        'redirect_after_signup' => null, // URL or null to show the package page
        'honeypot_field' => 'website',
    ],

    /*
    |--------------------------------------------------------------------------
    | Content
    |--------------------------------------------------------------------------
    |
    | Blocks are application-provided content (latest posts, events ...).
    | Each class implements JensS\Newsletter\Contracts\ContentBlock and is
    | rendered once when a campaign starts sending (content freezing).
    |
    */

    'content' => [
        'layout' => 'newsletter::mail.layout',
        'text_layout' => 'newsletter::mail.layout-text',
        'templates' => [
            'newsletter::mail.layout' => 'Default',
        ],
        'blocks' => [
            // 'latest-posts' => App\Newsletter\Blocks\LatestPosts::class,
        ],
        'require_unsubscribe_link' => true, // refuse to send a template without the unsubscribe URL
    ],

    'branding' => [
        'name' => env('NEWSLETTER_BRAND_NAME', env('APP_NAME')),
        'url' => env('APP_URL'),
        'logo_url' => env('NEWSLETTER_LOGO_URL'),
        'accent_color' => '#111827',
        'postal_address' => env('NEWSLETTER_POSTAL_ADDRESS'), // shown in the footer; required by many jurisdictions
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    |
    | Admin features (Filament plugin, preview route) check this gate ability
    | unless you register a policy for JensS\Newsletter\Models\Campaign.
    |
    */

    'authorization' => [
        'ability' => 'manage-newsletters',
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery webhooks
    |--------------------------------------------------------------------------
    */

    'webhooks' => [
        'resend' => [
            'secret' => env('NEWSLETTER_RESEND_WEBHOOK_SECRET'), // whsec_...
            'tolerance_seconds' => 300,
        ],
        'generic' => [
            'secret' => env('NEWSLETTER_WEBHOOK_SECRET'), // HMAC-SHA256 shared secret
            'tolerance_seconds' => 300,
        ],
    ],

    'suppression' => [
        'on_permanent_bounce' => true,
        'on_complaint' => true,
        'soft_bounce_threshold' => 3, // suppress after this many transient bounces (0 = never)
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Applied by `newsletter:prune` (scheduled daily). null keeps forever.
    |
    */

    'retention' => [
        'unconfirmed_subscriptions_days' => 30,
        'unsubscribed_subscriptions_days' => 730, // keep consent/withdrawal proof
        'delivery_records_days' => 365,           // anonymise recipient data after this
        'webhook_events_days' => 30,
    ],

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    */

    'table_prefix' => 'newsletter_',
];
