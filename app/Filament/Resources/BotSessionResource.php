<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BotSessionResource\Pages;
use App\Models\BotSession;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BotSessionResource extends Resource
{
    protected static ?string $model = BotSession::class;
    protected static ?string $navigationIcon  = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationLabel = 'Sessioni Bot';
    protected static ?string $modelLabel      = 'Sessione Bot';
    protected static ?string $pluralModelLabel = 'Sessioni Bot';
    protected static ?int    $navigationSort  = 2;

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([

            // ── Riepilogo sessione ──────────────────────────────────────
            Infolists\Components\Section::make('Sessione')
                ->columns(3)
                ->schema([
                    Infolists\Components\TextEntry::make('phone')
                        ->label('Telefono'),

                    Infolists\Components\TextEntry::make('state')
                        ->label('Stato')
                        ->badge()
                        ->color(fn(string $state): string => match (true) {
                            str_starts_with($state, 'ONBOARD') => 'warning',
                            $state === 'MENU'                  => 'success',
                            $state === 'CONFERMATO'            => 'success',
                            $state === 'NEW'                   => 'gray',
                            default                            => 'info',
                        }),

                    Infolists\Components\TextEntry::make('updated_at')
                        ->label('Ultimo aggiornamento')
                        ->dateTime('d/m/Y H:i'),
                ]),

            // ── Profilo raccolto ────────────────────────────────────────
            Infolists\Components\Section::make('Profilo utente')
                ->columns(4)
                ->collapsed()
                ->schema([
                    Infolists\Components\TextEntry::make('data.persona')
                        ->label('Persona bot')
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('data.profile.name')
                        ->label('Nome')
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('data.profile.is_fit')
                        ->label('Tesserato FIT')
                        ->formatStateUsing(fn($state) => match(true) {
                            $state === true  || $state === 1 || $state === '1' => 'Sì',
                            $state === false || $state === 0 || $state === '0' => 'No',
                            default => '—',
                        })
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('data.profile.fit_rating')
                        ->label('Classifica FIT')
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('data.profile.self_level')
                        ->label('Livello autodichiarato')
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('data.profile.age')
                        ->label('Età')
                        ->placeholder('—'),

                    Infolists\Components\TextEntry::make('data.profile.slot')
                        ->label('Fascia preferita')
                        ->placeholder('—'),
                ]),

            // ── Chat ────────────────────────────────────────────────────
            Infolists\Components\Section::make('Conversazione')
                ->schema([
                    Infolists\Components\ViewEntry::make('data.history')
                        ->label('')
                        ->view('filament.infolists.chat-history'),
                ]),

        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('phone')
                    ->label('Telefono')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('state')
                    ->label('Stato')
                    ->badge()
                    ->color(fn(string $state): string => match (true) {
                        str_starts_with($state, 'ONBOARD') => 'warning',
                        $state === 'MENU'                  => 'success',
                        $state === 'CONFERMATO'            => 'success',
                        $state === 'NEW'                   => 'gray',
                        default                            => 'info',
                    }),

                Tables\Columns\TextColumn::make('data.persona')
                    ->label('Persona')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('data.profile.name')
                    ->label('Nome utente')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Ultimo aggiornamento')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('state')
                    ->label('Stato')
                    ->options([
                        'NEW'                => 'NEW',
                        'ONBOARD_NOME'       => 'ONBOARD_NOME',
                        'ONBOARD_FIT'        => 'ONBOARD_FIT',
                        'ONBOARD_CLASSIFICA' => 'ONBOARD_CLASSIFICA',
                        'ONBOARD_LIVELLO'    => 'ONBOARD_LIVELLO',
                        'ONBOARD_ETA'        => 'ONBOARD_ETA',
                        'ONBOARD_SLOT_PREF'  => 'ONBOARD_SLOT_PREF',
                        'ONBOARD_COMPLETO'   => 'ONBOARD_COMPLETO',
                        'MENU'               => 'MENU',
                        'SCEGLI_QUANDO'      => 'SCEGLI_QUANDO',
                        'VERIFICA_SLOT'      => 'VERIFICA_SLOT',
                        'PROPONI_SLOT'       => 'PROPONI_SLOT',
                        'CONFERMA'           => 'CONFERMA',
                        'PAGAMENTO'          => 'PAGAMENTO',
                        'CONFERMATO'         => 'CONFERMATO',
                        'ATTESA_MATCH'       => 'ATTESA_MATCH',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('reset')
                    ->label('Reset')
                    ->icon('heroicon-o-arrow-path')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn(BotSession $record) => $record->resetConversation(
                        'NEW',
                        \App\Services\Bot\BotPersona::pickRandom()
                    )),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBotSessions::route('/'),
            'view'  => Pages\ViewBotSession::route('/{record}'),
        ];
    }
}
