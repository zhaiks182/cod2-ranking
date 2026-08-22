# CoD2 Ranking — Pug Latam

Dashboard de estadísticas para servidores de **Call of Duty 2** corriendo el mod
**CoD2x + zPAM**. Lee el log del gameserver en tiempo real y arma rankings de
jugadores, historial de partidas, estado del servidor en vivo, y un panel de
administración con consola RCON — sin depender de ninguna API externa del juego,
todo se calcula a partir del propio `games_mp.log`.

## Qué incluye

- 📡 **Estado en vivo del servidor** — mapa actual, jugadores conectados con su
  puntaje/muertes/headshots en tiempo real (consulta RCON), y comando de conexión
  con un click para copiar.
- 🏆 **Ranking general** — global, por mapa, y filtrable por rango de fechas.
  Tabla de posiciones Axis/Allies con el marcador de cada partida.
- 🗂️ **Historial de partidas** — detalle por partida con línea de tiempo de eventos
  (inicio, cambio de bando, tiempo extra, timeouts) y badges de quién lideró esa
  partida en headshots, granadas y bash cuerpo a cuerpo, chat general y chat de
  equipo (separado por Axis/Allies), y desglose de kills por jugador.
- ⭐ **+20 páginas de "Especialidades"** (leaderboards temáticos), agrupadas en:
  - ⚔️ **Combate** — headshots, granadas, ranking por arma, eficiencia K/D,
    especialistas en bombas/daño, clutches 1vX, rachas de bajas, bash (bajas
    cuerpo a cuerpo).
  - 🗺️ **Mapas y partidas** — mapas ganados, reyes de cada mapa, racha de mapas,
    win rate por mapa.
  - 🙈 **Salón de la vergüenza** — muertes por granada, fuego amigo, suicidios,
    desconexiones a media ronda.
  - 💬 **Social** — jugador más hablador, timeouts pedidos.
  - 📊 **Actividad** — horas jugadas, actividad reciente, hora pico, países de
    los jugadores (geolocalización por IP).
- 🪪 **Perfiles de jugador** — historial completo, arma favorita, rivalidades
  cara-a-cara contra cualquier otro jugador.
- 🛠️ **Panel de administración** (`/adm_cod2`) — login propio (no OAuth), CRUD de
  servidores (soporta más de un servidor CoD2 a la vez), consola RCON en vivo
  (kick, mensaje, cambio de mapa, comando libre), subida de imágenes de mapa,
  gestión de partidas y de países/IP de jugadores.
- 🌐 **Multi-servidor** — cada servidor CoD2 configurado tiene su propio log,
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

### Instalar los prerrequisitos en una VM nueva (Ubuntu/Debian)

`install.sh` **no instala nada de esto** — asume que ya está en la máquina.
En una VM recién creada (probado en Ubuntu 24.04 LTS), instalar todo con:

```bash
# PHP 8.3 + extensiones (el repo oficial de Ubuntu no siempre trae 8.3, se usa el PPA de ondrej)
apt-get update
apt-get install -y software-properties-common
add-apt-repository -y ppa:ondrej/php
apt-get update
apt-get install -y php8.3 php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml \
    php8.3-curl php8.3-zip php8.3-bcmath php8.3-gd php8.3-intl git curl

# Composer (no viene empaquetado en los repos de Ubuntu)
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm composer-setup.php

# Base de datos
apt-get install -y mariadb-server
systemctl enable --now mariadb

# Servidor web — nginx + PHP-FPM (Apache también sirve, pero acá se documenta nginx)
apt-get install -y nginx
systemctl enable --now php8.3-fpm nginx
```

Con eso instalado, seguí con `install.sh` en la sección de abajo. Después de
que termine, todavía falta apuntar un vhost de nginx a `public/` — ver
["Servir la aplicación"](#servir-la-aplicación) más abajo; `install.sh` no
lo hace por vos. Ejemplo mínimo de vhost:

```nginx
server {
    listen 80;
    server_name tu-dominio-o-ip;

    root /var/www/html/public;   # o /ruta/a/cod2-ranking/public si clonaste en otro lado
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

```bash
ln -s /etc/nginx/sites-available/tu-vhost /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

## Instalación

### Opción rápida: `install.sh`

Ruta recomendada: `/var/www/html` (el DocumentRoot por defecto de la mayoría de
las instalaciones de Apache/nginx — evita tener que inventar una ruta nueva y
armar el vhost desde cero). Si tu Apache/nginx recién instalado ya dejó algo
ahí (una página de bienvenida placeholder), hay que vaciarlo primero:

```bash
rm -rf /var/www/html/* /var/www/html/.[!.]*
git clone https://github.com/zhaiks182/cod2-ranking.git /var/www/html
cd /var/www/html
chmod +x install.sh && ./install.sh
```

> Si preferís otra ruta (por ejemplo para tener varios sitios en el mismo VPS,
> como `/var/www/tu-dominio.com`), cloná ahí en vez de `/var/www/html` — el
> resto de la guía funciona igual, `install.sh` se auto-detecta.

Pide por prompts todo lo necesario (dominio, base de datos, datos del server de
CoD2, usuario/contraseña del panel admin) y hace el resto: `composer install`,
`.env`, creación de la base de datos, migraciones, `storage:link`, permisos,
el cron del parser, y la descarga inicial de GeoIP. Al final imprime un
resumen con las credenciales — el panel queda en `/adm_cod2/login`.

### Manual, paso a paso

```bash
rm -rf /var/www/html/* /var/www/html/.[!.]*
git clone https://github.com/zhaiks182/cod2-ranking.git /var/www/html
cd /var/www/html
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

> Si usaste `install.sh`, este paso ya quedó hecho — el script configura el
> cron por vos. Es solo para el camino manual.

El scheduler de Laravel necesita correr cada minuto vía el cron del sistema:

```cron
* * * * * cd /ruta/al/proyecto && php artisan schedule:run >> /dev/null 2>&1
```

Esto dispara automáticamente `cod2:parse-log` cada minuto y `geoip:update` una
vez al mes.

### GeoIP (opcional, para las banderas de país)

> `install.sh` ya corre esto al final de la instalación. Es solo para el
> camino manual, o para volver a correrlo más adelante.

```bash
php artisan geoip:update
```

Descarga la base de datos de países de DB-IP a `storage/app/geoip/country.mmdb`.
Sin este paso, el sitio funciona igual pero no muestra banderas de país.

### Panel de administración

Entrar a `/adm_cod2/login`. Con `install.sh`, el usuario y la contraseña son
los que elegiste durante la instalación. Por el camino manual, el usuario es
`adm_cod2` y la contraseña es la que imprimió `php artisan migrate` — cambiala
cuanto antes desde `/adm_cod2/password`. Desde ahí se agregan y editan los
servidores CoD2 (ver "Multi-servidor" arriba), incluido el primero.

Desde `/adm_cod2/console/{server}` también hay botones para **reiniciar,
detener e iniciar** el servicio systemd del gameserver (`servers.systemd_service`,
por defecto `cod2server.service`). Para que funcionen, el usuario del server web
(`www-data` en Debian/Ubuntu, `apache` en RHEL/CentOS) necesita permiso de
`sudo` sin contraseña para esos 3 comandos exactos — `install.sh` lo configura
solo si el servicio del gameserver ya existe en la máquina al momento de
instalar. Si instalaste `cod2-ranking` **antes** que `cod2-server`, o en otra
máquina, corré esto a mano una vez que exista el servicio (reemplazando
`www-data` y `cod2server.service` si tu setup usa otros nombres):

```bash
echo 'www-data ALL=(root) NOPASSWD: /usr/bin/systemctl restart cod2server.service, /usr/bin/systemctl stop cod2server.service, /usr/bin/systemctl start cod2server.service' > /tmp/cod2-panel-sudoers
visudo -cf /tmp/cod2-panel-sudoers && sudo install -m 0440 -o root -g root /tmp/cod2-panel-sudoers /etc/sudoers.d/cod2-panel
```

### Respaldos y migración a otro servidor

`/adm_cod2/respaldos` tiene todo lo necesario para mover el sitio entero a otra
máquina:

- **Crear respaldo** — volcado completo de la base de datos (`mysqldump`
  comprimido), todos los módulos (partidas, jugadores, demos, bans, auditoría,
  configuración). También corre uno automático por día, borrando los que
  tengan más de 10 días.
- **Restaurar** — reemplaza la base de datos actual por un respaldo ya
  guardado en ese mismo server.
- **Importar** — subís un `.sql`/`.sql.gz` desde tu computadora (por ejemplo,
  uno que bajaste con "Descargar" desde el server viejo) y lo importa entero.
  Funciona aunque la base de datos destino esté completamente vacía —
  `mysqldump` incluye el esquema (`CREATE TABLE`) además de los datos, no
  hace falta correr `php artisan migrate` antes.

> ⚠️ **Al importar/restaurar en un server CON UN `APP_KEY` DISTINTO al que
> generó el respaldo, el sitio se rompe** (`DecryptException: The MAC is
> invalid`, apenas intenta leer la contraseña RCON de algún servidor CoD2 —
> `servers.rcon_password` se guarda encriptado con `APP_KEY`, no en texto
> plano). Pasa siempre que migrás a una instalación nueva, porque
> `install.sh`/`php artisan key:generate` generan una `APP_KEY` propia cada
> vez. **Arreglo:** copiar el `APP_KEY` del server de origen al `.env` del
> server de destino (reemplazando la línea entera) y correr
> `php artisan config:clear`:
>
> ```bash
> # en el server de origen, ver la key actual:
> grep APP_KEY .env
>
> # en el server de destino, pegar esa misma linea en .env (reemplazando la
> # que ya estaba), despues:
> php artisan config:clear
> ```
>
> Con esto no hace falta re-escribir ninguna contraseña RCON a mano — vuelven
> a leerse bien apenas coincide la key.

## Servir la aplicación

Cualquier método estándar de Laravel sirve — Apache/Nginx + PHP-FPM apuntando a
`public/`, o para probar rápido:

```bash
php artisan serve
```

## Licencia

Sin licencia definida todavía.
