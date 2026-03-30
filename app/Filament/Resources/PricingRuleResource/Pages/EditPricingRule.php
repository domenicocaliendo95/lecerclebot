<?php
namespace App\Filament\Resources\PricingRuleResource\Pages;
use App\Filament\Resources\PricingRuleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
class EditPricingRule extends EditRecord {
    protected static string $resource = PricingRuleResource::class;
    protected function getHeaderActions(): array { return [Actions\DeleteAction::make()]; }
}
