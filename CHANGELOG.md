# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] - 2026-11-04

First stable release.

### Added

- Audiences backed by package subscriptions (with optional member linking) or application recipient providers.
- Double opt-in with scanner-safe confirmation, consent source/IP/text/timestamps, CSV import keeping original consent.
- Login-free unsubscribe and preference pages, RFC 8058 one-click unsubscribe headers.
- Campaigns with subject, preview text, HTML/plain text, personalization, application content blocks, test emails, scheduling, cancellation and content freezing.
- Chunked, resumable preparation; one delivery record per campaign and recipient; atomic claims against duplicate dispatch; rate limits; retries with backoff; crash recovery.
- `mailer` (any Laravel mailer) and `resend` (idempotency keys) drivers.
- Resend (Svix-signed) and generic HMAC webhooks; automatic suppression of permanent bounces and complaints.
- Shared-hosting workers: `newsletter:work` for hourly cron, scheduler integration, authenticated HTTP tick.
- Account lifecycle hooks: email change, erasure, export, retention pruning.
- Optional Filament 3 plugin for campaigns, subscribers and the suppression list.
- `routes.signup_middleware` for host-app spam checks on the public signup endpoint only.
- Import of `pending` and `unsubscribed` records (CSV `status` column, `--status`), including opt-outs for application-provided audiences.
- Rendered layout output is frozen per locale in the campaign snapshot (v2), so template deployments cannot change queued messages; regional locales fall back to their language.
- Shared-host SMTP safeguards: per-day and per-provider limits, SMTP error classification with a global circuit breaker, a reputation guard that pauses campaigns (`paused` status, pause/resume), Return-Path and Feedback-ID headers, tokenised `mailto:` List-Unsubscribe.
- Pure-PHP IMAP bounce processing (`newsletter:process-mailbox`): RFC 3464 DSNs, non-standard bounces, ARF complaints, mailto unsubscribes, auto-replies.
- Content checks (Gmail clipping, http links, shorteners, alt text, subjects) and automatic absolute URLs.
- netcup guide (`docs/netcup.md`) and netcup-aware `newsletter:doctor` checks (SPF include, DKIM key1/key2, DMARC, reverse DNS, `--smtp`, `--mailbox`).
- `newsletter:install`, `newsletter:doctor` (including SPF/DKIM/DMARC checks), `newsletter:send`, `newsletter:import`, `newsletter:prune`, `newsletter:maintain`.

[Unreleased]: https://github.com/JensS/laravel-newsletter/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/JensS/laravel-newsletter/releases/tag/v1.0.0
