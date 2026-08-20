<?php

namespace App\Filament\Resources\MenuStyles\Pages;

use App\Filament\Resources\MenuStyles\MenuStyleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMenuStyles extends ManageRecords
{
    protected static string $resource = MenuStyleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
