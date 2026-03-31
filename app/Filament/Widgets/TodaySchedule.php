<?php

namespace App\Filament\Widgets;

use App\Models\Booking;
use Carbon\Carbon;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class TodaySchedule extends TableWidget
{
    protected static ?string $heading = 'Programma di oggi';
    protected static ?int $sort = 3;
    protected int | string | array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Booking::query()
                    ->with(['player1', 'player2'])
                    ->where('booking_date', today())
                    ->whereIn('status', ['confirmed', 'pending_match'])
                    ->orderBy('start_time')
            )
            ->columns([
                Tables\Columns\TextColumn::make('start_time')
                    ->label('Orario')
                    ->formatStateUsing(function ($state, $record) {
                        $s = Carbon::parse($state)->format('H:i');
                        $e = Carbon::parse($record->end_time)->format('H:i');
                        return "{$s} – {$e}";
                    })
                    ->icon('heroicon-o-clock')
                    ->sortable(),

                Tables\Columns\TextColumn::make('player1.name')
                    ->label('Giocatore 1')
                    ->weight('bold')
                    ->searchable(),

                Tables\Columns\TextColumn::make('player2.name')
                    ->label('Giocatore 2')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'confirmed'     => 'Confermata',
                        'pending_match' => 'In attesa',
                        default         => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        'confirmed'     => 'success',
                        'pending_match' => 'warning',
                        default         => 'gray',
                    }),

                Tables\Columns\TextColumn::make('price')
                    ->label('Prezzo')
                    ->money('EUR')
                    ->alignEnd(),

                Tables\Columns\IconColumn::make('is_peak')
                    ->label('Peak')
                    ->boolean()
                    ->trueIcon('heroicon-s-bolt')
                    ->trueColor('warning')
                    ->falseIcon('heroicon-o-minus')
                    ->falseColor('gray'),
            ])
            ->paginated(false)
            ->emptyStateHeading('Nessuna prenotazione oggi')
            ->emptyStateDescription('Il campo è libero per tutta la giornata.')
            ->emptyStateIcon('heroicon-o-calendar');
    }
}
