<?php

namespace JensS\Newsletter\Filament\Resources\CampaignResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use JensS\Newsletter\Filament\Resources\CampaignResource;

class CreateCampaign extends CreateRecord
{
    protected static string $resource = CampaignResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['name'] = filled($data['name'] ?? null) ? $data['name'] : $data['subject'];
        $data['status'] = 'draft';

        if (($user = auth()->user()) instanceof Model) {
            $data['created_by_type'] = $user->getMorphClass();
            $data['created_by_id'] = $user->getKey();
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
