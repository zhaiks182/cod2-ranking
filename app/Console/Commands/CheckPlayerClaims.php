<?php

namespace App\Console\Commands;

use App\Models\ChatMessage;
use App\Models\SiteUser;
use Illuminate\Console\Command;

class CheckPlayerClaims extends Command
{
    protected $signature = 'players:check-claims';

    protected $description = 'Confirma reclamos de perfil pendientes cuyo codigo aparecio en el chat del juego';

    public function handle(): void
    {
        $pending = SiteUser::with('pendingClaimPlayer')
            ->whereNotNull('pending_claim_player_id')
            ->where('claim_code_expires_at', '>=', now())
            ->get();

        if ($pending->isEmpty()) {
            return;
        }

        // Mismo margen (20 min) que la expiracion del codigo (15 min) mas
        // holgura, y mismo criterio que DemoMatchResolver: una sola query
        // chica en vez de una por cada reclamo pendiente.
        $recentMessages = ChatMessage::where('occurred_at', '>=', now()->subMinutes(20))->get();

        foreach ($pending as $siteUser) {
            $targetGuid = $siteUser->pendingClaimPlayer->guid;

            $match = $recentMessages->first(
                fn ($m) => $m->guid === $targetGuid && str_contains($m->message, $siteUser->claim_code)
            );

            if ($match) {
                $siteUser->update([
                    'player_id' => $siteUser->pending_claim_player_id,
                    'pending_claim_player_id' => null,
                    'claim_code' => null,
                    'claim_code_expires_at' => null,
                ]);
            }
        }
    }
}
