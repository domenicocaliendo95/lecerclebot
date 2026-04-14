<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix: assicura che il profilo venga salvato alla fine dell'onboarding
 * sia via path DB-driven (input_rules con side_effect) sia via on_enter_actions.
 *
 * Aggiunge side_effect='save_profile' alla rule di ONBOARD_SLOT_PREF
 * come sicurezza ridondante con l'on_enter_action di ONBOARD_COMPLETO.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Aggiorna ONBOARD_SLOT_PREF: aggiungi side_effect alla rule
        $state = DB::table('bot_flow_states')->where('state', 'ONBOARD_SLOT_PREF')->first();
        if ($state && $state->input_rules) {
            $rules = json_decode($state->input_rules, true);
            if (is_array($rules)) {
                foreach ($rules as &$rule) {
                    $rule['side_effect'] = 'save_profile';
                }
                unset($rule);
                DB::table('bot_flow_states')
                    ->where('state', 'ONBOARD_SLOT_PREF')
                    ->update(['input_rules' => json_encode($rules)]);
            }
        }

        // Assicura anche che ONBOARD_COMPLETO abbia on_enter_actions = ['save_profile']
        DB::table('bot_flow_states')
            ->where('state', 'ONBOARD_COMPLETO')
            ->whereNull('on_enter_actions')
            ->update(['on_enter_actions' => json_encode(['save_profile'])]);
    }

    public function down(): void
    {
        // Rimuovi side_effect dalle rules di ONBOARD_SLOT_PREF
        $state = DB::table('bot_flow_states')->where('state', 'ONBOARD_SLOT_PREF')->first();
        if ($state && $state->input_rules) {
            $rules = json_decode($state->input_rules, true);
            if (is_array($rules)) {
                foreach ($rules as &$rule) {
                    unset($rule['side_effect']);
                }
                unset($rule);
                DB::table('bot_flow_states')
                    ->where('state', 'ONBOARD_SLOT_PREF')
                    ->update(['input_rules' => json_encode($rules)]);
            }
        }
    }
};
