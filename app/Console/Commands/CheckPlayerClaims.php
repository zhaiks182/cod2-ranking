<?php

namespace App\Console\Commands;

use App\Models\ChatMessage;
use App\Models\SiteUser;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;

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

            if (! $match) {
                continue;
            }

            try {
                $siteUser->update([
                    'player_id' => $siteUser->pending_claim_player_id,
                    'pending_claim_player_id' => null,
                    'claim_code' => null,
                    'claim_code_expires_at' => null,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Dos SiteUser distintos pueden tener un reclamo pendiente sobre el
                // MISMO jugador todavia sin confirmar (PlayerClaimController::store()
                // solo bloquea contra un jugador ya confirmado a otra cuenta, no
                // contra otro reclamo pendiente) -- si el codigo de ambos aparece en
                // el chat dentro de la misma corrida, el segundo update() choca con
                // el unique real de site_users.player_id. No dejar que esto aborte
                // el resto de la corrida -- mismo patron que
                // HostedServerPortAllocator::allocate(). El reclamo perdedor queda
                // pendiente tal cual (sin tocar), se resuelve solo cuando expire.
                continue;
            }
        }
    }
}
