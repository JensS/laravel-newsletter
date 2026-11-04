<?php

namespace JensS\Newsletter\Filament\Resources\SuppressionResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use JensS\Newsletter\Filament\Resources\SuppressionResource;

class ManageSuppressions extends ManageRecords
{
    protected static string $resource = SuppressionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Suppress address')];
    }
}
