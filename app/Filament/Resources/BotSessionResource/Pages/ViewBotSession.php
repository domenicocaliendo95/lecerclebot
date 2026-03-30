<?php

namespace App\Filament\Resources\BotSessionResource\Pages;

use App\Filament\Resources\BotSessionResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewBotSession extends ViewRecord
{
    protected static string $resource = BotSessionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('reset')
                ->label('Reset sessione')
                ->icon('heroicon-o-arrow-path')
                ->color('danger')
                ->requiresConfirmation()
                ->action(fn() => $this->record->resetConversation('NEW', \App\Services\Bot\BotPersona::pickRandom())),
        ];
    }
}
