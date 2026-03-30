<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BookingResource\Pages;
use App\Models\Booking;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Prenotazioni';

    protected static ?string $modelLabel = 'Prenotazione';

    protected static ?string $pluralModelLabel = 'Prenotazioni';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Prenotazione')->schema([
                Forms\Components\Select::make('player1_id')
                    ->label('Giocatore 1')
                    ->relationship('player1', 'name')
                    ->searchable()
                    ->required(),

                Forms\Components\Select::make('player2_id')
                    ->label('Giocatore 2')
                    ->relationship('player2', 'name')
                    ->searchable()
                    ->nullable(),

                Forms\Components\DatePicker::make('booking_date')
                    ->label('Data')
                    ->required(),

                Forms\Components\TimePicker::make('start_time')
                    ->label('Ora inizio')
                    ->seconds(false)
                    ->required(),

                Forms\Components\TimePicker::make('end_time')
                    ->label('Ora fine')
                    ->seconds(false)
                    ->required(),

                Forms\Components\Select::make('status')
                    ->label('Stato')
                    ->options([
                        'pending_match' => 'In attesa avversario',
                        'confirmed'     => 'Confermata',
                        'cancelled'     => 'Annullata',
                        'completed'     => 'Completata',
                    ])
                    ->default('confirmed'),

                Forms\Components\TextInput::make('price')
                    ->label('Prezzo (€)')
                    ->numeric()
                    ->prefix('€'),

                Forms\Components\Toggle::make('is_peak')
                    ->label('Orario di punta'),

                Forms\Components\TextInput::make('gcal_event_id')
                    ->label('Google Calendar Event ID')
                    ->disabled(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('booking_date')
                    ->label('Data')
                    ->date('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('start_time')
                    ->label('Inizio'),

                Tables\Columns\TextColumn::make('end_time')
                    ->label('Fine'),

                Tables\Columns\TextColumn::make('player1.name')
                    ->label('Giocatore 1')
                    ->searchable(),

                Tables\Columns\TextColumn::make('player2.name')
                    ->label('Giocatore 2')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'confirmed'     => 'success',
                        'pending_match' => 'warning',
                        'cancelled'     => 'danger',
                        'completed'     => 'info',
                        default         => 'gray',
                    }),

                Tables\Columns\TextColumn::make('price')
                    ->label('Prezzo')
                    ->money('EUR')
                    ->sortable(),

                Tables\Columns\IconColumn::make('gcal_event_id')
                    ->label('Su Calendar')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Creata')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Stato')
                    ->options([
                        'pending_match' => 'In attesa avversario',
                        'confirmed'     => 'Confermata',
                        'cancelled'     => 'Annullata',
                        'completed'     => 'Completata',
                    ]),
            ])
            ->defaultSort('booking_date', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListBookings::route('/'),
            'create' => Pages\CreateBooking::route('/create'),
            'edit'   => Pages\EditBooking::route('/{record}/edit'),
        ];
    }
}
