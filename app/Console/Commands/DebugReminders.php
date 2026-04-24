<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\BotSetting;
use App\Models\FlowNode;
use Carbon\Carbon;
use Illuminate\Console\Command;

class DebugReminders extends Command
{
    protected $signature = 'debug:reminders';
    protected $description = 'Mostra lo stato completo dei reminder per debug';

    public function handle(): int
    {
        $now = Carbon::now('Europe/Rome');
        $this->line("Ora server: {$now->toIso8601String()}");
        $this->line("");

        // 1. Impostazioni
        $settings = BotSetting::get('reminders');
        $this->info("── Impostazioni reminders ──");
        if (!$settings) {
            $this->error("bot_settings['reminders'] NON ESISTE!");
            return self::FAILURE;
        }
        $this->line("Enabled: " . ($settings['enabled'] ?? false ? 'SÌ' : 'NO'));
        $slots = $settings['slots'] ?? [];
        if (empty($slots)) {
            $this->warn("Nessuno slot configurato.");
        }
        foreach ($slots as $i => $slot) {
            $hours = $slot['hours_before'] ?? '?';
            $enabled = $slot['enabled'] ?? false;
            $nodeId = $slot['flow_node_id'] ?? 'MANCANTE';
            $nodeExists = is_numeric($nodeId) ? (FlowNode::find($nodeId) ? 'OK' : 'NODO NON TROVATO!') : '-';
            $this->line("  Slot {$i}: {$hours}h prima | enabled: " . ($enabled ? 'sì' : 'no') . " | node_id: {$nodeId} ({$nodeExists})");
        }

        // 2. Prenotazioni future
        $this->info("\n── Prenotazioni future ──");
        $bookings = Booking::where('status', 'confirmed')
            ->where('booking_date', '>=', $now->format('Y-m-d'))
            ->whereNotNull('player1_id')
            ->orderBy('booking_date')
            ->orderBy('start_time')
            ->with('player1')
            ->limit(10)
            ->get();

        if ($bookings->isEmpty()) {
            $this->warn("Nessuna prenotazione futura confermata.");
        }

        foreach ($bookings as $b) {
            $startFull = $b->booking_date->format('Y-m-d') . ' ' . $b->start_time;
            $startDT = Carbon::parse($startFull, 'Europe/Rome');
            $diffHours = round($now->diffInMinutes($startDT, false) / 60, 1);
            $sent = $b->reminders_sent ? json_encode($b->reminders_sent) : 'nessuno';
            $player = $b->player1?->name ?? '?';
            $phone = $b->player1?->phone ?? '?';

            $this->line("  #{$b->id} | {$startDT->format('d/m H:i')} | tra {$diffHours}h | {$player} ({$phone}) | sent: {$sent}");

            // Verifica se rientra in qualche finestra
            foreach ($slots as $slot) {
                if (!($slot['enabled'] ?? false)) continue;
                $h = (int) ($slot['hours_before'] ?? 0);
                $windowStart = $now->copy()->addHours($h)->subMinutes(8);
                $windowEnd = $now->copy()->addHours($h)->addMinutes(8);
                $inWindow = $startDT->between($windowStart, $windowEnd);
                $alreadySent = ($b->reminders_sent[(string)$h] ?? null) !== null;
                if ($inWindow && !$alreadySent) {
                    $this->line("    → ✅ DOVREBBE PARTIRE per slot {$h}h (finestra {$windowStart->format('H:i')}-{$windowEnd->format('H:i')})");
                } elseif ($inWindow && $alreadySent) {
                    $this->line("    → ⏩ Già inviato per slot {$h}h");
                }
            }
        }

        // 3. Post-match
        $this->info("\n── Post-partita ──");
        $postMatch = BotSetting::get('post_match');
        if ($postMatch) {
            $this->line("Enabled: " . (($postMatch['enabled'] ?? false) ? 'sì' : 'no'));
            $this->line("Hours after: " . ($postMatch['hours_after'] ?? '?'));
            $nodeId = $postMatch['flow_node_id'] ?? 'MANCANTE';
            $nodeExists = is_numeric($nodeId) ? (FlowNode::find($nodeId) ? 'OK' : 'NON TROVATO!') : '-';
            $this->line("Node: {$nodeId} ({$nodeExists})");
        } else {
            $this->warn("bot_settings['post_match'] non configurato.");
        }

        return self::SUCCESS;
    }
}
