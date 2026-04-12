<?php

namespace App\Providers;

use App\Models\BotSetting;
use Illuminate\Support\ServiceProvider;

/**
 * Sovrascrive i valori di config() con quelli salvati in bot_settings
 * dall'admin panel. Permette di modificare credenziali e parametri
 * senza toccare il file .env.
 *
 * L'override avviene solo se il valore è presente in bot_settings.
 * Altrimenti il valore di .env/config resta valido.
 */
class SettingsOverrideProvider extends ServiceProvider
{
    /**
     * Mappa: chiave bot_settings → chiave config().
     */
    private const OVERRIDES = [
        'env_whatsapp_phone_number_id' => 'services.whatsapp.phone_id',
        'env_whatsapp_verify_token'    => 'services.whatsapp.verify_token',
        'env_whatsapp_token'           => 'services.whatsapp.api_token',
        'env_whatsapp_api_version'     => 'services.whatsapp.api_version',
        'env_gemini_model'             => 'services.gemini.model',
        'env_gemini_key'               => 'services.gemini.api_key',
        'env_gemini_timeout'           => 'services.gemini.timeout',
        'env_google_calendar_id'       => 'services.google_calendar.calendar_id',
        'env_app_timezone'             => 'app.timezone',
    ];

    public function boot(): void
    {
        // Non eseguire durante le migrazioni o i comandi console che non hanno il DB
        if (!$this->app->runningInConsole() || $this->app->runningUnitTests()) {
            $this->applyOverrides();
        } else {
            // In console: prova, ma ignora errori se il DB non è ancora pronto
            try {
                $this->applyOverrides();
            } catch (\Throwable) {
                // DB non disponibile (migrate, db:seed, ecc.) — ignora silenziosamente
            }
        }
    }

    private function applyOverrides(): void
    {
        foreach (self::OVERRIDES as $settingKey => $configKey) {
            $value = BotSetting::get($settingKey);

            if ($value !== null && $value !== '') {
                config([$configKey => $value]);
            }
        }
    }
}
