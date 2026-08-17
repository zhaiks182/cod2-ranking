# CoD2 Ranking — Pug Latam

Dashboard de estadísticas para servidores de **Call of Duty 2** corriendo el mod
**CoD2x + zPAM**. Lee el log del gameserver en tiempo real y arma rankings de
jugadores, historial de partidas, estado del servidor en vivo, y un panel de
administración con consola RCON — sin depender de ninguna API externa del juego,
todo se calcula a partir del propio `games_mp.log`.

## Qué incluye

- **Estado en vivo del servidor** — mapa actual, jugadores conectados con su
  puntaje/muertes/headshots en tiempo real (consulta RCON), y comando de conexión
  con un click para copiar.
- **Ranking general** — global, por mapa, y filtrable por rango de fechas.
  Tabla de posiciones Axis/Allies con el marcador de cada partida.
- **Historial de partidas** — detalle por partida con línea de tiempo de eventos
  (inicio, cambio de bando, tiempo extra, timeouts), chat general y chat de
  equipo (separado por Axis/Allies), y desglose de kills por jugador.
- **+20 páginas de "Especialidades"** (leaderboards temáticos), agrupadas en:
  - **Combate** — headshots, granadas, ranking por arma, eficiencia K/D,
    especialistas en bombas/daño, clutches 1vX, rachas de bajas, bash (bajas
    cuerpo a cuerpo).
  - **Mapas y partidas** — mapas ganados, reyes de cada mapa, racha de mapas,
    win rate por mapa.
  - **Salón de la vergüenza** — muertes por granada, fuego amigo, suicidios,
    desconexiones a media ronda.
  - **Social** — jugador más hablador, timeouts pedidos.
  - **Actividad** — horas jugadas, actividad reciente, hora pico, países de
    los jugadores (geolocalización por IP).
- **Perfiles de jugador** — historial completo, arma favorita, rivalidades
  cara-a-cara contra cualquier otro jugador.
- **Panel de administración** (`/adm_cod2`) — login propio (no OAuth), CRUD de
  servidores (soporta más de un servidor CoD2 a la vez), consola RCON en vivo
  (kick, mensaje, cambio de mapa, comando libre), subida de imágenes de mapa,
  gestión de partidas y de países/IP de jugadores.
- **Multi-servidor** — cada servidor CoD2 configurado tiene su propio log,
  credenciales RCON e IP pública; los jugadores son globales (identificados por
  hardware ID, no por nombre) pero las estadísticas se llevan por servidor.

## Cómo funciona (a grandes rasgos)

El gameserver escribe cada evento de partida (kills, rondas, conexiones, chat,
etc.) como texto plano en `games_mp.log`. El comando
`php artisan cod2:parse-log`, programado para correr cada minuto
(`routes/console.php`), lee las líneas nuevas del log (recuerda su posición de
lectura, no relee todo el archivo) y las guarda en la base de datos. Ese mismo
comando también consulta `status` por RCON para mantener sincronizados los
nombres y el país (por IP) de los jugadores conectados.

## Stack

- **Backend:** Servidor LAMP (Laravel 13, PHP 8.3+)
- **Frontend:** Blade + Tailwind CSS (vía CDN, sin paso de build)
- **Base de datos:** MySQL / MariaDB
- **GeoIP:** [DB-IP Country Lite](https://db-ip.com) (`.mmdb`, licencia CC BY 4.0,
  sin API key)

## Prerrequisitos

- PHP 8.3 o superior, con las extensiones habituales de Laravel (`pdo_mysql`,
  `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`)
- Composer
- MySQL o MariaDB
- Un servidor de Call of Duty 2 corriendo el mod **CoD2x** con **zPAM**, con:
  - Acceso de lectura al archivo `games_mp.log` desde donde corra la app (mismo
    filesystem — ver nota abajo si el gameserver está en otra máquina)
  - RCON habilitado (host, puerto y contraseña)
- Acceso de escritura a `storage/` y `bootstrap/cache/` para el usuario con el
  que corra PHP (`www-data` en un setup típico de Apache/Nginx)

> **Nota:** el parser abre el log con `fopen()` directo, así que necesita estar
> en el mismo filesystem que la app. Si el gameserver corre en otra máquina, hay
> que sincronizar el log primero (por ejemplo con `rsync` por SSH antes de cada
> corrida del parser) — no viene resuelto de fábrica.

## Instalación

### Opción rápida: `install.sh`

```bash
git clone https://github.com/zhaiks182/cod2-ranking.git
cd cod2-ranking
chmod +x install.sh && ./install.sh
```

Pide por prompts todo lo necesario (dominio, base de datos, datos del server de
CoD2, usuario/contraseña del panel admin) y hace el resto: `composer install`,
`.env`, creación de la base de datos, migraciones, `storage:link`, permisos,
el cron del parser, y la descarga inicial de GeoIP. Al final imprime un
resumen con las credenciales — el panel queda en `/adm_cod2/login`.

### Manual, paso a paso

```bash
git clone https://github.com/zhaiks182/cod2-ranking.git
cd cod2-ranking
composer install
cp .env.example .env
php artisan key:generate
```

Editar `.env` con al menos la conexión a la base de datos y la zona horaria:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cod2_stats
DB_USERNAME=tu_usuario
DB_PASSWORD=tu_password

APP_TIMEZONE=America/Guayaquil   # o la zona horaria de tu comunidad — ver nota abajo
```

> **Sobre `APP_TIMEZONE`:** Laravel usa su propia configuración de zona horaria,
> independiente de la del sistema operativo — si se deja en UTC (el default),
> todas las fechas/horas del sitio van a aparecer adelantadas respecto a la hora
> real de tu comunidad.

Correr las migraciones (esto crea el esquema y un usuario admin con contraseña
generada al azar — **copiá esa contraseña de la salida de la consola**, no
queda guardada en ningún otro lado):

```bash
php artisan migrate
```

Los datos del servidor de CoD2 (ruta del log, IP/puerto de conexión, RCON) **no
se configuran en `.env`** — se cargan desde el panel de administración una vez
logueado, en `/adm_cod2/servers` (podés agregar más de un servidor ahí, ver
"Multi-servidor" arriba). La migración crea un primer servidor de ejemplo con
valores genéricos; entrá a editarlo con los datos reales antes de usar el
sitio.

Enlazar el storage público (necesario para que se sirvan las imágenes de mapa y
los íconos de arma):

```bash
php artisan storage:link
```

### Poner el parser a correr

El scheduler de Laravel necesita correr cada minuto vía el cron del sistema:

```cron
* * * * * cd /ruta/al/proyecto && php artisan schedule:run >> /dev/null 2>&1
```

Esto dispara automáticamente `cod2:parse-log` cada minuto y `geoip:update` una
vez al mes.

### GeoIP (opcional, para las banderas de país)

```bash
php artisan geoip:update
```

Descarga la base de datos de países de DB-IP a `storage/app/geoip/country.mmdb`.
Sin este paso, el sitio funciona igual pero no muestra banderas de país.

### Panel de administración

Entrar a `/adm_cod2/login` con el usuario `adm_cod2` y la contraseña que
imprimió `php artisan migrate` (ver arriba). Cambiarla cuanto antes desde
`/adm_cod2/password`. Desde ahí se pueden agregar más servidores CoD2 (además
del que quedó configurado por defecto con las variables `COD2_*` del `.env`).

## Servir la aplicación

Cualquier método estándar de Laravel sirve — Apache/Nginx + PHP-FPM apuntando a
`public/`, o para probar rápido:

```bash
php artisan serve
```

## Licencia

Sin licencia definida todavía.
