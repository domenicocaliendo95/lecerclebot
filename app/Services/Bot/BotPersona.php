<?php

namespace App\Services\Bot;

/**
 * Gestisce l'identità del bot: ad ogni nuova conversazione
 * si presenta con il nome di un tennista famoso.
 */
class BotPersona
{
    /**
     * Tennisti famosi da usare come "avatar" del bot.
     * Solo il primo nome per un tono amichevole.
     */
    private const PERSONAS = [
        'Jannik',
        'Carlos',
        'Daniil',
        'Rafa',
        'Roger',
        'Novak',
        'Matteo',
        'Lorenzo',
        'Flavia',
        'Francesca',
        'Sara',
        'Jasmine',
        'Fabio',
        'Adriano',
        'Simone',
        'Roberta',
        'Andre',
        'Serena',
        'Stefanos',
        'Alexander',
    ];

    /**
     * Sceglie un nome casuale per una nuova sessione.
     */
    public static function pickRandom(): string
    {
        return self::PERSONAS[array_rand(self::PERSONAS)];
    }

    /**
     * Costruisce il saluto iniziale per un utente nuovo.
     */
    public static function greetingNew(string $persona): string
    {
        return "Ciao! Sono {$persona}, il tuo assistente virtuale per il circolo Le Cercle Tennis Club! 🎾\n\n"
            . "Per prenotare un campo ho bisogno di conoscerti un po'. Come ti chiami?";
    }

    /**
     * Costruisce il saluto per un utente già registrato.
     */
    public static function greetingReturning(string $persona, string $userName): string
    {
        return "Ciao {$userName}! Sono {$persona}, il tuo assistente oggi! 🎾\n\n"
            . "Cosa vuoi fare?";
    }
}
