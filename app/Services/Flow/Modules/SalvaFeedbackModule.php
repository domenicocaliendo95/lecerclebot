<?php

namespace App\Services\Flow\Modules;

use App\Models\Feedback;
use App\Services\Flow\FlowContext;
use App\Services\Flow\Module;
use App\Services\Flow\ModuleMeta;
use App\Services\Flow\ModuleResult;
use Illuminate\Support\Facades\Log;

/**
 * Salva un feedback nella tabella feedbacks.
 *
 * Legge da session.data:
 *   - feedback_rating: int (1-5)
 *   - feedback_comment: string (opzionale)
 *   - result_booking_id o selected_booking_id: int (opzionale)
 *
 * Porte: ok, errore
 */
class SalvaFeedbackModule extends Module
{
    public function meta(): ModuleMeta
    {
        return new ModuleMeta(
            key: 'salva_feedback',
            label: 'Salva feedback',
            category: 'azione',
            description: 'Persiste il feedback (rating + commento) nella tabella feedbacks, collegato a utente e prenotazione.',
            configSchema: [
                'type' => [
                    'type'    => 'string',
                    'label'   => 'Tipo feedback',
                    'default' => 'post_match',
                    'help'    => 'Es. post_match, experience, facility',
                ],
            ],
            icon: 'star',
        );
    }

    public function outputs(): array
    {
        return [
            'ok'     => 'Salvato',
            'errore' => 'Errore',
        ];
    }

    public function execute(FlowContext $ctx): ModuleResult
    {
        $rating    = (int) ($ctx->get('feedback_rating') ?? 0);
        $comment   = (string) ($ctx->get('feedback_comment') ?? '');
        $bookingId = $ctx->get('result_booking_id') ?? $ctx->get('selected_booking_id');
        $type      = (string) $this->cfg('type', 'post_match');

        if ($rating < 1) {
            return ModuleResult::next('errore');
        }

        try {
            Feedback::create([
                'user_id'    => $ctx->user?->id,
                'booking_id' => $bookingId ? (int) $bookingId : null,
                'type'       => $type,
                'rating'     => $rating,
                'content'    => $comment !== '' ? ['text' => $comment] : null,
                'is_read'    => false,
            ]);

            return ModuleResult::next('ok')->withData([
                'feedback_rating'  => null,
                'feedback_comment' => null,
            ]);
        } catch (\Throwable $e) {
            Log::error('salva_feedback failed', ['error' => $e->getMessage()]);
            return ModuleResult::next('errore');
        }
    }
}
