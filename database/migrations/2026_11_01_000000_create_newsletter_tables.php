<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('newsletter.table_prefix', 'newsletter_');

        Schema::create($prefix.'subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('audience', 64);
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('locale', 12)->nullable();
            $table->nullableMorphs('subscribable');
            $table->string('status', 20)->index(); // pending, subscribed, unsubscribed
            $table->string('source', 100)->nullable();
            $table->timestamp('consent_at')->nullable();
            $table->string('consent_ip', 45)->nullable();
            $table->text('consent_text')->nullable();
            $table->string('confirmation_token', 64)->nullable()->unique();
            $table->timestamp('confirmation_sent_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->string('unsubscribe_source', 100)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['audience', 'email']);
            $table->index(['audience', 'status', 'id']);
        });

        Schema::create($prefix.'campaigns', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('audience', 64);
            $table->string('status', 20)->index(); // draft, scheduled, sending, paused, sent, cancelled
            $table->string('subject');
            $table->string('preview_text')->nullable();
            $table->longText('content_html')->nullable();
            $table->longText('content_text')->nullable();
            $table->json('blocks')->nullable();
            $table->string('layout')->nullable();
            $table->json('data')->nullable();
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();
            $table->string('reply_to')->nullable();
            $table->json('snapshot')->nullable(); // frozen content, written when sending starts
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('prepared_at')->nullable();
            $table->string('prepare_cursor')->nullable();
            $table->timestamp('prepare_heartbeat_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->string('pause_reason')->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->nullableMorphs('created_by');
            $table->timestamps();
        });

        Schema::create($prefix.'deliveries', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('campaign_id')->constrained($prefix.'campaigns')->cascadeOnDelete();
            $table->string('recipient_key', 191);
            $table->foreignId('subscription_id')->nullable()->constrained($prefix.'subscriptions')->nullOnDelete();
            $table->nullableMorphs('subscribable');
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('locale', 12)->nullable();
            $table->json('personalization')->nullable();
            $table->string('status', 20); // queued, sending, accepted, delivered, failed, bounced, complained, suppressed, skipped, cancelled
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('provider', 40)->nullable();
            $table->string('message_id')->nullable()->index();
            $table->string('provider_message_id')->nullable()->index();
            $table->string('bounce_type', 20)->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->timestamp('complained_at')->nullable();
            $table->timestamp('anonymized_at')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'recipient_key']);
            $table->unique(['campaign_id', 'email']); // one copy per address, even if two recipients share it
            $table->index(['campaign_id', 'status']);
            $table->index(['status', 'claimed_at']);
            $table->index('email');
        });

        Schema::create($prefix.'suppressions', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('reason', 30); // bounce, complaint, manual
            $table->string('source', 100)->nullable();
            $table->text('details')->nullable();
            $table->timestamps();
        });

        Schema::create($prefix.'webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40);
            $table->string('event_id', 191);
            $table->string('type', 60);
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        $prefix = config('newsletter.table_prefix', 'newsletter_');

        Schema::dropIfExists($prefix.'webhook_events');
        Schema::dropIfExists($prefix.'suppressions');
        Schema::dropIfExists($prefix.'deliveries');
        Schema::dropIfExists($prefix.'campaigns');
        Schema::dropIfExists($prefix.'subscriptions');
    }
};
