<?php

namespace JensS\Newsletter\Tests\Feature;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use JensS\Newsletter\Enums\CampaignStatus;
use JensS\Newsletter\Facades\Newsletter;
use JensS\Newsletter\Filament\Resources\CampaignResource;
use JensS\Newsletter\Filament\Resources\CampaignResource\Pages\CreateCampaign;
use JensS\Newsletter\Filament\Resources\CampaignResource\Pages\EditCampaign;
use JensS\Newsletter\Filament\Resources\CampaignResource\Pages\ListCampaigns;
use JensS\Newsletter\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use JensS\Newsletter\Filament\Resources\SuppressionResource\Pages\ManageSuppressions;
use JensS\Newsletter\Models\Campaign;
use JensS\Newsletter\Tests\Fixtures\AdminPanelProvider;
use JensS\Newsletter\Tests\Fixtures\User;
use JensS\Newsletter\Tests\TestCase;
use Livewire\Livewire;

class FilamentPluginTest extends TestCase
{
    private User $admin;

    protected function getPackageProviders($app): array
    {
        return array_merge(parent::getPackageProviders($app), [
            \RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider::class,
            \BladeUI\Icons\BladeIconsServiceProvider::class,
            \BladeUI\Heroicons\BladeHeroiconsServiceProvider::class,
            \Livewire\LivewireServiceProvider::class,
            \Filament\Support\SupportServiceProvider::class,
            \Filament\Actions\ActionsServiceProvider::class,
            \Filament\Forms\FormsServiceProvider::class,
            \Filament\Infolists\InfolistsServiceProvider::class,
            \Filament\Notifications\NotificationsServiceProvider::class,
            \Filament\Tables\TablesServiceProvider::class,
            \Filament\Widgets\WidgetsServiceProvider::class,
            \Filament\FilamentServiceProvider::class,
            AdminPanelProvider::class,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->user(['email' => 'admin@example.test']);
        Gate::define('manage-newsletters', fn (User $user) => $user->email === 'admin@example.test');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_resources_are_registered_and_guarded_by_the_gate(): void
    {
        $this->actingAs($this->admin)->get(CampaignResource::getUrl('index'))->assertOk();

        $this->actingAs($this->user())->get(CampaignResource::getUrl('index'))->assertForbidden();
    }

    public function test_compose_and_send_a_campaign(): void
    {
        Newsletter::subscribe('newsletter', 'reader@example.test', ['confirmed' => true]);
        $this->actingAs($this->admin);

        Livewire::test(CreateCampaign::class)
            ->fillForm([
                'audience' => 'newsletter',
                'subject' => 'From Filament',
                'preview_text' => 'Preview',
                'content_html' => '<p>Hello {{ first_name|there }}</p>',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $campaign = Campaign::query()->sole();
        $this->assertSame('From Filament', $campaign->name);
        $this->assertSame($this->admin->id, (int) $campaign->created_by_id);

        Livewire::test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
            ->assertActionVisible('preview')
            ->callAction('sendTest', ['emails' => 'admin@example.test'])
            ->assertNotified()
            ->callAction('sendNow')
            ->assertNotified('Sending started');

        $this->assertSame(CampaignStatus::Sending, $campaign->fresh()->status);
        $this->work();
        $this->assertSame(['reader@example.test'], $this->driver->recipients());

        Livewire::test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
            ->assertActionHidden('sendNow')
            ->assertActionHidden('schedule')
            ->assertSee('frozen');
    }

    public function test_schedule_and_cancel(): void
    {
        $campaign = $this->campaign();
        $this->actingAs($this->admin);

        Livewire::test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
            ->callAction('schedule', ['scheduled_at' => now()->addDay()->format('Y-m-d H:i:s')])
            ->assertNotified('Campaign scheduled');

        $this->assertSame(CampaignStatus::Scheduled, $campaign->fresh()->status);

        Livewire::test(EditCampaign::class, ['record' => $campaign->getRouteKey()])
            ->callAction('cancelCampaign');

        $this->assertSame(CampaignStatus::Cancelled, $campaign->fresh()->status);
    }

    public function test_list_pages_render(): void
    {
        $this->actingAs($this->admin);
        $campaign = $this->campaign();
        Newsletter::subscribe('newsletter', 'listed@example.test', ['confirmed' => true]);
        Newsletter::suppressions()->add('bounced@example.test', 'bounce');

        Livewire::test(ListCampaigns::class)->assertCanSeeTableRecords([$campaign]);
        Livewire::test(ListSubscriptions::class)->assertSee('listed@example.test');
        Livewire::test(ManageSuppressions::class)->assertSee('bounced@example.test');
    }
}
