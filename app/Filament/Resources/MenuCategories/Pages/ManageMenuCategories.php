<?php

namespace App\Filament\Resources\MenuCategories\Pages;

use App\Filament\Resources\MenuCategories\MenuCategoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMenuCategories extends ManageRecords
{
    protected static string $resource = MenuCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
