<?php
namespace App\Filament\Resources;

use App\Filament\Resources\PricingRuleResource\Pages;
use App\Models\PricingRule;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PricingRuleResource extends Resource
{
    protected static ?string $model = PricingRule::class;
    protected static ?string $navigationIcon  = 'heroicon-o-currency-euro';
    protected static ?string $navigationLabel = 'Prezzi';
    protected static ?string $modelLabel      = 'Regola Prezzo';
    protected static ?string $pluralModelLabel = 'Tabella Prezzi';
    protected static ?int    $navigationSort  = 6;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Quando si applica')->schema([
                Forms\Components\TextInput::make('label')
                    ->label('Nome regola')
                    ->placeholder('es. Sera feriale 1h')
                    ->required(),

                Forms\Components\Select::make('day_of_week')
                    ->label('Giorno settimana')
                    ->options([
                        0 => 'Domenica', 1 => 'Lunedì', 2 => 'Martedì',
                        3 => 'Mercoledì', 4 => 'Giovedì', 5 => 'Venerdì', 6 => 'Sabato',
                    ])
                    ->placeholder('Tutti i giorni')
                    ->nullable(),

                Forms\Components\DatePicker::make('specific_date')
                    ->label('Data specifica (override)')
                    ->placeholder('Solo per questa data')
                    ->nullable()
                    ->helperText('Prevale sul giorno della settimana. Utile per festivi.'),

                Forms\Components\TimePicker::make('start_time')
                    ->label('Dalle')
                    ->seconds(false)
                    ->required(),

                Forms\Components\TimePicker::make('end_time')
                    ->label('Alle')
                    ->seconds(false)
                    ->required(),

                Forms\Components\Select::make('duration_minutes')
                    ->label('Durata slot')
                    ->options([
                        60  => '1 ora',
                        90  => '1,5 ore',
                        120 => '2 ore',
                        180 => '3 ore',
                    ])
                    ->placeholder('Qualsiasi durata')
                    ->nullable(),
            ])->columns(3),

            Forms\Components\Section::make('Prezzo e priorità')->schema([
                Forms\Components\TextInput::make('price')
                    ->label('Prezzo slot (€)')
                    ->numeric()
                    ->prefix('€')
                    ->required()
                    ->helperText('Prezzo totale per questo slot (indipendente dalla durata).'),

                Forms\Components\TextInput::make('priority')
                    ->label('Priorità')
                    ->numeric()
                    ->default(0)
                    ->helperText('Valore più alto = regola più specifica. Usare 0 per generiche, 1 per weekend, 2 per festivi.'),

                Forms\Components\Toggle::make('is_peak')
                    ->label('Orario di punta'),

                Forms\Components\Toggle::make('is_active')
                    ->label('Attiva')
                    ->default(true),
            ])->columns(4),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('label')->label('Nome')->searchable(),
                Tables\Columns\TextColumn::make('day_of_week')
                    ->label('Giorno')
                    ->formatStateUsing(fn ($state) => match((int)$state) {
                        0 => 'Dom', 1 => 'Lun', 2 => 'Mar', 3 => 'Mer',
                        4 => 'Gio', 5 => 'Ven', 6 => 'Sab', default => 'Tutti',
                    })
                    ->placeholder('Tutti'),
                Tables\Columns\TextColumn::make('specific_date')->label('Data specifica')->date('d/m/Y')->placeholder('—'),
                Tables\Columns\TextColumn::make('start_time')->label('Dalle'),
                Tables\Columns\TextColumn::make('end_time')->label('Alle'),
                Tables\Columns\TextColumn::make('duration_minutes')
                    ->label('Durata')
                    ->formatStateUsing(fn ($state) => $state ? \App\Models\PricingRule::durationLabel((int)$state) : 'Qualsiasi')
                    ->placeholder('Qualsiasi'),
                Tables\Columns\TextColumn::make('price')->label('Prezzo')->money('EUR'),
                Tables\Columns\TextColumn::make('priority')->label('Priorità')->sortable(),
                Tables\Columns\IconColumn::make('is_active')->label('Attiva')->boolean(),
                Tables\Columns\IconColumn::make('is_peak')->label('Peak')->boolean(),
            ])
            ->filters([
                Tables\Filters\Filter::make('attive')
                    ->label('Solo attive')
                    ->query(fn ($q) => $q->where('is_active', true))
                    ->default(),
            ])
            ->defaultSort('priority', 'desc')
            ->reorderable('priority');
    }

    public static function getRelations(): array { return []; }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPricingRules::route('/'),
            'create' => Pages\CreatePricingRule::route('/create'),
            'edit'   => Pages\EditPricingRule::route('/{record}/edit'),
        ];
    }
}
