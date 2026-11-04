# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
- `newsletter:install`, `newsletter:doctor` (including SPF/DKIM/DMARC checks), `newsletter:send`, `newsletter:import`, `newsletter:prune`, `newsletter:maintain`.
