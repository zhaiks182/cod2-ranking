<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\BanController;
use App\Http\Controllers\Admin\ConsoleController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\DemoController as AdminDemoController;
use App\Http\Controllers\Admin\DiscordSettingController;
use App\Http\Controllers\Admin\HostedServerSettingController;
use App\Http\Controllers\Admin\MapImageController;
use App\Http\Controllers\Admin\MatchController as AdminMatchController;
use App\Http\Controllers\Admin\PasswordController;
use App\Http\Controllers\Admin\PlayerController as AdminPlayerController;
use App\Http\Controllers\Admin\PlayerDeleteController;
use App\Http\Controllers\Admin\PlayerIconController;
use App\Http\Controllers\Admin\PlayerMergeController;
use App\Http\Controllers\Admin\SeasonController;
use App\Http\Controllers\Admin\ServerController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\SiteUserController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DemosController;
use App\Http\Controllers\DemoUploadController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\HostedServerController;
use App\Http\Controllers\KillDetailController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\PlayerClaimController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\PlayerSearchController;
use App\Http\Controllers\SiteAuthController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SpecialtyController;
use App\Http\Controllers\TeamBalanceController;
use App\Http\Controllers\TeamkillController;
use Illuminate\Support\Facades\Route;

Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/widget/live-status', [DashboardController::class, 'liveStatusWidget'])->name('dashboard.live-status');
Route::get('/widget/discord', [DashboardController::class, 'discordWidget'])->name('dashboard.discord-widget');
Route::get('/ranking', [LeaderboardController::class, 'index'])->name('leaderboard');
Route::get('/rango', [SpecialtyController::class, 'rango'])->name('rango');
Route::get('/equipos', [TeamBalanceController::class, 'index'])->name('team-balance');
Route::get('/partidas', [MatchController::class, 'index'])->name('matches.index');
Route::get('/partidas/{match}', [MatchController::class, 'show'])->name('matches.show');
Route::get('/demos', [DemosController::class, 'index'])->name('demos.index');
Route::get('/demos/download/{demo}', [DemosController::class, 'download'])->name('demos.download');
Route::get('/demos/{match}', [DemosController::class, 'show'])->name('demos.show');
Route::get('/jugadores/buscar', [PlayerSearchController::class, 'search'])->name('players.search');
Route::get('/jugadores/{player:guid}', [PlayerController::class, 'show'])->name('players.show');
Route::post('/jugadores/{player:guid}/reclamar', [PlayerClaimController::class, 'store'])->name('players.claim.store')->middleware('auth:site');
Route::get('/teamkills/{player:guid}', [TeamkillController::class, 'index'])->name('teamkills.index');
Route::get('/kills/{player:guid}', [KillDetailController::class, 'index'])->name('kills.detail');
Route::get('/granadas', [SpecialtyController::class, 'grenades'])->name('specialties.grenades');
Route::get('/headshots', [SpecialtyController::class, 'headshots'])->name('specialties.headshots');
Route::get('/fuego-amigo', [SpecialtyController::class, 'friendlyFire'])->name('specialties.friendly-fire');
Route::get('/suicidios', [SpecialtyController::class, 'suicides'])->name('specialties.suicides');
Route::get('/eficiencia', [SpecialtyController::class, 'efficiency'])->name('specialties.efficiency');
Route::get('/mapas-ganados', [SpecialtyController::class, 'mapsWon'])->name('specialties.maps-won');
Route::get('/armas', [SpecialtyController::class, 'weapons'])->name('specialties.weapons');
Route::get('/rivalidades', [SpecialtyController::class, 'rivalries'])->name('specialties.rivalries');
Route::get('/reyes-de-mapa', [SpecialtyController::class, 'mapKings'])->name('specialties.map-kings');
Route::get('/horas-jugadas', [SpecialtyController::class, 'playtime'])->name('specialties.playtime');
Route::get('/racha-de-mapas', [SpecialtyController::class, 'streaks'])->name('specialties.streaks');
Route::get('/actividad-reciente', [SpecialtyController::class, 'recentActivity'])->name('specialties.recent-activity');
Route::get('/paises', [SpecialtyController::class, 'countries'])->name('specialties.countries');
Route::get('/clutches', [SpecialtyController::class, 'clutches'])->name('specialties.clutches');
Route::get('/rachas-de-bajas', [SpecialtyController::class, 'killStreaks'])->name('specialties.streaks-kills');
Route::get('/mas-hablador', [SpecialtyController::class, 'chattiest'])->name('specialties.chattiest');
Route::get('/hora-pico', [SpecialtyController::class, 'peakTimes'])->name('specialties.peak-times');
Route::get('/timeouts', [SpecialtyController::class, 'timeouts'])->name('specialties.timeouts');
Route::get('/bash', [SpecialtyController::class, 'bashCalls'])->name('specialties.bash');
Route::get('/win-rate', [SpecialtyController::class, 'winRate'])->name('specialties.win-rate');
Route::get('/bombas', [SpecialtyController::class, 'bombs'])->name('specialties.bombs');
Route::get('/dano', [SpecialtyController::class, 'damage'])->name('specialties.damage');
Route::get('/desconexiones', [SpecialtyController::class, 'disconnects'])->name('specialties.disconnects');
Route::get('/muertes-por-nades', [SpecialtyController::class, 'grenadeDeaths'])->name('specialties.grenade-deaths');

Route::get('/preguntas-frecuentes', [HelpController::class, 'faq'])->name('faq');
Route::get('/descargas', [HelpController::class, 'downloads'])->name('downloads');
Route::get('/descargas/archivos/{path?}', [HelpController::class, 'browseFiles'])
    ->where('path', '.*')
    ->name('downloads.browse');

Route::get('/idioma/{locale}', [LocaleController::class, 'switch'])->name('locale.switch');

// Login publico con Discord (2026-09-01) -- guard `site`, separado del login
// admin. Ver docs/superpowers/specs/2026-09-01-login-discord-reclamo-perfil-design.md.
Route::get('/login', [SiteAuthController::class, 'redirect'])->name('login');
Route::get('/auth/discord/callback', [SiteAuthController::class, 'callback'])->name('auth.discord.callback');
Route::post('/logout', [SiteAuthController::class, 'logout'])->name('logout')->middleware('auth:site');

// Mi cuenta -- ver estado del reclamo y editar bio/redes/specs de PC (Task 5).
Route::get('/mi-cuenta', [AccountController::class, 'show'])->name('account.show')->middleware('auth:site');
Route::post('/mi-cuenta', [AccountController::class, 'update'])->name('account.update')->middleware('auth:site');
Route::get('/mi-cuenta/estado', [AccountController::class, 'status'])->name('account.status')->middleware('auth:site');
Route::post('/mi-cuenta/reclamo/cancelar', [PlayerClaimController::class, 'cancel'])->name('account.claim.cancel')->middleware('auth:site');

// El endpoint de latencia (/ping, usado por hosted-servers/create.blade.php) YA NO
// es una ruta de Laravel -- ver public/ping (archivo estatico vacio) y public/.htaccess
// (header CORS). Apache lo sirve directo por el RewriteCond "!-f" de abajo, sin pasar
// por index.php/PHP-FPM, para que la medicion de latencia no incluya el bootstrap del
// framework. No agregar una ruta '/ping' aca -- nunca se alcanzaria, el archivo
// estatico siempre gana primero.

// Servidores temporales self-service (publico, sin login) -- ver CLAUDE.md, seccion
// "Servidores temporales". management_token en la URL es la unica "credencial" del
// creador (no hay cuentas), por eso show/stop llevan {token} ademas del id.
Route::prefix('servidores')->name('hosted-servers.')->group(function () {
    Route::get('/crear', [HostedServerController::class, 'create'])->name('create');
    // throttle:20,1 reactivado (2026-08-24) -- estuvo sacado desde el
    // 2026-08-22 (commit 4ebfd16) para que el dueño pudiera probar el flujo
    // sin pegarle al 429 durante el rediseño del form. Turnstile (ver
    // HostedServerController::passesTurnstile()) y el Cache::lock de
    // concurrencia global siguen siendo las otras dos capas -- esta es la
    // tercera, especifica contra rafagas rapidas desde una sola IP/sesion.
    // 20 por minuto (no el 3/60 original, que también cayó en la misma trampa:
    // el segundo argumento de throttle en Laravel es decayMinutes, no segundos,
    // así que throttle:20,60 hubiera sido 20/hora = 60x mas estricto de lo que
    // el comentario, plan y nombre del test decían). El tercer parámetro
    // (hosted-create) es un prefijo de clave para no compartir bucket con
    // futuras rutas que agreguen throttle en este dominio.
    Route::post('/crear', [HostedServerController::class, 'store'])->name('store')->middleware('throttle:20,1,hosted-create');
    Route::get('/{hostedServer}/{token}', [HostedServerController::class, 'show'])->name('show');
    Route::post('/{hostedServer}/{token}/detener', [HostedServerController::class, 'stop'])->name('stop');
});

// Subida automatica de demos por HWID desde el cliente CoD2x (ver _record.gsc en el
// mod zPAM). Sin auth: el cliente del juego no puede autenticarse, y exento de CSRF
// (ver bootstrap/app.php) por lo mismo.
Route::post('/api/demos/upload/{hwid}/{demoName}', [DemoUploadController::class, 'store'])
    ->where('hwid', '[0-9a-fA-F]+')
    ->name('demos.upload');

Route::prefix('adm_cod2')->name('admin.')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');

    Route::middleware('auth')->group(function () {
        Route::get('/', [AdminDashboardController::class, 'index'])->name('home');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

        // Cuenta propia -- cualquier admin autenticado puede cambiar su propia
        // contraseña, sin importar que modulos tenga asignados.
        Route::get('/password', [PasswordController::class, 'edit'])->name('password.edit');
        Route::put('/password', [PasswordController::class, 'update'])->name('password.update');

        // Usuarios y roles (2026-08-31) -- NUNCA un modulo de User::MODULES
        // (ver esa constante), solo super-admins.
        Route::middleware('super-admin')->group(function () {
            Route::resource('usuarios', UserController::class)->except(['show'])->parameters(['usuarios' => 'user'])->names('users');
        });

        // "servers" es SOLO consola RCON de Pug Latam (kick/ban/mensaje/mapa/
        // comando/reiniciar servicio) + ver la lista de servers reales. Crear,
        // editar o borrar un server (toca la contraseña RCON de produccion)
        // quedo reservado a super-admin (ver el grupo mas abajo) -- un modulo
        // otorgable de un checkbox no debe poder tocar la config del
        // gameserver real, solo operarlo. Ver User::MODULES.
        Route::middleware('module:servers')->group(function () {
            Route::get('/servers', [ServerController::class, 'index'])->name('servers.index');

            Route::get('/console/{server}', [ConsoleController::class, 'show'])->name('console.show');
            Route::post('/console/{server}/kick', [ConsoleController::class, 'kick'])->name('console.kick');
            Route::post('/console/{server}/ban', [ConsoleController::class, 'ban'])->name('console.ban');
            Route::post('/console/{server}/message', [ConsoleController::class, 'message'])->name('console.message');
            Route::post('/console/{server}/map', [ConsoleController::class, 'changeMap'])->name('console.map');
            Route::post('/console/{server}/command', [ConsoleController::class, 'command'])->name('console.command');
            Route::post('/console/{server}/service', [ConsoleController::class, 'service'])->name('console.service');
            Route::post('/console/{server}/notify-teams', [ConsoleController::class, 'notifyTeams'])->name('console.notify-teams');
            Route::get('/console/{server}/log-tail', [ConsoleController::class, 'logTail'])->name('console.log-tail');
            Route::get('/console/{server}/resources', [ConsoleController::class, 'resources'])->name('console.resources');
            Route::get('/console/{server}/resource-usage', [ConsoleController::class, 'resourceUsage'])->name('console.resource-usage');
        });

        // Crear/editar/borrar servers reales -- ver el comentario de arriba.
        Route::middleware('super-admin')->group(function () {
            Route::get('/servers/create', [ServerController::class, 'create'])->name('servers.create');
            Route::post('/servers', [ServerController::class, 'store'])->name('servers.store');
            Route::get('/servers/{server}/edit', [ServerController::class, 'edit'])->name('servers.edit');
            Route::put('/servers/{server}', [ServerController::class, 'update'])->name('servers.update');
            Route::delete('/servers/{server}', [ServerController::class, 'destroy'])->name('servers.destroy');
        });

        // Servidores temporales self-service -- separado de "servers" (2026-09-01,
        // ver User::MODULES): responsabilidad distinta (el feature publico de
        // /servidores/crear, no el gameserver real de Pug Latam).
        Route::middleware('module:hosted-servers')->group(function () {
            Route::get('/servidores-temporales', [HostedServerSettingController::class, 'index'])->name('hosted-servers.index');
            Route::put('/servidores-temporales', [HostedServerSettingController::class, 'update'])->name('hosted-servers.update');
        });

        Route::middleware('module:discord')->group(function () {
            Route::get('/discord', [DiscordSettingController::class, 'edit'])->name('discord.edit');
            Route::put('/discord', [DiscordSettingController::class, 'update'])->name('discord.update');
        });

        Route::middleware('module:matches')->group(function () {
            Route::get('/partidas', [AdminMatchController::class, 'index'])->name('matches.index');
            Route::delete('/partidas/{match}', [AdminMatchController::class, 'destroy'])->name('matches.destroy');
        });

        // "settings.update" es el form de retencion de demos embebido dentro de
        // /adm_cod2/demos -- gateado por demos, no por servers (no tiene nada
        // que ver con el gameserver).
        Route::middleware('module:demos')->group(function () {
            Route::put('/configuracion', [SettingController::class, 'update'])->name('settings.update');
            Route::get('/demos', [AdminDemoController::class, 'index'])->name('demos.index');
            Route::delete('/demos/{demo}', [AdminDemoController::class, 'destroy'])->name('demos.destroy');
            Route::delete('/demos/match/{match}', [AdminDemoController::class, 'destroyByMatch'])->name('demos.destroy-match');
            Route::get('/demos/{match}', [AdminDemoController::class, 'show'])->name('demos.show');
        });

        Route::middleware('module:maps')->group(function () {
            Route::get('/maps', [MapImageController::class, 'index'])->name('maps.index');
            Route::post('/maps/{code}', [MapImageController::class, 'store'])->name('maps.store');
            Route::delete('/maps/{code}', [MapImageController::class, 'destroy'])->name('maps.destroy');
        });

        // "players" agrupa las 4 pantallas que administran identidad de jugador
        // (países, fusionar, borrar, íconos) -- separarlas en 4 modulos hubiera
        // sido granularidad sin caso de uso real, todas operan sobre lo mismo.
        Route::middleware('module:players')->group(function () {
            Route::get('/paises', [AdminPlayerController::class, 'index'])->name('players.index');
            Route::delete('/paises/{player}', [AdminPlayerController::class, 'clearIp'])->name('players.clear-ip');

            Route::get('/jugadores/fusionar', [PlayerMergeController::class, 'index'])->name('players.merge.index');
            Route::post('/jugadores/fusionar', [PlayerMergeController::class, 'store'])->name('players.merge.store');

            Route::get('/jugadores/borrar', [PlayerDeleteController::class, 'index'])->name('players.delete.index');
            Route::delete('/jugadores/borrar/masivo-sin-actividad', [PlayerDeleteController::class, 'destroyZeroActivity'])->name('players.delete.bulk-zero-activity');
            Route::delete('/jugadores/borrar/{player}', [PlayerDeleteController::class, 'destroy'])->name('players.delete.destroy');

            Route::get('/jugadores/cuentas-discord', [SiteUserController::class, 'index'])->name('players.discord-accounts.index');
            Route::delete('/jugadores/cuentas-discord/{siteUser}', [SiteUserController::class, 'unlink'])->name('players.discord-accounts.unlink');

            Route::get('/jugadores/iconos', [PlayerIconController::class, 'index'])->name('players.icons.index');
            Route::post('/jugadores/iconos/{player}', [PlayerIconController::class, 'store'])->name('players.icons.store');
            Route::delete('/jugadores/iconos/{player}', [PlayerIconController::class, 'destroy'])->name('players.icons.destroy');
        });

        Route::middleware('module:audit')->group(function () {
            Route::get('/auditoria', [AuditController::class, 'index'])->name('audit.index');
        });

        Route::middleware('module:bans')->group(function () {
            Route::get('/bans', [BanController::class, 'index'])->name('bans.index');
            Route::delete('/bans/{ban}', [BanController::class, 'destroy'])->name('bans.destroy');
        });

        Route::middleware('module:backups')->group(function () {
            Route::get('/respaldos', [BackupController::class, 'index'])->name('backups.index');
            Route::post('/respaldos', [BackupController::class, 'store'])->name('backups.store');
            Route::post('/respaldos/importar', [BackupController::class, 'import'])->name('backups.import');
            Route::get('/respaldos/{filename}/descargar', [BackupController::class, 'download'])->name('backups.download');
            Route::post('/respaldos/{filename}/restaurar', [BackupController::class, 'restore'])->name('backups.restore');
            Route::delete('/respaldos/{filename}', [BackupController::class, 'destroy'])->name('backups.destroy');
        });

        Route::middleware('module:seasons')->group(function () {
            Route::get('/temporadas', [SeasonController::class, 'index'])->name('seasons.index');
            Route::post('/temporadas', [SeasonController::class, 'store'])->name('seasons.store');
            Route::post('/temporadas/{season}/reactivar', [SeasonController::class, 'reactivate'])->name('seasons.reactivate');
        });
    });
});
