<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seed del primo grafo operativo: onboarding + menu + prenotazione base.
 *
 * Il grafo è costruito usando i moduli definiti in app/Services/Flow/Modules.
 * Ogni nodo è un'istanza configurata di un modulo, ogni arco collega una
 * porta di output di un nodo a quella di input di un altro.
 *
 * Layout: colonne verticali semantiche (onboarding | menu | prenotazione),
 * riga per profondità. Le posizioni sono indicative, l'editor visuale può
 * riorganizzarle con autolayout.
 */
return new class extends Migration
{
    /** @var array<string,int> mappa chiave logica → id nodo */
    private array $ids = [];

    public function up(): void
    {
        DB::transaction(function () {
            $this->buildOnboarding();
            $this->buildMenu();
            $this->buildPrenotazione();
            $this->buildEdges();
        });
    }

    public function down(): void
    {
        // La migrazione 000001 ha già cascadeOnDelete sugli edges, quindi
        // basta svuotare i nodi creati (se esistono).
        DB::table('flow_nodes')->truncate();
    }

    /* ───────── Costruzione nodi ───────── */

    private function buildOnboarding(): void
    {
        $this->node('trigger_main', 'primo_messaggio', [], ['x' => 0,   'y' => 0], 'Messaggio ricevuto', isEntry: true, entryTrigger: 'first_message');
        $this->node('check_reg',    'utente_registrato', ['richiedi_onboarding_completo' => true], ['x' => 0, 'y' => 120], 'Già registrato?');

        $this->node('ask_nome', 'chiedi_campo', [
            'question'      => "Ciao! Sono il bot de Le Cercle 🎾 Come ti chiami?",
            'save_to'       => 'profile.name',
            'validator'     => 'name',
            'retry_message' => 'Il nome non mi convince, puoi riscriverlo solo in lettere?',
        ], ['x' => -250, 'y' => 260], 'Chiedi nome');

        $this->node('ask_fit', 'invia_bottoni', [
            'text'    => 'Sei tesserato FIT?',
            'buttons' => [
                ['label' => 'Sì, tesserato'],
                ['label' => 'Non sono tesserato'],
            ],
        ], ['x' => -250, 'y' => 400], 'Chiedi FIT');

        $this->node('save_fit_si', 'salva_in_sessione', [
            'assignments' => [['key' => 'profile.is_fit', 'value' => true]],
        ], ['x' => -400, 'y' => 540], 'Marca FIT sì');

        $this->node('save_fit_no', 'salva_in_sessione', [
            'assignments' => [['key' => 'profile.is_fit', 'value' => false]],
        ], ['x' => -100, 'y' => 540], 'Marca FIT no');

        $this->node('ask_classifica', 'chiedi_campo', [
            'question'      => 'Qual è la tua classifica FIT? (es. 4.1, 3.3, NC)',
            'save_to'       => 'profile.fit_rating',
            'validator'     => 'regex',
            'pattern'       => '^(4\\.[1-6]|3\\.[1-5]|2\\.[1-8]|1\\.[1-5]|NC)$',
            'retry_message' => 'Classifica non valida. Formati accettati: 4.1 / 3.3 / NC',
        ], ['x' => -400, 'y' => 680], 'Chiedi classifica');

        $this->node('ask_livello', 'invia_bottoni', [
            'text'    => 'Come giochi?',
            'buttons' => [
                ['label' => 'Neofita'],
                ['label' => 'Dilettante'],
                ['label' => 'Avanzato'],
            ],
        ], ['x' => -100, 'y' => 680], 'Chiedi livello');

        $this->node('save_liv_neo', 'salva_in_sessione', [
            'assignments' => [['key' => 'profile.self_level', 'value' => 'neofita']],
        ], ['x' => -200, 'y' => 820], 'Salva neofita');

        $this->node('save_liv_dil', 'salva_in_sessione', [
            'assignments' => [['key' => 'profile.self_level', 'value' => 'dilettante']],
        ], ['x' => -100, 'y' => 820], 'Salva dilettante');

        $this->node('save_liv_ava', 'salva_in_sessione', [
            'assignments' => [['key' => 'profile.self_level', 'value' => 'avanzato']],
        ], ['x' => 0, 'y' => 820], 'Salva avanzato');

        $this->node('ask_eta', 'chiedi_campo', [
            'question'      => 'Quanti anni hai?',
            'save_to'       => 'profile.age',
            'validator'     => 'integer',
            'min'           => 5,
            'max'           => 99,
            'retry_message' => 'Inserisci un\'età valida tra 5 e 99.',
        ], ['x' => -250, 'y' => 960], 'Chiedi età');

        $this->node('ask_slot', 'invia_bottoni', [
            'text'    => 'Quando preferisci giocare?',
            'buttons' => [
                ['label' => 'Mattina'],
                ['label' => 'Pomeriggio'],
                ['label' => 'Sera'],
            ],
        ], ['x' => -250, 'y' => 1100], 'Chiedi fascia');

        $this->node('save_slot_mat', 'salva_in_sessione', [
            'assignments' => [['key' => 'profile.slot', 'value' => 'mattina']],
        ], ['x' => -400, 'y' => 1240], 'Salva mattina');

        $this->node('save_slot_pom', 'salva_in_sessione', [
            'assignments' => [['key' => 'profile.slot', 'value' => 'pomeriggio']],
        ], ['x' => -250, 'y' => 1240], 'Salva pomeriggio');

        $this->node('save_slot_ser', 'salva_in_sessione', [
            'assignments' => [['key' => 'profile.slot', 'value' => 'sera']],
        ], ['x' => -100, 'y' => 1240], 'Salva sera');

        $this->node('save_profile', 'salva_profilo', [], ['x' => -250, 'y' => 1380], 'Salva su DB');

        $this->node('welcome_done', 'invia_testo', [
            'text' => 'Perfetto {profile.name}, sei a bordo! 🎾 Ora posso aiutarti a prenotare un campo.',
        ], ['x' => -250, 'y' => 1520], 'Benvenuto finale');
    }

    private function buildMenu(): void
    {
        $this->node('menu_main', 'invia_bottoni', [
            'text'    => 'Ciao {profile.name}! Cosa vuoi fare?',
            'buttons' => [
                ['label' => 'Prenota un campo'],
                ['label' => 'Trovami avversario'],
                ['label' => 'Sparapalline'],
            ],
        ], ['x' => 250, 'y' => 260], 'Menu principale');

        $this->node('trigger_menu_kw', 'trigger_keyword', [
            'keyword' => 'menu',
        ], ['x' => 500, 'y' => 0], 'Keyword: menu', isEntry: true, entryTrigger: 'keyword:menu');

        $this->node('set_type_avv', 'salva_in_sessione', [
            'assignments' => [['key' => 'booking_type', 'value' => 'con_avversario']],
        ], ['x' => 100, 'y' => 400], 'Tipo: con avversario');

        $this->node('set_type_mm', 'salva_in_sessione', [
            'assignments' => [['key' => 'booking_type', 'value' => 'matchmaking']],
        ], ['x' => 250, 'y' => 400], 'Tipo: matchmaking');

        $this->node('set_type_spara', 'salva_in_sessione', [
            'assignments' => [['key' => 'booking_type', 'value' => 'sparapalline']],
        ], ['x' => 400, 'y' => 400], 'Tipo: sparapalline');
    }

    private function buildPrenotazione(): void
    {
        $this->node('ask_when', 'invia_testo', [
            'text' => 'Quando vuoi giocare? Puoi scrivermi "domani alle 18", "sabato mattina" ecc.',
        ], ['x' => 250, 'y' => 540], 'Chiedi quando');

        $this->node('wait_when', 'attendi_input', [
            'save_to' => 'user_reply',
        ], ['x' => 250, 'y' => 680], 'Attendi data');

        $this->node('parse_when', 'parse_data', [
            'source' => 'user_reply',
        ], ['x' => 250, 'y' => 820], 'Parsa data/ora');

        $this->node('date_ko', 'invia_testo', [
            'text' => 'Non ho capito la data 🤔 Riprova con qualcosa tipo "domani alle 19"',
        ], ['x' => 450, 'y' => 820], 'Data non capita');

        $this->node('check_cal', 'verifica_calendario', [
            'durata_minuti' => 60,
        ], ['x' => 250, 'y' => 960], 'Verifica calendario');

        $this->node('slot_libero', 'invia_testo', [
            'text' => "Lo slot {data.requested_friendly} è libero 🎾 Sto prenotando...",
        ], ['x' => 150, 'y' => 1100], 'Slot libero');

        $this->node('crea_book', 'crea_prenotazione', [
            'status' => 'confirmed',
        ], ['x' => 150, 'y' => 1240], 'Crea booking');

        $this->node('book_ok', 'invia_testo', [
            'text' => 'Fatto ✅ Ti aspetto {data.requested_friendly}!',
        ], ['x' => 150, 'y' => 1380], 'Conferma finale');

        $this->node('book_ko', 'invia_testo', [
            'text' => 'Scusami, non sono riuscito a creare la prenotazione 😔 Riprova tra poco.',
        ], ['x' => 300, 'y' => 1380], 'Errore booking');

        $this->node('slot_occupato', 'invia_testo', [
            'text' => 'Lo slot {data.requested_friendly} è occupato. Provo a proporti un\'alternativa a breve.',
        ], ['x' => 400, 'y' => 1100], 'Slot occupato');

        $this->node('fine', 'fine_flusso', [], ['x' => 250, 'y' => 1660], 'Fine');
    }

    private function buildEdges(): void
    {
        // ── Trigger → check registrazione
        $this->edge('trigger_main', 'out', 'check_reg');

        // ── Registrato: sì → menu, no → onboarding
        $this->edge('check_reg', 'si', 'menu_main');
        $this->edge('check_reg', 'no', 'ask_nome');

        // ── Onboarding
        $this->edge('ask_nome', 'ok', 'ask_fit');
        $this->edge('ask_fit', 'btn_0', 'save_fit_si');
        $this->edge('ask_fit', 'btn_1', 'save_fit_no');
        $this->edge('ask_fit', 'fallback', 'ask_fit');

        $this->edge('save_fit_si', 'out', 'ask_classifica');
        $this->edge('save_fit_no', 'out', 'ask_livello');

        $this->edge('ask_classifica', 'ok', 'ask_eta');

        $this->edge('ask_livello', 'btn_0', 'save_liv_neo');
        $this->edge('ask_livello', 'btn_1', 'save_liv_dil');
        $this->edge('ask_livello', 'btn_2', 'save_liv_ava');
        $this->edge('ask_livello', 'fallback', 'ask_livello');
        $this->edge('save_liv_neo', 'out', 'ask_eta');
        $this->edge('save_liv_dil', 'out', 'ask_eta');
        $this->edge('save_liv_ava', 'out', 'ask_eta');

        $this->edge('ask_eta', 'ok', 'ask_slot');

        $this->edge('ask_slot', 'btn_0', 'save_slot_mat');
        $this->edge('ask_slot', 'btn_1', 'save_slot_pom');
        $this->edge('ask_slot', 'btn_2', 'save_slot_ser');
        $this->edge('ask_slot', 'fallback', 'ask_slot');
        $this->edge('save_slot_mat', 'out', 'save_profile');
        $this->edge('save_slot_pom', 'out', 'save_profile');
        $this->edge('save_slot_ser', 'out', 'save_profile');

        $this->edge('save_profile', 'ok', 'welcome_done');
        $this->edge('save_profile', 'errore', 'welcome_done');
        $this->edge('welcome_done', 'out', 'menu_main');

        // ── Menu → prenotazione
        $this->edge('trigger_menu_kw', 'out', 'menu_main');
        $this->edge('menu_main', 'btn_0', 'set_type_avv');
        $this->edge('menu_main', 'btn_1', 'set_type_mm');
        $this->edge('menu_main', 'btn_2', 'set_type_spara');
        $this->edge('menu_main', 'fallback', 'menu_main');

        $this->edge('set_type_avv',   'out', 'ask_when');
        $this->edge('set_type_mm',    'out', 'ask_when');
        $this->edge('set_type_spara', 'out', 'ask_when');

        // ── Prenotazione: chiedi quando → parse → calendario → crea
        $this->edge('ask_when',   'out', 'wait_when');
        $this->edge('wait_when',  'out', 'parse_when');
        $this->edge('parse_when', 'ok',     'check_cal');
        $this->edge('parse_when', 'errore', 'date_ko');
        $this->edge('date_ko',    'out',    'wait_when');

        $this->edge('check_cal', 'libero',   'slot_libero');
        $this->edge('check_cal', 'occupato', 'slot_occupato');
        $this->edge('check_cal', 'errore',   'book_ko');

        $this->edge('slot_libero', 'out', 'crea_book');
        $this->edge('crea_book',   'ok',     'book_ok');
        $this->edge('crea_book',   'errore', 'book_ko');

        $this->edge('slot_occupato', 'out', 'fine');
        $this->edge('book_ok',       'out', 'fine');
        $this->edge('book_ko',       'out', 'fine');
    }

    /* ───────── Helper ───────── */

    private function node(
        string $key,
        string $moduleKey,
        array  $config,
        array  $position,
        ?string $label = null,
        bool    $isEntry = false,
        ?string $entryTrigger = null,
    ): void {
        $id = DB::table('flow_nodes')->insertGetId([
            'module_key'    => $moduleKey,
            'label'         => $label,
            'config'        => json_encode($config),
            'position'      => json_encode($position),
            'is_entry'      => $isEntry,
            'entry_trigger' => $entryTrigger,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
        $this->ids[$key] = $id;
    }

    private function edge(string $fromKey, string $fromPort, string $toKey, string $toPort = 'in'): void
    {
        if (!isset($this->ids[$fromKey]) || !isset($this->ids[$toKey])) {
            throw new \RuntimeException("seed edge: nodo mancante {$fromKey} → {$toKey}");
        }

        DB::table('flow_edges')->insert([
            'from_node_id' => $this->ids[$fromKey],
            'from_port'    => $fromPort,
            'to_node_id'   => $this->ids[$toKey],
            'to_port'      => $toPort,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }
};
