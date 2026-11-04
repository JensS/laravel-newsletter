<?php

namespace JensS\Newsletter\Filament\Resources\SubscriptionResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use JensS\Newsletter\Filament\Resources\SubscriptionResource;
use JensS\Newsletter\Subscriptions\SubscriptionManager;
use Throwable;

class ListSubscriptions extends ListRecords
{
    protected static string $resource = SubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add subscriber')
                ->modalDescription('The address receives a confirmation email if the audience uses double opt-in.')
                ->using(function (array $data) {
                    try {
                        return app(SubscriptionManager::class)->subscribe($data['audience'], $data['email'], [
                            'name' => $data['name'] ?? null,
                            'source' => 'admin',
                        ]);
                    } catch (Throwable $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                        $this->halt();
                    }
                }),
        ];
    }
}
