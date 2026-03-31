<?php

namespace App\Filament\Widgets;

use App\Models\User;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class LatestUsers extends TableWidget
{
    protected static ?string $heading = 'Ultimi giocatori registrati';
    protected static ?int $sort = 4;
    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                User::query()
                    ->where('is_admin', false)
                    ->latest()
                    ->limit(8)
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nome')
                    ->weight('bold')
                    ->searchable(),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Telefono')
                    ->icon('heroicon-o-phone')
                    ->color('gray'),

                Tables\Columns\IconColumn::make('is_fit')
                    ->label('FIT')
                    ->boolean()
                    ->trueColor('success')
                    ->falseColor('gray'),

                Tables\Columns\TextColumn::make('self_level')
                    ->label('Livello')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'neofita'     => 'gray',
                        'dilettante'  => 'info',
                        'avanzato'    => 'success',
                        default       => 'gray',
                    })
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('elo_rating')
                    ->label('ELO')
                    ->numeric()
                    ->alignEnd()
                    ->color('primary')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Registrato')
                    ->since()
                    ->color('gray'),
            ])
            ->paginated(false)
            ->emptyStateHeading('Nessun giocatore')
            ->emptyStateIcon('heroicon-o-users');
    }
}
