<?php

namespace JensS\Newsletter\Filament\Resources;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use JensS\Newsletter\Filament\Concerns\AuthorizesNewsletterAccess;
use JensS\Newsletter\Filament\Resources\SuppressionResource\Pages;
use JensS\Newsletter\Models\Suppression;

class SuppressionResource extends Resource
{
    use AuthorizesNewsletterAccess;

    protected static ?string $model = Suppression::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static ?string $navigationLabel = 'Suppression list';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('email')->email()->required()->unique(ignoreRecord: true),
            Forms\Components\Hidden::make('reason')->default(Suppression::REASON_MANUAL),
            Forms\Components\Hidden::make('source')->default('admin'),
            Forms\Components\Textarea::make('details'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('email')->searchable(),
                Tables\Columns\TextColumn::make('reason')->badge()->color(fn (string $state) => $state === 'manual' ? 'gray' : 'danger'),
                Tables\Columns\TextColumn::make('source'),
                Tables\Columns\TextColumn::make('details')->limit(50)->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->label('Since')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('reason')->options(['bounce' => 'Bounce', 'complaint' => 'Complaint', 'manual' => 'Manual']),
            ])
            ->actions([
                Tables\Actions\DeleteAction::make()
                    ->label('Remove')
                    ->modalDescription('The address can receive newsletters again. Only do this if you are sure the problem is resolved.'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageSuppressions::route('/'),
        ];
    }
}
