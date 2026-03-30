<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MatchResultResource\Pages;
use App\Models\MatchResult;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MatchResultResource extends Resource
{
    protected static ?string $model = MatchResult::class;

    protected static ?string $navigationIcon  = 'heroicon-o-trophy';
    protected static ?string $navigationLabel = 'Risultati Partite';
    protected static ?string $modelLabel      = 'Risultato';
    protected static ?string $pluralModelLabel = 'Risultati Partite';
    protected static ?int    $navigationSort  = 4;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Partita')->schema([
                Forms\Components\Select::make('booking_id')
                    ->label('Prenotazione')
                    ->relationship('booking', 'id')
                    ->required(),

                Forms\Components\Select::make('winner_id')
                    ->label('Vincitore')
                    ->relationship('winner', 'name')
                    ->nullable()
                    ->searchable(),

                Forms\Components\TextInput::make('score')
                    ->label('Punteggio')
                    ->placeholder('es. 6-4 6-2')
                    ->nullable(),
            ])->columns(3),

            Forms\Components\Section::make('ELO')->schema([
                Forms\Components\TextInput::make('player1_elo_before')->label('ELO P1 prima')->numeric()->disabled(),
                Forms\Components\TextInput::make('player1_elo_after')->label('ELO P1 dopo')->numeric()->disabled(),
                Forms\Components\TextInput::make('player2_elo_before')->label('ELO P2 prima')->numeric()->disabled(),
                Forms\Components\TextInput::make('player2_elo_after')->label('ELO P2 dopo')->numeric()->disabled(),
            ])->columns(4),

            Forms\Components\Section::make('Conferme')->schema([
                Forms\Components\Toggle::make('player1_confirmed')->label('P1 confermato'),
                Forms\Components\Toggle::make('player2_confirmed')->label('P2 confermato'),
                Forms\Components\DateTimePicker::make('confirmed_at')->label('Confermato alle')->disabled(),
            ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('booking.booking_date')
                    ->label('Data partita')
                    ->date('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('booking.player1.name')
                    ->label('Giocatore 1'),

                Tables\Columns\TextColumn::make('booking.player2.name')
                    ->label('Giocatore 2'),

                Tables\Columns\TextColumn::make('winner.name')
                    ->label('Vincitore')
                    ->placeholder('—')
                    ->badge()
                    ->color('success'),

                Tables\Columns\TextColumn::make('score')
                    ->label('Punteggio')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('player1_elo_before')
                    ->label('ELO P1 prima')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('player1_elo_after')
                    ->label('ELO P1 dopo')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('player2_elo_before')
                    ->label('ELO P2 prima')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('player2_elo_after')
                    ->label('ELO P2 dopo')
                    ->numeric()
                    ->toggleable(isToggledHiddenByDefault: true),

                // Stato conferme
                Tables\Columns\IconColumn::make('player1_confirmed')
                    ->label('P1 ✓')
                    ->boolean(),

                Tables\Columns\IconColumn::make('player2_confirmed')
                    ->label('P2 ✓')
                    ->boolean(),

                Tables\Columns\TextColumn::make('confirmed_at')
                    ->label('Confermato')
                    ->dateTime('d/m H:i')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('da_verificare')
                    ->label('Da verificare (discordanti)')
                    ->query(fn (Builder $query) => $query
                        ->where('player1_confirmed', true)
                        ->where('player2_confirmed', true)
                        ->whereNull('winner_id')
                    ),

                Tables\Filters\Filter::make('in_attesa')
                    ->label('In attesa di conferma')
                    ->query(fn (Builder $query) => $query->where(
                        fn (Builder $q) => $q->where('player1_confirmed', false)->orWhere('player2_confirmed', false)
                    )),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMatchResults::route('/'),
            'edit'  => Pages\EditMatchResult::route('/{record}/edit'),
        ];
    }
}
