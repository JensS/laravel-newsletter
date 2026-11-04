<?php

namespace JensS\Newsletter\Filament\Resources;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use JensS\Newsletter\Enums\CampaignStatus;
use JensS\Newsletter\Filament\Concerns\AuthorizesNewsletterAccess;
use JensS\Newsletter\Filament\Resources\CampaignResource\Pages;
use JensS\Newsletter\Filament\Resources\CampaignResource\RelationManagers\DeliveriesRelationManager;
use JensS\Newsletter\Models\Campaign;
use JensS\Newsletter\Support\AudienceRegistry;
use JensS\Newsletter\Support\BlockRegistry;

class CampaignResource extends Resource
{
    use AuthorizesNewsletterAccess;

    protected static ?string $model = Campaign::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        $locked = fn (?Campaign $record) => $record !== null && ! $record->isEditable();

        return $form->schema([
            Forms\Components\Placeholder::make('frozen')
                ->hiddenLabel()
                ->content(new HtmlString('<strong>This campaign has started sending.</strong> Its content is frozen and can no longer be changed.'))
                ->visible($locked)
                ->columnSpanFull(),

            Forms\Components\Section::make('Campaign')->schema([
                Forms\Components\TextInput::make('name')
                    ->helperText('Internal name, not shown to recipients.')
                    ->maxLength(255),
                Forms\Components\Select::make('audience')
                    ->options(fn () => app(AudienceRegistry::class)->options())
                    ->default(config('newsletter.default_audience'))
                    ->required()
                    ->live()
                    ->helperText(function (?string $state) {
                        $registry = app(AudienceRegistry::class);

                        if (! $state || ! $registry->has($state)) {
                            return null;
                        }

                        try {
                            return 'About '.number_format($registry->get($state)->recipients()->count()).' recipients right now.';
                        } catch (\Throwable) {
                            return null;
                        }
                    }),
                Forms\Components\TextInput::make('subject')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Placeholders: {{ first_name|there }}, {{ name }}, {{ email }}'),
                Forms\Components\TextInput::make('preview_text')
                    ->maxLength(255)
                    ->helperText('Shown next to the subject in most inboxes.'),
            ])->columns(2)->disabled($locked),

            Forms\Components\Section::make('Content')->schema([
                Forms\Components\RichEditor::make('content_html')
                    ->label('HTML content')
                    ->toolbarButtons(['bold', 'italic', 'link', 'h2', 'h3', 'bulletList', 'orderedList', 'blockquote', 'undo', 'redo'])
                    ->columnSpanFull(),
                Forms\Components\Builder::make('blocks')
                    ->label('Content blocks')
                    ->helperText('Rendered once, when sending starts.')
                    ->blocks(fn () => collect(app(BlockRegistry::class)->all())
                        ->map(fn ($block, $key) => Forms\Components\Builder\Block::make($key)
                            ->label($block->label())
                            ->schema($block->schema()))
                        ->values()
                        ->all())
                    ->visible(fn () => app(BlockRegistry::class)->all() !== [])
                    ->collapsible()
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('content_text')
                    ->label('Plain-text version')
                    ->helperText('Leave empty to generate it from the HTML content.')
                    ->rows(8)
                    ->columnSpanFull(),
            ])->disabled($locked),

            Forms\Components\Section::make('Sender & template')->schema([
                Forms\Components\TextInput::make('from_name')->placeholder(fn () => config('newsletter.mail.from.name')),
                Forms\Components\TextInput::make('from_address')->email()->placeholder(fn () => config('newsletter.mail.from.address')),
                Forms\Components\TextInput::make('reply_to')->email()->placeholder(fn () => config('newsletter.mail.reply_to')),
                Forms\Components\Select::make('layout')
                    ->options(fn () => (array) config('newsletter.content.templates', []))
                    ->placeholder('Default'),
            ])->columns(2)->collapsed()->disabled($locked),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->description(fn (Campaign $r) => $r->subject),
                Tables\Columns\TextColumn::make('audience')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => app(AudienceRegistry::class)->has($state) ? app(AudienceRegistry::class)->get($state)->label() : $state),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (CampaignStatus $state) => $state->label())
                    ->color(fn (CampaignStatus $state) => match ($state) {
                        CampaignStatus::Draft => 'gray',
                        CampaignStatus::Scheduled => 'info',
                        CampaignStatus::Sending => 'warning',
                        CampaignStatus::Sent => 'success',
                        CampaignStatus::Cancelled => 'danger',
                    }),
                Tables\Columns\TextColumn::make('progress')
                    ->state(function (Campaign $record) {
                        if ($record->started_at === null) {
                            return '—';
                        }

                        $p = $record->progress();

                        return $p->processed().' / '.$p->total();
                    }),
                Tables\Columns\TextColumn::make('scheduled_at')->dateTime()->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('finished_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(collect(CampaignStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])),
                Tables\Filters\SelectFilter::make('audience')->options(fn () => app(AudienceRegistry::class)->options()),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label(fn (Campaign $r) => $r->isEditable() ? 'Edit' : 'View'),
                Tables\Actions\DeleteAction::make()->visible(fn (Campaign $r) => $r->status === CampaignStatus::Draft),
            ]);
    }

    public static function getRelations(): array
    {
        return [DeliveriesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCampaigns::route('/'),
            'create' => Pages\CreateCampaign::route('/create'),
            'edit' => Pages\EditCampaign::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $sending = static::getModel()::query()->where('status', CampaignStatus::Sending->value)->count();

        return $sending > 0 ? (string) $sending : null;
    }
}
