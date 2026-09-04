<?php

namespace App\Http\Controllers;

use App\Models\Clan;
use App\Models\ClanInvitation;
use App\Models\ClanMember;
use App\Models\PlayerAlias;
use App\Models\Season;
use App\Models\SiteUser;
use App\Support\ClanLogo;
use App\Support\ClanStatsCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Modulo de clanes (2026-09-03) -- identidad + membresia + estadisticas
 * reales de los miembros. Ver docs/superpowers/specs/2026-09-03-clanes-design.md.
 */
class ClanController extends Controller
{
    private const URL_SAFE_TAG = ['required', 'string', 'max:15', 'regex:/^[A-Za-z0-9_-]+$/'];

    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $clans = Clan::withCount('members')
            ->when($q !== '', fn ($query) => $query->where(
                fn ($sub) => $sub->where('name', 'like', "%{$q}%")->orWhere('tag', 'like', "%{$q}%")
            ))
            ->orderByDesc('members_count')
            ->paginate(20)
            ->withQueryString();

        return view('clans.index', compact('clans', 'q'));
    }

    public function create()
    {
        $siteUser = Auth::guard('site')->user();

        if (! $siteUser->player_id) {
            return redirect()->route('account.show')->withErrors(['clan' => __('Necesitás reclamar tu perfil de jugador antes de crear un clan.')]);
        }
        if ($siteUser->clanMembership) {
            return redirect()->route('clans.show', $siteUser->clanMembership->clan)->with('status', __('Ya pertenecés a un clan.'));
        }

        return view('clans.create');
    }

    public function store(Request $request)
    {
        $siteUser = Auth::guard('site')->user();
        abort_unless($siteUser->player_id, 403);

        if ($siteUser->clanMembership) {
            return back()->withErrors(['clan' => __('Ya pertenecés a un clan.')]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', 'unique:clans,name'],
            'tag' => [...self::URL_SAFE_TAG, 'unique:clans,tag'],
            'description' => ['nullable', 'string', 'max:1000'],
            // El clan real puede ser mucho mas viejo que su registro en el
            // sitio (2026-09-03) -- el fundador la elige a mano, nunca se
            // infiere de created_at. No permite fechas futuras.
            'founded_on' => ['required', 'date', 'before_or_equal:today'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ]);

        $clan = DB::transaction(function () use ($data, $siteUser) {
            $clan = Clan::create([
                'name' => $data['name'],
                'tag' => $data['tag'],
                'description' => $data['description'] ?? null,
                'founded_on' => $data['founded_on'],
                'founder_site_user_id' => $siteUser->id,
            ]);

            ClanMember::create([
                'clan_id' => $clan->id, 'site_user_id' => $siteUser->id,
                'role' => 'founder', 'joined_at' => now(),
            ]);

            return $clan;
        });

        if ($request->hasFile('logo')) {
            ClanLogo::store($clan, $request->file('logo'));
        }

        return redirect()->route('clans.show', $clan)->with('status', __('Clan creado.'));
    }

    public function show(Request $request, Clan $clan)
    {
        // CASE portable entre MySQL y SQLite (tests) -- FIELD() es solo MySQL.
        $clan->load([
            'members' => fn ($q) => $q->orderByRaw("CASE role WHEN 'founder' THEN 0 WHEN 'manager' THEN 1 ELSE 2 END"),
            'members.siteUser.player', 'founder',
        ]);

        $seasons = Season::orderByDesc('started_at')->get();
        $seasonParam = $request->query('season');
        $seasonId = $seasonParam === 'all' ? 'all' : ($seasonParam ? (int) $seasonParam : Season::current()->id);

        $playerIds = $clan->members->pluck('siteUser.player_id')->filter()->values()->all();
        $stats = ClanStatsCalculator::aggregate($playerIds, $seasonId);

        $siteUser = Auth::guard('site')->user();
        $myMembership = $siteUser ? ClanMember::where('site_user_id', $siteUser->id)->first() : null;
        $myRoleHere = ($myMembership && $myMembership->clan_id === $clan->id) ? $myMembership->role : null;
        $canManage = in_array($myRoleHere, ['founder', 'manager'], true);
        $isFounder = $myRoleHere === 'founder';

        $pendingRequests = $canManage
            ? $clan->pendingInvitations()->where('direction', 'player_requested')->with('siteUser.player')->get()
            : collect();
        $pendingSentInvites = $canManage
            ? $clan->pendingInvitations()->where('direction', 'manager_invited')->with('siteUser.player')->get()
            : collect();

        $myPendingRequest = ($siteUser && ! $myMembership)
            ? ClanInvitation::where('clan_id', $clan->id)->where('site_user_id', $siteUser->id)->where('status', 'pending')->first()
            : null;

        return view('clans.show', compact(
            'clan', 'seasons', 'seasonId', 'stats', 'siteUser',
            'myMembership', 'myRoleHere', 'canManage', 'isFounder',
            'pendingRequests', 'pendingSentInvites', 'myPendingRequest'
        ));
    }

    public function update(Request $request, Clan $clan)
    {
        $this->authorizeManage($clan);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', Rule::unique('clans', 'name')->ignore($clan->id)],
            'tag' => [...self::URL_SAFE_TAG, Rule::unique('clans', 'tag')->ignore($clan->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'founded_on' => ['required', 'date', 'before_or_equal:today'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ]);

        if ($request->hasFile('logo')) {
            ClanLogo::store($clan, $request->file('logo'));
        }
        unset($data['logo']);

        $clan->update($data);

        return redirect()->route('clans.show', $clan)->with('status', __('Clan actualizado.'));
    }

    public function disband(Clan $clan)
    {
        $this->authorizeFounder($clan);

        $name = $clan->name;
        if ($clan->logo_path) {
            Storage::disk('public')->delete($clan->logo_path);
        }
        $clan->delete(); // cascade: clan_members, clan_invitations

        return redirect()->route('clans.index')->with('status', __('Clan ":name" disuelto.', ['name' => $name]));
    }

    /** El jugador pide unirse a $clan -- lo resuelve un Manager/Fundador del clan. */
    public function requestJoin(Clan $clan)
    {
        $siteUser = Auth::guard('site')->user();
        abort_unless($siteUser->player_id, 403);

        if ($siteUser->clanMembership) {
            return back()->withErrors(['clan' => __('Ya pertenecés a un clan. Salí de tu clan actual antes de pedir unirte a otro.')]);
        }

        if (ClanInvitation::where('clan_id', $clan->id)->where('site_user_id', $siteUser->id)->where('status', 'pending')->exists()) {
            return back()->with('status', __('Ya tenés una solicitud/invitación pendiente con este clan.'));
        }

        ClanInvitation::create([
            'clan_id' => $clan->id, 'site_user_id' => $siteUser->id,
            'created_by_site_user_id' => $siteUser->id, 'direction' => 'player_requested', 'status' => 'pending',
        ]);

        return back()->with('status', __('Solicitud enviada.'));
    }

    /** Un Manager/Fundador de $clan invita a un jugador puntual. */
    public function invite(Request $request, Clan $clan)
    {
        $this->authorizeManage($clan);

        $data = $request->validate(['site_user_id' => ['required', 'integer', 'exists:site_users,id']]);
        $target = SiteUser::findOrFail($data['site_user_id']);

        if (! $target->player_id) {
            return back()->withErrors(['site_user_id' => __('Ese jugador todavía no reclamó su perfil.')]);
        }
        if ($target->clanMembership) {
            return back()->withErrors(['site_user_id' => __('Ese jugador ya pertenece a un clan.')]);
        }
        if (ClanInvitation::where('clan_id', $clan->id)->where('site_user_id', $target->id)->where('status', 'pending')->exists()) {
            return back()->with('status', __('Ya hay una solicitud/invitación pendiente con ese jugador.'));
        }

        ClanInvitation::create([
            'clan_id' => $clan->id, 'site_user_id' => $target->id,
            'created_by_site_user_id' => Auth::guard('site')->id(), 'direction' => 'manager_invited', 'status' => 'pending',
        ]);

        return back()->with('status', __('Invitación enviada.'));
    }

    /**
     * Buscador JSON para el form de invitar -- solo jugadores con perfil
     * reclamado y sin clan. Sin texto de búsqueda (2026-09-04, a pedido del
     * dueño) devuelve el listado completo de usuarios ya registrados
     * elegibles (hasta 50, alfabético) en vez de nada -- así el manager
     * puede tildar directo a alguien de la lista sin tener que saber/tipear
     * su nombre exacto de antemano. Con texto, acota como antes.
     */
    public function searchInvitable(Request $request, Clan $clan)
    {
        $this->authorizeManage($clan);

        $q = trim((string) $request->query('q', ''));

        $query = SiteUser::whereNotNull('player_id')
            ->whereDoesntHave('clanMembership')
            ->with('player');

        if ($q !== '') {
            $aliasPlayerIds = PlayerAlias::where('name_plain', 'like', "%{$q}%")->pluck('player_id');
            $query->whereHas('player', fn ($pq) => $pq->where('last_name_plain', 'like', "%{$q}%")->orWhereIn('id', $aliasPlayerIds));
        }

        $results = $query->limit(50)->get()->sortBy(fn (SiteUser $su) => $su->player->last_name_plain)->values();

        return response()->json($results->map(fn (SiteUser $su) => [
            'id' => $su->id,
            'name' => $su->player->last_name_plain,
            'guid' => $su->player->guid,
        ]));
    }

    /** El propio jugador acepta/rechaza una invitación que recibió. */
    public function respondToInvitation(Request $request, ClanInvitation $invitation)
    {
        $siteUser = Auth::guard('site')->user();
        abort_unless(
            $invitation->site_user_id === $siteUser->id && $invitation->isManagerInvite() && $invitation->status === 'pending',
            403
        );

        $data = $request->validate(['accept' => ['required', 'boolean']]);

        if (! $data['accept']) {
            $invitation->update(['status' => 'rejected']);

            return back()->with('status', __('Invitación rechazada.'));
        }

        if ($siteUser->clanMembership) {
            return back()->withErrors(['clan' => __('Ya pertenecés a un clan.')]);
        }

        DB::transaction(function () use ($invitation, $siteUser) {
            ClanMember::create([
                'clan_id' => $invitation->clan_id, 'site_user_id' => $siteUser->id,
                'role' => 'member', 'joined_at' => now(),
            ]);
            $invitation->update(['status' => 'accepted']);
            ClanInvitation::where('site_user_id', $siteUser->id)->where('status', 'pending')
                ->where('id', '!=', $invitation->id)->update(['status' => 'cancelled']);
        });

        return redirect()->route('clans.show', $invitation->clan)->with('status', __('Te uniste al clan.'));
    }

    /** Un Manager/Fundador de $clan aprueba/rechaza una solicitud recibida. */
    public function respondToRequest(Request $request, Clan $clan, ClanInvitation $invitation)
    {
        $this->authorizeManage($clan);
        abort_unless(
            $invitation->clan_id === $clan->id && $invitation->isPlayerRequest() && $invitation->status === 'pending',
            404
        );

        $data = $request->validate(['accept' => ['required', 'boolean']]);

        if (! $data['accept']) {
            $invitation->update(['status' => 'rejected']);

            return back()->with('status', __('Solicitud rechazada.'));
        }

        $target = $invitation->siteUser;
        if ($target->clanMembership) {
            $invitation->update(['status' => 'rejected']);

            return back()->withErrors(['clan' => __('Ese jugador ya se unió a otro clan mientras tanto.')]);
        }

        DB::transaction(function () use ($invitation, $target) {
            ClanMember::create([
                'clan_id' => $invitation->clan_id, 'site_user_id' => $target->id,
                'role' => 'member', 'joined_at' => now(),
            ]);
            $invitation->update(['status' => 'accepted']);
            ClanInvitation::where('site_user_id', $target->id)->where('status', 'pending')
                ->where('id', '!=', $invitation->id)->update(['status' => 'cancelled']);
        });

        return back()->with('status', __('Solicitud aprobada.'));
    }

    /** Solo el Fundador puede ascender/degradar (Manager no puede tocar roles). */
    public function changeRole(Request $request, Clan $clan, ClanMember $member)
    {
        $this->authorizeFounder($clan);
        abort_unless($member->clan_id === $clan->id && ! $member->isFounder(), 404);

        $data = $request->validate(['role' => ['required', 'in:manager,member']]);
        $member->update(['role' => $data['role']]);

        return back()->with('status', __('Rol actualizado.'));
    }

    /** Fundador puede expulsar a cualquiera (menos a sí mismo); Manager solo a Miembros. */
    public function kick(Clan $clan, ClanMember $member)
    {
        $acting = $this->authorizeManage($clan);
        abort_unless($member->clan_id === $clan->id, 404);
        abort_if($member->isFounder(), 403);
        abort_if($acting->isManager() && $member->role !== 'member', 403);

        $member->delete();

        return back()->with('status', __('Miembro expulsado.'));
    }

    public function transfer(Request $request, Clan $clan)
    {
        $founderMembership = $this->authorizeFounder($clan);

        $data = $request->validate(['member_id' => ['required', 'integer']]);
        $newFounder = ClanMember::where('id', $data['member_id'])->where('clan_id', $clan->id)->firstOrFail();
        abort_if($newFounder->id === $founderMembership->id, 422);

        DB::transaction(function () use ($founderMembership, $newFounder, $clan) {
            $founderMembership->update(['role' => 'member']);
            $newFounder->update(['role' => 'founder']);
            $clan->update(['founder_site_user_id' => $newFounder->site_user_id]);
        });

        return back()->with('status', __('Fundación transferida.'));
    }

    public function leave(Clan $clan)
    {
        $membership = $this->myMembership();
        abort_unless($membership && $membership->clan_id === $clan->id, 403);

        if ($membership->isFounder()) {
            return back()->withErrors(['clan' => __('Como fundador, primero tenés que transferir la fundación a otro miembro (o disolver el clan).')]);
        }

        $membership->delete();

        return redirect()->route('clans.index')->with('status', __('Saliste del clan.'));
    }

    private function myMembership(): ?ClanMember
    {
        $siteUser = Auth::guard('site')->user();

        return $siteUser ? ClanMember::where('site_user_id', $siteUser->id)->first() : null;
    }

    private function authorizeManage(Clan $clan): ClanMember
    {
        $membership = $this->myMembership();
        abort_unless($membership && $membership->clan_id === $clan->id && $membership->canManage(), 403);

        return $membership;
    }

    private function authorizeFounder(Clan $clan): ClanMember
    {
        $membership = $this->myMembership();
        abort_unless($membership && $membership->clan_id === $clan->id && $membership->isFounder(), 403);

        return $membership;
    }
}
