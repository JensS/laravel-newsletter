<?php

namespace JensS\Newsletter\Filament\Resources\CampaignResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use JensS\Newsletter\Enums\DeliveryStatus;

class DeliveriesRelationManager extends RelationManager
{
    protected static string $relationship = 'deliveries';

    protected static ?string $title = 'Deliveries';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->started_at !== null;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('email')
            ->defaultSort('id')
            ->poll(fn () => $this->getOwnerRecord()->status->value === 'sending' ? '10s' : null)
            ->columns([
                Tables\Columns\TextColumn::make('email')->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (DeliveryStatus $state) => $state->label())
                    ->color(fn (DeliveryStatus $state) => $state->color()),
                Tables\Columns\TextColumn::make('attempts')->numeric()->toggleable(),
                Tables\Columns\TextColumn::make('accepted_at')->dateTime()->toggleable(),
                Tables\Columns\TextColumn::make('delivered_at')->dateTime()->toggleable(),
                Tables\Columns\TextColumn::make('last_error')->limit(60)->tooltip(fn ($record) => $record->last_error)->toggleable(),
                Tables\Columns\TextColumn::make('provider_message_id')->label('Provider ID')->toggleable(isToggledHiddenByDefault: true)->copyable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(DeliveryStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
            ]);
    }
}
