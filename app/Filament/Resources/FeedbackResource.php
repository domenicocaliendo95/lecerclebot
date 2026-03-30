<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FeedbackResource\Pages;
use App\Models\Feedback;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class FeedbackResource extends Resource
{
    protected static ?string $model = Feedback::class;

    protected static ?string $navigationIcon  = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationLabel = 'Feedback';
    protected static ?string $modelLabel      = 'Feedback';
    protected static ?string $pluralModelLabel = 'Feedback';
    protected static ?int    $navigationSort  = 5;

    // Badge con contatore dei non letti
    public static function getNavigationBadge(): ?string
    {
        $count = Feedback::where('is_read', false)->count();
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Feedback')->schema([
                Forms\Components\Select::make('user_id')
                    ->label('Utente')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->nullable(),

                Forms\Components\Select::make('booking_id')
                    ->label('Prenotazione collegata')
                    ->relationship('booking', 'id')
                    ->searchable()
                    ->nullable(),

                Forms\Components\Select::make('type')
                    ->label('Tipo')
                    ->options([
                        'match_feedback' => 'Dopo partita',
                        'general'        => 'Generale',
                        'complaint'      => 'Segnalazione problema',
                    ])
                    ->default('general'),

                Forms\Components\Select::make('rating')
                    ->label('Valutazione (1–5)')
                    ->options([1 => '1 ★', 2 => '2 ★★', 3 => '3 ★★★', 4 => '4 ★★★★', 5 => '5 ★★★★★'])
                    ->nullable(),

                Forms\Components\Toggle::make('is_read')
                    ->label('Letto'),
            ])->columns(3),

            Forms\Components\Section::make('Contenuto')->schema([
                // Mostra il JSON content in modo leggibile
                Forms\Components\Placeholder::make('content_display')
                    ->label('Risposte')
                    ->content(fn ($record) => $record
                        ? collect($record->content ?? [])->map(
                            fn ($item) => "**{$item['question']}**\n{$item['answer']}"
                        )->implode("\n\n")
                        : '—'
                    ),

                Forms\Components\KeyValue::make('metadata')
                    ->label('Metadati extra')
                    ->nullable(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Utente')
                    ->placeholder('Anonimo')
                    ->searchable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'complaint'      => 'danger',
                        'match_feedback' => 'info',
                        default          => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'match_feedback' => 'Dopo partita',
                        'general'        => 'Generale',
                        'complaint'      => 'Problema',
                        default          => $state,
                    }),

                Tables\Columns\TextColumn::make('rating')
                    ->label('Rating')
                    ->formatStateUsing(fn ($state) => $state ? str_repeat('★', $state) : '—')
                    ->placeholder('—'),

                // Prima risposta del content JSON
                Tables\Columns\TextColumn::make('content')
                    ->label('Contenuto')
                    ->formatStateUsing(fn ($state) =>
                        is_array($state) && !empty($state)
                            ? mb_strimwidth($state[0]['answer'] ?? '', 0, 60, '...')
                            : '—'
                    )
                    ->wrap(),

                Tables\Columns\IconColumn::make('is_read')
                    ->label('Letto')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-envelope'),
            ])
            ->filters([
                Tables\Filters\Filter::make('non_letti')
                    ->label('Non letti')
                    ->query(fn ($query) => $query->where('is_read', false))
                    ->default(),

                Tables\Filters\SelectFilter::make('type')
                    ->label('Tipo')
                    ->options([
                        'match_feedback' => 'Dopo partita',
                        'general'        => 'Generale',
                        'complaint'      => 'Problema',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('segna_letto')
                    ->label('Segna letto')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn ($record) => !$record->is_read)
                    ->action(fn ($record) => $record->update(['is_read' => true])),

                Tables\Actions\ViewAction::make(),
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
            'index' => Pages\ListFeedbacks::route('/'),
            'view'  => Pages\ViewFeedback::route('/{record}'),
        ];
    }
}
