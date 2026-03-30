<?php
namespace App\Services\Bot;

class BotPersona
{
    private const PERSONAS = [
        'Jannik','Carlos','Daniil','Rafa','Roger','Novak',
        'Matteo','Lorenzo','Flavia','Francesca','Sara','Jasmine',
        'Fabio','Adriano','Simone','Roberta','Andre','Serena',
        'Stefanos','Alexander',
    ];

    public static function pickRandom(): string
    {
        return self::PERSONAS[array_rand(self::PERSONAS)];
    }

    public static function greetingNew(string $persona): string
    {
        return "Ciao! Sono {$persona}, il tuo assistente virtuale del circolo Le Cercle Tennis Club di San Gennaro Vesuviano. 🎾\n\n"
            . "Posso aiutarti a:\n"
            . "• Prenotare un campo (con avversario o sparapalline)\n"
            . "• Trovare un avversario del tuo livello\n"
            . "• Gestire le tue prenotazioni\n\n"
            . "Per iniziare ho bisogno di registrarti. Dimmi il tuo nome!";
    }

    public static function greetingReturning(string $persona, string $userName): string
    {
        return "Bentornato {$userName}! Sono {$persona}, il tuo assistente di oggi. 🎾\n\n"
            . "Cosa vuoi fare? Puoi anche scrivere:\n"
            . "• \"prenotazioni\" per gestire le tue prenotazioni\n"
            . "• \"profilo\" per modificare i tuoi dati\n"
            . "• \"menu\" per tornare qui in qualsiasi momento";
    }
}
