<?php

use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\BanController;
use App\Http\Controllers\Admin\ConsoleController;
use App\Http\Controllers\Admin\DemoController as AdminDemoController;
use App\Http\Controllers\Admin\DiscordSettingController;
use App\Http\Controllers\Admin\MapImageController;
use App\Http\Controllers\Admin\MatchController as AdminMatchController;
use App\Http\Controllers\Admin\PasswordController;
use App\Http\Controllers\Admin\PlayerController as AdminPlayerController;
use App\Http\Controllers\Admin\SeasonController;
use App\Http\Controllers\Admin\ServerController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DemosController;
use App\Http\Controllers\DemoUploadController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\HostedServerController;
use App\Http\Controllers\KillDetailController;
use App\Http\Controllers\LeaderboardController;
use App\Http\Controllers\MatchController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\SpecialtyController;
use App\Http\Controllers\TeamBalanceController;
use App\Http\Controllers\TeamkillController;
use Illuminate\Support\Facades\Route;

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
Route::get('/jugadores/{player:guid}', [PlayerController::class, 'show'])->name('players.show');
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
// La ruta para "Descargas" (menu Ayuda) se agrega cuando este definido el contenido.

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
    Route::get('/', fn () => redirect()->route('admin.servers.index'))->name('home');
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.attempt');

    Route::middleware('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

        Route::get('/password', [PasswordController::class, 'edit'])->name('password.edit');
        Route::put('/password', [PasswordController::class, 'update'])->name('password.update');

        Route::put('/configuracion', [SettingController::class, 'update'])->name('settings.update');
        Route::put('/configuracion/servidores-temporales', [SettingController::class, 'updateHostedServers'])->name('settings.hosted-servers.update');

        Route::get('/discord', [DiscordSettingController::class, 'edit'])->name('discord.edit');
        Route::put('/discord', [DiscordSettingController::class, 'update'])->name('discord.update');

        Route::resource('servers', ServerController::class)->except(['show']);

        Route::get('/partidas', [AdminMatchController::class, 'index'])->name('matches.index');
        Route::delete('/partidas/{match}', [AdminMatchController::class, 'destroy'])->name('matches.destroy');

        Route::get('/demos', [AdminDemoController::class, 'index'])->name('demos.index');
        Route::delete('/demos/{demo}', [AdminDemoController::class, 'destroy'])->name('demos.destroy');
        Route::delete('/demos/match/{match}', [AdminDemoController::class, 'destroyByMatch'])->name('demos.destroy-match');
        Route::get('/demos/{match}', [AdminDemoController::class, 'show'])->name('demos.show');

        Route::get('/maps', [MapImageController::class, 'index'])->name('maps.index');
        Route::post('/maps/{code}', [MapImageController::class, 'store'])->name('maps.store');
        Route::delete('/maps/{code}', [MapImageController::class, 'destroy'])->name('maps.destroy');

        Route::get('/paises', [AdminPlayerController::class, 'index'])->name('players.index');
        Route::delete('/paises/{player}', [AdminPlayerController::class, 'clearIp'])->name('players.clear-ip');

        Route::get('/console/{server}', [ConsoleController::class, 'show'])->name('console.show');
        Route::post('/console/{server}/kick', [ConsoleController::class, 'kick'])->name('console.kick');
        Route::post('/console/{server}/ban', [ConsoleController::class, 'ban'])->name('console.ban');
        Route::post('/console/{server}/message', [ConsoleController::class, 'message'])->name('console.message');
        Route::post('/console/{server}/map', [ConsoleController::class, 'changeMap'])->name('console.map');
        Route::post('/console/{server}/command', [ConsoleController::class, 'command'])->name('console.command');
        Route::post('/console/{server}/service', [ConsoleController::class, 'service'])->name('console.service');
        Route::get('/console/{server}/log-tail', [ConsoleController::class, 'logTail'])->name('console.log-tail');
        Route::get('/console/{server}/resources', [ConsoleController::class, 'resources'])->name('console.resources');
        Route::get('/console/{server}/resource-usage', [ConsoleController::class, 'resourceUsage'])->name('console.resource-usage');

        Route::get('/auditoria', [AuditController::class, 'index'])->name('audit.index');

        Route::get('/bans', [BanController::class, 'index'])->name('bans.index');
        Route::delete('/bans/{ban}', [BanController::class, 'destroy'])->name('bans.destroy');

        Route::get('/respaldos', [BackupController::class, 'index'])->name('backups.index');
        Route::post('/respaldos', [BackupController::class, 'store'])->name('backups.store');
        Route::post('/respaldos/importar', [BackupController::class, 'import'])->name('backups.import');
        Route::get('/respaldos/{filename}/descargar', [BackupController::class, 'download'])->name('backups.download');
        Route::post('/respaldos/{filename}/restaurar', [BackupController::class, 'restore'])->name('backups.restore');
        Route::delete('/respaldos/{filename}', [BackupController::class, 'destroy'])->name('backups.destroy');

        Route::get('/temporadas', [SeasonController::class, 'index'])->name('seasons.index');
        Route::post('/temporadas', [SeasonController::class, 'store'])->name('seasons.store');
        Route::post('/temporadas/{season}/reactivar', [SeasonController::class, 'reactivate'])->name('seasons.reactivate');
    });
});
