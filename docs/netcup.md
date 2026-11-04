# Sending through a web host's SMTP server (netcup)

A dedicated email provider (Resend, Postmark, SES …) is the better choice for newsletters. When you send through the mail server of a shared web hosting package instead, you share IP reputation with other customers, have no say over reverse DNS, get no delivery events, and work under sending limits that are rarely published. This guide uses netcup web hosting as the example and covers how to make that setup work as well as it can.

The package protects the hosting account and the domain's reputation while it sends:

| Problem with shared-host SMTP | What the package does |
| --- | --- |
| Unknown hourly and daily caps; exceeding them blocks the mailbox | per-second, minute, hour and **day** limits; **circuit breaker** pauses all sending on quota, auth or connection errors (5 → 15 → 60 → 240 min) and resumes on its own |
| Big providers throttle bursts from unknown senders | **per-provider limit**: Gmail+Googlemail, GMX+web.de, Outlook+Hotmail and others count as one provider |
| No webhooks: bounces arrive as emails | **IMAP bounce processing** (pure PHP, no `ext-imap`): DSNs, non-standard bounces, ARF spam complaints, auto-replies |
| Bad addresses damage the shared IP's reputation | hard bounces and complaints are suppressed automatically; repeated soft bounces too |
| A bad list can get the account suspended | the **reputation guard** pauses a campaign above 5 % bounces or 0.3 % complaints |
| Rejections caused by *your* reputation (DMARC, blocklists) | recognised as sender problems; recipients are **not** suppressed for them |
| Unsubscribe requirements | HTTPS one-click (RFC 8058) plus a `mailto:` address that the mailbox processor handles |
| Gmail Postmaster Tools | `Feedback-ID` header on every campaign message |
| Gmail clips mail above ~102 KB; relative links break | size and content warnings before sending; relative URLs are made absolute |

## 1. Create a dedicated mailbox

In the netcup control panel, create a real mailbox such as `newsletter@example.org` and use that **one address** for everything machine-related:

- **From**: the sender address and the authenticated SMTP user are the same. Shared hosts may reject or rewrite mail whose From address differs from the logged-in mailbox, and netcup signs DKIM for the mailbox's domain.
- **Return-Path**: bounces come back here.
- **Bounce mailbox**: the package reads it over IMAP.
- **mailto unsubscribe**: unsubscribe requests sent by email arrive here too.

Set a **Reply-To** with a human address (e.g. `hello@example.org`) so that replies from readers don't get lost among bounces. Messages the package doesn't recognise (real replies that still end up in this mailbox) are left unread.

## 2. Configure Laravel

Use **authenticated SMTP via netcup's mail server**. netcup only DKIM-signs mail that goes through its mail server, not mail sent with PHP `mail()` or sendmail from the web server. Take the exact server name from the control panel (usually `mxXXXX.netcup.net`). Its TLS certificate matches that name, while `mail.<your-domain>` often doesn't.

```dotenv
# Mail server (Laravel 12+ mail config)
MAIL_MAILER=smtp
MAIL_SCHEME=smtps
MAIL_HOST=mxXXXX.netcup.net
MAIL_PORT=465
MAIL_USERNAME=newsletter@example.org
MAIL_PASSWORD="..."

# Sender
NEWSLETTER_FROM_ADDRESS=newsletter@example.org
NEWSLETTER_FROM_NAME="Example Club"
NEWSLETTER_REPLY_TO=hello@example.org
NEWSLETTER_RETURN_PATH=newsletter@example.org
NEWSLETTER_UNSUBSCRIBE_MAILTO=newsletter@example.org
NEWSLETTER_POSTAL_ADDRESS="Example Club e.V., Example Street 1, 10115 Berlin"
NEWSLETTER_FEEDBACK_ID=exampleclub

# Bounce mailbox (IMAP over TLS)
NEWSLETTER_MAILBOX_HOST=mxXXXX.netcup.net
NEWSLETTER_MAILBOX_PORT=993
NEWSLETTER_MAILBOX_ENCRYPTION=ssl
NEWSLETTER_MAILBOX_USERNAME=newsletter@example.org
NEWSLETTER_MAILBOX_PASSWORD="..."
NEWSLETTER_MAILBOX_PROCESSED_FOLDER=Newsletter-Processed

# Conservative limits (see "Sending limits" below)
NEWSLETTER_RATE_PER_SECOND=1
NEWSLETTER_RATE_PER_MINUTE=20
NEWSLETTER_RATE_PER_HOUR=300
NEWSLETTER_RATE_PER_DAY=2000
NEWSLETTER_RATE_PER_DOMAIN_PER_MINUTE=10

# Background processing (see the README)
NEWSLETTER_QUEUE_CONNECTION=database
NEWSLETTER_TICK_TOKEN=<random 40 characters>
```

If you'd rather keep the application's other mail on a different mailer, define a second mailer in `config/mail.php` (e.g. `newsletter`) and set `NEWSLETTER_MAILER=newsletter`. Port 587 with STARTTLS works too (`MAIL_SCHEME=smtp`, `MAIL_PORT=587`).

### Sending limits

netcup doesn't publish an outgoing-mail limit for web hosting. The values above are deliberately low starting points: 2,000 recipients take a day at most and roughly seven hours at 300/hour. **Ask netcup support for the limit of your package** and stay at about half of it. If you hit the limit anyway, the circuit breaker pauses sending instead of failing deliveries, and everything continues later.

## 3. DNS records

Set these in netcup's DNS management for the sending domain. netcup documents the SPF and DKIM values in its help center ([web hosting DNS records](https://www.netcup.com/en/helpcenter/documentation/web-hosting/reseller-dns)):

| Host | Type | Value |
| --- | --- | --- |
| `@` | TXT | `v=spf1 mx a include:_spf.webhosting.systems ~all` |
| `key1._domainkey` | CNAME | `key1._domainkey.webhosting.systems` |
| `key2._domainkey` | CNAME | `key2._domainkey.webhosting.systems` |
| `_dmarc` | TXT | `v=DMARC1; p=none; rua=mailto:dmarc@example.org` |

- Publish **exactly one** SPF record. Two SPF records make SPF fail everywhere. If you also send through other services, merge their `include:`s into this one record.
- **Both** DKIM CNAMEs are required.
- Start DMARC at `p=none` and read the aggregate reports (`rua`) for 2–4 weeks. Once SPF and DKIM pass consistently, move to `p=quarantine`. Gmail and Yahoo require a DMARC record from bulk senders.

Then check everything:

```bash
php artisan newsletter:doctor --smtp --mailbox
```

The doctor detects netcup, checks SPF (single record, netcup include, no `+all`), DKIM `key1`/`key2`, DMARC with `rua`, forward-confirmed reverse DNS of the mail server, port and TLS, From/mailbox alignment, the bounce mailbox and your limits. `--smtp` logs in to the SMTP server without sending, and `--mailbox` opens the IMAP folder.

Finally, send a test to [mail-tester.com](https://www.mail-tester.com) from the campaign editor (*Send test*) and fix anything it reports.

## 4. Bounce processing

Maintenance (cron, scheduler or HTTP tick) reads the mailbox every `mailbox.interval_minutes` (10 by default). You can also run it yourself:

```bash
php artisan newsletter:process-mailbox --test      # can we log in?
php artisan newsletter:process-mailbox --dry-run   # what would happen?
php artisan newsletter:process-mailbox -v          # process and list every message
```

| Message | Effect |
| --- | --- |
| Hard bounce (`Action: failed`, `5.x.x`) | delivery `bounced`, address suppressed |
| Delay notice or soft bounce (`4.x.x`) | counted; address suppressed after `soft_bounce_threshold` (3) within 90 days |
| Rejection because of your reputation (`5.7.x` with DMARC, SPF, blocklist …) | soft bounce, **not** suppressed (fix your setup instead) |
| ARF spam complaint | delivery `complained`, address suppressed |
| `unsubscribe <token>` email (from the `mailto:` header) | unsubscribed from that audience |
| Auto-reply / out of office | marked as read |
| Anything else (human replies) | left untouched |

Handled messages are moved to `NEWSLETTER_MAILBOX_PROCESSED_FOLDER`, or marked as read if no folder is set. The processor remembers which messages it has already seen, so nothing is processed twice.

With SMTP, a delivery never becomes `delivered`, because there are no delivery receipts. `accepted` means netcup's server took the message. If no bounce arrives, assume it was delivered.

## 5. Warm-up and list hygiene

A domain that has never sent bulk mail through this server should start slowly:

1. **First week:** a few hundred messages a day (`NEWSLETTER_RATE_PER_DAY=200`), and send to recently active, confirmed subscribers first.
2. **Increase** the daily limit by roughly doubling it each week while bounce and complaint rates stay low.
3. **Old lists:** if addresses haven't been mailed for more than a year, send a re-confirmation campaign first rather than a newsletter. These lists are where hard bounces and spam traps hide.
4. Keep **double opt-in** for public signups, which is the default.

## 6. Monitoring

- **Gmail Postmaster Tools** (postmaster.google.com): verify the domain to see spam rate and reputation. The `Feedback-ID` header groups the numbers per campaign. Keep the spam rate below 0.1 % and never reach 0.3 %.
- **DMARC reports** (`rua`): confirm that only netcup's servers send as your domain and that SPF and DKIM pass.
- **Blocklists:** if bounces mention Spamhaus, Barracuda or similar, check the mail server's IP on mxtoolbox.com and contact netcup support. On shared hosting only netcup can fix it.
- **Paused campaigns:** the reputation guard pauses a campaign and records why (Filament shows the reason). Review the bounced addresses before you resume.

## 7. When to move to a provider

Switch to a dedicated provider (the package supports any Laravel mailer, Resend natively) if:

- a single send regularly exceeds a few thousand recipients;
- campaigns keep running into the hosting limits or the circuit breaker;
- bounces mention blocklists or the shared IP's reputation;
- you need confirmed delivery or complaint data from Gmail.

Switching means changing the mailer configuration and adding the provider's webhook. Audiences, subscriptions, campaigns and history stay as they are.
