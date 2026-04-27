<?php

namespace App\Services\Flow\Modules;

use App\Models\Booking;
use App\Models\PricingRule;
use App\Services\CalendarService;
use App\Services\Flow\FlowContext;
use App\Services\Flow\Module;
use App\Services\Flow\ModuleMeta;
use App\Services\Flow\ModuleResult;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Crea una prenotazione: Booking DB + evento su Google Calendar.
 *
 * Legge da session.data: requested_date, requested_time,
 * requested_duration_minutes, booking_type, opponent_user_id, opponent_name,
 * payment_method, editing_booking_id (se modifica).
 *
 * Se `editing_booking_id` è settato, cancella prima la vecchia prenotazione
 * (DB + gcal) e ne crea una nuova.
 *
 * Porte: "ok" con booking_id salvato in session.data, "errore" se manca data
 * o fallisce la chiamata al calendario.
 */
class CreaPrenotazioneModule extends Module
{
    public function meta(): ModuleMeta
    {
        return new ModuleMeta(
            key: 'crea_prenotazione',
            label: 'Crea prenotazione',
            category: 'azione',
            description: 'Crea record Booking + evento Google Calendar leggendo i dati dalla sessione (data, orario, durata, avversario).',
            configSchema: [
                'status' => [
                    'type'    => 'select',
                    'label'   => 'Stato iniziale',
                    'default' => 'confirmed',
                    'options' => [
                        ['value' => 'confirmed',     'label' => 'Confermata'],
                        ['value' => 'pending_match', 'label' => 'In attesa match'],
                    ],
                ],
            ],
            icon: 'calendar-plus',
        );
    }

    public function outputs(): array
    {
        return [
            'ok'     => 'Creata',
            'errore' => 'Errore',
        ];
    }

    public function execute(FlowContext $ctx): ModuleResult
    {
        $user = $ctx->user;
        if ($user === null) {
            return ModuleResult::next('errore');
        }

        $date = $ctx->get('requested_date');
        $time = $ctx->get('requested_time');
        if (empty($date) || empty($time)) {
            return ModuleResult::next('errore');
        }

        $duration    = (int) ($ctx->get('requested_duration_minutes') ?? 60);
        $bookingType = (string) ($ctx->get('booking_type') ?? 'con_avversario');
        $payment     = (string) ($ctx->get('payment_method') ?? 'in_loco');
        $opponentId  = $ctx->get('opponent_user_id');
        $opponentName= $ctx->get('opponent_name');
        $status      = (string) $this->cfg('status', 'confirmed');

        try {
            // Eventuale modifica: cancella vecchia booking prima
            $editingId = $ctx->get('editing_booking_id');
            if ($editingId) {
                $old = Booking::find($editingId);
                if ($old) {
                    if ($old->gcal_event_id) {
                        try { app(CalendarService::class)->deleteEvent($old->gcal_event_id); } catch (\Throwable) {}
                    }
                    $old->update(['status' => 'cancelled']);
                }
                $ctx->session->mergeData(['editing_booking_id' => null, 'selected_booking_id' => null]);
            }

            $startDT = Carbon::parse("{$date} {$time}", 'Europe/Rome');
            $endDT   = $startDT->copy()->addMinutes($duration);

            $typeLabels = [
                'con_avversario' => 'Partita singolo',
                'matchmaking'    => 'Partita (matchmaking)',
                'sparapalline'   => 'Noleggio sparapalline',
            ];
            $typeLabel = $typeLabels[$bookingType] ?? 'Prenotazione campo';

            $summary = ($bookingType === 'con_avversario' && $opponentName)
                ? "Partita singolo - {$user->name} vs {$opponentName}"
                : "{$typeLabel} - {$user->name}";

            $descLines = [
                "Giocatore: {$user->name}",
                "Telefono: {$ctx->phone}",
                "Tipo: {$typeLabel}",
                "Pagamento: {$payment}",
            ];
            if ($opponentName) {
                $descLines[] = "Avversario: {$opponentName}";
            }
            $descLines[] = 'Prenotato via: WhatsApp Bot';

            $price = PricingRule::getPriceForSlot($startDT, $duration);

            $gcalEvent = app(CalendarService::class)->createEvent(
                summary:     $summary,
                description: implode("\n", $descLines),
                startTime:   $startDT,
                endTime:     $endDT,
            );

            $booking = Booking::create([
                'player1_id'        => $user->id,
                'player2_id'        => $opponentId,
                'player2_name_text' => $opponentId ? null : $opponentName,
                'booking_date'      => $startDT->format('Y-m-d'),
                'start_time'        => $startDT->format('H:i:s'),
                'end_time'          => $endDT->format('H:i:s'),
                'price'             => $price,
                'is_peak'           => $startDT->hour >= 18,
                'status'            => $status,
                'gcal_event_id'     => $gcalEvent->getId(),
            ]);

            // Notifica admin
            $this->notifyAdmin("📋 Nuova prenotazione!\n{$user->name}" .
                ($opponentName ? " vs {$opponentName}" : '') .
                "\n{$startDT->locale('it')->isoFormat('ddd D MMM')} {$startDT->format('H:i')}-{$endDT->format('H:i')}" .
                "\n€{$price} ({$payment})");

            return ModuleResult::next('ok')->withData([
                'last_booking_id'         => $booking->id,
                'last_booking_price'      => $price,
                'opponent_user_id'        => null,
                'opponent_name'           => null,
                'opponent_phone'          => null,
                'opponent_search_results' => null,
                'opponent_pending_confirm'=> null,
            ]);
        } catch (\Throwable $e) {
            Log::error('crea_prenotazione failed', [
                'phone' => $ctx->phone,
                'error' => $e->getMessage(),
            ]);
            return ModuleResult::next('errore');
        }
    }

    private function notifyAdmin(string $message): void
    {
        try {
            $adminPhone = \App\Models\BotSetting::get('admin_phone');
            if (!$adminPhone) return;
            $adapter = app(\App\Services\Channel\ChannelRegistry::class)->get('whatsapp');
            $adapter?->sendText((string) $adminPhone, $message);
        } catch (\Throwable) {}
    }
}
