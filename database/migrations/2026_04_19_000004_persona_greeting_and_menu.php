<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aggiorna i messaggi di saluto per usare la persona tennista ({data.persona}).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Saluto onboarding (primo contatto)
        DB::table('flow_nodes')->where('label', 'Chiedi nome')->update([
            'config' => json_encode([
                'question'      => "Ciao! Sono {data.persona}, il tuo assistente virtuale de Le Cercle Tennis Club 🎾\nCome ti chiami?",
                'save_to'       => 'profile.name',
                'validator'     => 'name',
                'retry_message' => 'Il nome non mi convince, puoi riscriverlo solo in lettere?',
            ]),
        ]);

        // Menu ritorno (utente già registrato)
        DB::table('flow_nodes')->where('label', 'Menu principale')->update([
            'config' => json_encode([
                'text'    => "Bentornato {user.name}! Sono {data.persona} 🎾 Cosa vuoi fare?",
                'buttons' => [
                    ['label' => 'Prenota un campo'],
                    ['label' => 'Trovami avversario'],
                    ['label' => 'Sparapalline'],
                ],
            ]),
        ]);
    }

    public function down(): void {}
};
