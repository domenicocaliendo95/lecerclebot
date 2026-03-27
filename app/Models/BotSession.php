<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sessione del bot WhatsApp.
 *
 * Gestisce lo stato della conversazione, la history,
 * il profilo raccolto e il persona assegnato.
 *
 * @property int    $id
 * @property string $phone
 * @property string $state
 * @property array  $data
 */
class BotSession extends Model
{
    protected $fillable = ['phone', 'state', 'data'];

    protected $casts = [
        'data' => 'array',
    ];

    private const MAX_HISTORY_LENGTH = 40;

    /* ───────── Accessori dati ───────── */

    /**
     * Restituisce il nome del persona (tennista) assegnato alla sessione.
     */
    public function persona(): string
    {
        return $this->data['persona'] ?? 'il tuo assistente';
    }

    /**
     * Restituisce il profilo utente raccolto durante l'onboarding.
     */
    public function profile(): array
    {
        return $this->data['profile'] ?? [];
    }

    /**
     * Restituisce la conversation history.
     */
    public function history(): array
    {
        return $this->data['history'] ?? [];
    }

    /**
     * Legge un valore specifico dai dati della sessione.
     */
    public function getData(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }

    /* ───────── Mutatori dati ───────── */

    /**
     * Merge dati nel campo `data` della sessione.
     */
    public function mergeData(array $newData): void
    {
        $current = $this->data ?? [];
        $this->data = array_merge($current, $newData);
        $this->save();
    }

    /**
     * Merge dati nel sotto-campo `profile`.
     */
    public function mergeProfile(array $profileData): void
    {
        $current = $this->data ?? [];
        $current['profile'] = array_merge($current['profile'] ?? [], $profileData);
        $this->data = $current;
        $this->save();
    }

    /**
     * Aggiunge un messaggio alla conversation history.
     * Tronca automaticamente se supera il limite.
     */
    public function appendHistory(string $role, string $content): void
    {
        $current = $this->data ?? [];
        $history = $current['history'] ?? [];

        $history[] = [
            'role'    => $role,
            'content' => $content,
        ];

        // Tronca mantenendo i messaggi più recenti
        if (count($history) > self::MAX_HISTORY_LENGTH) {
            $history = array_slice($history, -self::MAX_HISTORY_LENGTH);
        }

        $current['history'] = $history;
        $this->data = $current;
        $this->save();
    }

    /**
     * Resetta la sessione per una nuova conversazione.
     * Mantiene il profilo ma resetta stato e history.
     */
    public function resetConversation(string $newState, string $persona): void
    {
        $profile = $this->profile();

        $this->update([
            'state' => $newState,
            'data'  => [
                'persona' => $persona,
                'history' => [],
                'profile' => $profile,
            ],
        ]);
    }
}
