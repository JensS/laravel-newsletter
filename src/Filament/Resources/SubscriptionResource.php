<?php

namespace JensS\Newsletter\Filament\Resources;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use JensS\Newsletter\Enums\SubscriptionStatus;
use JensS\Newsletter\Filament\Concerns\AuthorizesNewsletterAccess;
use JensS\Newsletter\Filament\Resources\SubscriptionResource\Pages;
use JensS\Newsletter\Models\Subscription;
use JensS\Newsletter\Subscriptions\SubscriptionManager;
use JensS\Newsletter\Support\AudienceRegistry;

/**
 * Subscribers are added through the double opt-in flow (or by members
 * themselves). Admins can look up, add (with confirmation) and unsubscribe,
 * but never edit consent records.
 */
class SubscriptionResource extends Resource
{
    use AuthorizesNewsletterAccess;

    protected static ?string $model = Subscription::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $modelLabel = 'subscriber';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('email')->email()->required(),
            Forms\Components\TextInput::make('name'),
            Forms\Components\Select::make('audience')
                ->options(fn () => collect(app(AudienceRegistry::class)->all())
                    ->filter(fn ($a) => $a->usesSubscriptions() && ! $a->linksMembers())
                    ->map(fn ($a) => $a->label()))
                ->default(config('newsletter.default_audience'))
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('email')->searchable()->description(fn (Subscription $r) => $r->name),
                Tables\Columns\TextColumn::make('audience')->badge(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (SubscriptionStatus $state) => $state->label())
                    ->color(fn (SubscriptionStatus $state) => match ($state) {
                        SubscriptionStatus::Subscribed => 'success',
                        SubscriptionStatus::Pending => 'warning',
                        SubscriptionStatus::Unsubscribed => 'gray',
                    }),
                Tables\Columns\TextColumn::make('source')->toggleable(),
                Tables\Columns\TextColumn::make('consent_at')->dateTime()->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('unsubscribed_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('subscribable_type')->label('Member')->formatStateUsing(fn ($state, Subscription $r) => $state ? class_basename($state).' #'.$r->subscribable_id : null)->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('audience')->options(fn () => app(AudienceRegistry::class)->options()),
                Tables\Filters\SelectFilter::make('status')->options(collect(SubscriptionStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->infolist(fn ($infolist) => $infolist->schema([
                    \Filament\Infolists\Components\TextEntry::make('email'),
                    \Filament\Infolists\Components\TextEntry::make('audience'),
                    \Filament\Infolists\Components\TextEntry::make('status')->formatStateUsing(fn ($state) => $state->label()),
                    \Filament\Infolists\Components\TextEntry::make('source'),
                    \Filament\Infolists\Components\TextEntry::make('consent_at')->dateTime(),
                    \Filament\Infolists\Components\TextEntry::make('consent_ip'),
                    \Filament\Infolists\Components\TextEntry::make('consent_text')->columnSpanFull(),
                    \Filament\Infolists\Components\TextEntry::make('confirmed_at')->dateTime(),
                    \Filament\Infolists\Components\TextEntry::make('unsubscribed_at')->dateTime(),
                    \Filament\Infolists\Components\TextEntry::make('unsubscribe_source'),
                ])->columns(2)),
                Tables\Actions\Action::make('unsubscribe')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Subscription $r) => $r->status !== SubscriptionStatus::Unsubscribed)
                    ->action(fn (Subscription $r) => app(SubscriptionManager::class)->unsubscribe($r->audience, $r->email, 'admin')),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('unsubscribe')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records) {
                        $records->each(fn (Subscription $r) => app(SubscriptionManager::class)->unsubscribe($r->audience, $r->email, 'admin'));
                        Notification::make()->success()->title($records->count().' unsubscribed')->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptions::route('/'),
        ];
    }
}
