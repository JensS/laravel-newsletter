<?php

namespace JensS\Newsletter\Filament\Resources\CampaignResource\Pages;

use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use JensS\Newsletter\Enums\CampaignStatus;
use JensS\Newsletter\Exceptions\CampaignException;
use JensS\Newsletter\Filament\Resources\CampaignResource;
use JensS\Newsletter\Filament\Resources\CampaignResource\Widgets\CampaignProgress;
use JensS\Newsletter\Models\Campaign;
use JensS\Newsletter\Sending\CampaignDispatcher;
use JensS\Newsletter\Sending\CircuitBreaker;
use Throwable;

/**
 * @property Campaign $record
 */
class EditCampaign extends EditRecord
{
    protected static string $resource = CampaignResource::class;

    public function getTitle(): string
    {
        return $this->record->name;
    }

    public function getSubheading(): ?string
    {
        return match ($this->record->status) {
            CampaignStatus::Scheduled => 'Scheduled for '.$this->record->scheduled_at?->timezone(config('app.timezone'))->format('Y-m-d H:i'),
            CampaignStatus::Sending => 'Sending since '.$this->record->started_at?->diffForHumans()
                .(($reason = app(CircuitBreaker::class)->reason()) ? ' · Mail server paused sending: '.$reason : ''),
            CampaignStatus::Paused => 'Paused: '.$this->record->pause_reason,
            CampaignStatus::Sent => 'Sent '.$this->record->finished_at?->diffForHumans(),
            CampaignStatus::Cancelled => 'Cancelled '.$this->record->cancelled_at?->diffForHumans(),
            default => 'Draft',
        };
    }

    protected function getHeaderWidgets(): array
    {
        return $this->record->started_at ? [CampaignProgress::class] : [];
    }

    protected function getFormActions(): array
    {
        return $this->record->isEditable() ? parent::getFormActions() : [];
    }

    protected function getHeaderActions(): array
    {
        $dispatcher = fn () => app(CampaignDispatcher::class);

        return [
            Actions\Action::make('preview')
                ->icon('heroicon-o-eye')
                ->color('gray')
                ->modalHeading('Preview')
                ->modalWidth('5xl')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(function () use ($dispatcher) {
                    try {
                        $html = $dispatcher()->preview($this->previewRecord());
                    } catch (Throwable $e) {
                        $html = '<p style="font-family:sans-serif;color:#b91c1c">'.e($e->getMessage()).'</p>';
                    }

                    return view('newsletter::filament.preview', ['html' => $html]);
                }),

            Actions\Action::make('sendTest')
                ->label('Send test')
                ->icon('heroicon-o-beaker')
                ->color('gray')
                ->visible(fn () => $this->record->isEditable())
                ->form([
                    Forms\Components\TextInput::make('emails')
                        ->label('Send to')
                        ->helperText('Comma-separated addresses. Uses the unsaved content.')
                        ->default(fn () => auth()->user()?->email)
                        ->required(),
                ])
                ->action(function (array $data) use ($dispatcher) {
                    $emails = array_values(array_filter(array_map('trim', explode(',', $data['emails']))));

                    try {
                        $count = $dispatcher()->sendTest($this->previewRecord(), $emails, auth()->user()?->name);
                        Notification::make()->success()->title("Test sent to {$count} address(es)")->send();
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title('Test failed')->body($e->getMessage())->send();
                    }
                }),

            Actions\Action::make('schedule')
                ->icon('heroicon-o-clock')
                ->color('info')
                ->visible(fn () => $this->record->isEditable())
                ->form([
                    Forms\Components\DateTimePicker::make('scheduled_at')
                        ->label('Send at')
                        ->seconds(false)
                        ->minDate(now())
                        ->default(fn () => $this->record->scheduled_at ?? now()->addHour()->startOfHour())
                        ->helperText('Starts within a minute when an HTTP tick or minute cron is configured, otherwise at the next cron run.')
                        ->required(),
                ])
                ->action(function (array $data) use ($dispatcher) {
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
                    $this->guard(fn () => $dispatcher()->schedule($this->record->refresh(), Carbon::parse($data['scheduled_at'])), 'Campaign scheduled');
                }),

            Actions\Action::make('unschedule')
                ->label('Back to draft')
                ->color('gray')
                ->visible(fn () => $this->record->status === CampaignStatus::Scheduled)
                ->action(fn () => $this->guard(fn () => $dispatcher()->unschedule($this->record), 'Campaign is a draft again')),

            Actions\Action::make('sendNow')
                ->label('Send now')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->visible(fn () => $this->record->isEditable())
                ->requiresConfirmation()
                ->modalHeading('Send this campaign now?')
                ->modalDescription(function () use ($dispatcher) {
                    $count = rescue(fn () => $dispatcher()->estimateRecipients($this->record), 0, false);
                    $warnings = rescue(fn () => $dispatcher()->inspect($this->previewRecord()), [], false);
                    $text = e("It will be sent to about {$count} recipients. The content is frozen as soon as sending starts.");

                    if ($warnings !== []) {
                        $text .= '<br><br><strong>Deliverability warnings</strong><ul style="list-style:disc;padding-left:1.25rem;text-align:left">'
                            .implode('', array_map(fn ($w) => '<li>'.e($w).'</li>', $warnings)).'</ul>';
                    }

                    return new HtmlString($text);
                })
                ->action(function () use ($dispatcher) {
                    $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
                    $this->guard(fn () => $dispatcher()->start($this->record->refresh()), 'Sending started');
                }),

            Actions\Action::make('pause')
                ->icon('heroicon-o-pause')
                ->color('warning')
                ->visible(fn () => $this->record->status === CampaignStatus::Sending)
                ->requiresConfirmation()
                ->modalDescription('Queued deliveries wait until you resume. Messages already handed to the mail server are not affected.')
                ->action(fn () => $this->guard(fn () => $dispatcher()->pause($this->record, 'Paused by '.(auth()->user()?->name ?? 'admin')), 'Campaign paused')),

            Actions\Action::make('resume')
                ->icon('heroicon-o-play')
                ->color('success')
                ->visible(fn () => $this->record->status === CampaignStatus::Paused)
                ->requiresConfirmation()
                ->modalDescription(fn () => 'Paused because: '.$this->record->pause_reason.'. Check the delivery list (bounces, complaints) and the suppression list before resuming.')
                ->action(fn () => $this->guard(fn () => $dispatcher()->resume($this->record), 'Sending resumed')),

            Actions\Action::make('cancelCampaign')
                ->label(fn () => in_array($this->record->status, [CampaignStatus::Sending, CampaignStatus::Paused], true) ? 'Cancel remaining' : 'Cancel')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => in_array($this->record->status, [CampaignStatus::Scheduled, CampaignStatus::Sending, CampaignStatus::Paused], true))
                ->requiresConfirmation()
                ->modalDescription('Deliveries already handed to the mail provider cannot be recalled. Everything still queued is cancelled.')
                ->action(fn () => $this->guard(fn () => $dispatcher()->cancel($this->record), 'Campaign cancelled')),

            Actions\DeleteAction::make()->visible(fn () => $this->record->status === CampaignStatus::Draft),
        ];
    }

    /** The record with unsaved form changes applied (never persisted). */
    private function previewRecord(): Campaign
    {
        if (! $this->record->isEditable()) {
            return $this->record;
        }

        $state = $this->form->getState();

        return (clone $this->record)->forceFill(array_intersect_key($state, array_flip(Campaign::CONTENT_FIELDS)));
    }

    private function guard(callable $callback, string $success): void
    {
        try {
            $callback();
            Notification::make()->success()->title($success)->send();
        } catch (CampaignException $e) {
            Notification::make()->danger()->title('Not possible')->body($e->getMessage())->send();
        }

        $this->record->refresh();
        $this->fillForm();
    }
}
