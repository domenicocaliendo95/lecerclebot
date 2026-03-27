<?php

namespace App\Services\Bot;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Gestisce la persistenza del profilo utente dal bot.
 *
 * Responsabilità unica: tradurre i dati raccolti dal bot
 * nel modello User del database.
 */
class UserProfileService
{
    /**
     * Mappa di conversione livello → ELO iniziale stimato.
     */
    private const LEVEL_ELO_MAP = [
        'neofita'    => 1000,
        'dilettante' => 1200,
        'avanzato'   => 1400,
    ];

    /**
     * Mappa di conversione classifica FIT → ELO iniziale stimato.
     */
    private const FIT_ELO_MAP = [
        'NC'  => 1100,
        '4.6' => 1050, '4.5' => 1100, '4.4' => 1150,
        '4.3' => 1200, '4.2' => 1250, '4.1' => 1300,
        '3.5' => 1350, '3.4' => 1400, '3.3' => 1450,
        '3.2' => 1500, '3.1' => 1550,
        '2.8' => 1600, '2.7' => 1650, '2.6' => 1700,
        '2.5' => 1750, '2.4' => 1800, '2.3' => 1850,
        '2.2' => 1900, '2.1' => 1950,
        '1.1' => 2100,
    ];

    /**
     * Crea o aggiorna un utente a partire dai dati raccolti dal bot.
     */
    public function saveFromBot(string $phone, array $profile): ?User
    {
        try {
            $name = $profile['name'] ?? 'Giocatore';
            $elo  = $this->estimateElo($profile);

            return User::updateOrCreate(
                ['phone' => $phone],
                [
                    'name'            => $name,
                    'email'           => $this->generatePlaceholderEmail($phone),
                    'password'        => bcrypt(Str::random(32)),
                    'is_fit'          => $profile['is_fit'] ?? false,
                    'fit_rating'      => $profile['fit_rating'] ?? null,
                    'self_level'      => $profile['self_level'] ?? null,
                    'age'             => $profile['age'] ?? null,
                    'elo_rating'      => $elo,
                    'preferred_slots' => isset($profile['slot']) ? [$profile['slot']] : [],
                ]
            );
        } catch (\Throwable $e) {
            Log::error('UserProfileService: save failed', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Stima l'ELO iniziale basandosi su classifica FIT o livello autodichiarato.
     */
    private function estimateElo(array $profile): int
    {
        // Priorità alla classifica FIT
        if (!empty($profile['fit_rating'])) {
            return self::FIT_ELO_MAP[$profile['fit_rating']] ?? 1200;
        }

        if (!empty($profile['self_level'])) {
            return self::LEVEL_ELO_MAP[$profile['self_level']] ?? 1200;
        }

        return 1200; // Default dal modello di progetto
    }

    /**
     * Genera un'email placeholder per utenti registrati via WhatsApp.
     */
    private function generatePlaceholderEmail(string $phone): string
    {
        // Usa solo le ultime cifre per privacy
        $suffix = substr(preg_replace('/\D/', '', $phone), -10);

        return "wa_{$suffix}@lecercleclub.bot";
    }
}
