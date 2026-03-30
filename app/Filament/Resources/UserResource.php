<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Giocatori';

    protected static ?string $modelLabel = 'Giocatore';

    protected static ?string $pluralModelLabel = 'Giocatori';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Dati personali')->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('phone')
                    ->label('Telefono WhatsApp')
                    ->tel()
                    ->maxLength(30),

                Forms\Components\TextInput::make('age')
                    ->label('Età')
                    ->numeric()
                    ->minValue(5)
                    ->maxValue(99),

                Forms\Components\TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->maxLength(255),
            ])->columns(2),

            Forms\Components\Section::make('Profilo tennistico')->schema([
                Forms\Components\Toggle::make('is_fit')
                    ->label('Tesserato FIT'),

                Forms\Components\TextInput::make('fit_rating')
                    ->label('Classifica FIT')
                    ->placeholder('es. 4.1, 3.3, NC'),

                Forms\Components\Select::make('self_level')
                    ->label('Livello autodichiarato')
                    ->options([
                        'neofita'    => 'Neofita',
                        'dilettante' => 'Dilettante',
                        'avanzato'   => 'Avanzato',
                    ]),

                Forms\Components\TextInput::make('elo_rating')
                    ->label('ELO Rating')
                    ->numeric()
                    ->default(1200),

                Forms\Components\Select::make('preferred_slots')
                    ->label('Fasce orarie preferite')
                    ->multiple()
                    ->options([
                        'mattina'    => 'Mattina',
                        'pomeriggio' => 'Pomeriggio',
                        'sera'       => 'Sera',
                    ]),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Telefono')
                    ->searchable(),

                Tables\Columns\IconColumn::make('is_fit')
                    ->label('FIT')
                    ->boolean(),

                Tables\Columns\TextColumn::make('fit_rating')
                    ->label('Classifica')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('self_level')
                    ->label('Livello')
                    ->badge()
                    ->color(fn(?string $state): string => match ($state) {
                        'neofita'    => 'gray',
                        'dilettante' => 'warning',
                        'avanzato'   => 'success',
                        default      => 'gray',
                    }),

                Tables\Columns\TextColumn::make('elo_rating')
                    ->label('ELO')
                    ->sortable(),

                Tables\Columns\TextColumn::make('age')
                    ->label('Età')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Registrato')
                    ->dateTime('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_fit')
                    ->label('Tesserato FIT'),
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
            'index'  => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit'   => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
