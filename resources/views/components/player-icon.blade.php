{{-- Icono personalizado por jugador (2026-08-28, /adm_cod2/jugadores/iconos)
-- se muestra en cualquier lugar del sitio donde aparece el nombre de un
jugador (a pedido del dueño, 2026-08-28: originalmente solo se mostraba al
lado de la medalla del top 3, igual que el burro hardcodeado de harek que
reemplazó). Silencioso si el jugador no tiene icono. --}}
@props(['player'])
@if($player?->icon_url)
    <img src="{{ $player->icon_url }}" alt="" class="inline-block align-text-bottom ml-0.5" style="width:11px;height:auto">
@endif
