#!/usr/bin/env bash
# Instalador de CoD2 Ranking en un servidor LAMP.
# Ejecutar como root (o un usuario con sudo a mysql/composer/chown), desde
# dentro de la carpeta del proyecto ya clonado:
#   chmod +x install.sh && ./install.sh

set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$APP_DIR"

echo "== CoD2 Ranking — instalador =="
echo "Corre composer install, configura .env, crea la base de datos, corre las"
echo "migraciones, y deja un usuario admin listo para el panel."
echo

DOMAIN_DEFAULT="localhost"
read -rp "Dominio o IP pública del sitio (para APP_URL) [$DOMAIN_DEFAULT]: " DOMAIN
DOMAIN="${DOMAIN:-$DOMAIN_DEFAULT}"

DB_NAME_DEFAULT="cod2_stats"
read -rp "Nombre de la base de datos [$DB_NAME_DEFAULT]: " DB_NAME
DB_NAME="${DB_NAME:-$DB_NAME_DEFAULT}"

DB_USER_DEFAULT="cod2_user"
read -rp "Usuario MySQL a crear [$DB_USER_DEFAULT]: " DB_USER
DB_USER="${DB_USER:-$DB_USER_DEFAULT}"

DB_PASS_AUTO="$(openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | head -c 24)"
read -rp "Contraseña MySQL [presiona Enter para generar una aleatoria]: " DB_PASS
DB_PASS="${DB_PASS:-$DB_PASS_AUTO}"

echo
echo "-- Datos del servidor de CoD2 (mod CoD2x + zPAM) --"
LOG_PATH_DEFAULT="/home/gameserver/1.3/puG/main/games_mp.log"
read -rp "Ruta a games_mp.log [$LOG_PATH_DEFAULT]: " COD2_LOG_PATH
COD2_LOG_PATH="${COD2_LOG_PATH:-$LOG_PATH_DEFAULT}"

read -rp "IP pública de conexión al server CoD2 (la que verán los jugadores): " COD2_CONNECT_IP

COD2_PORT_DEFAULT="28960"
read -rp "Puerto de conexión [$COD2_PORT_DEFAULT]: " COD2_CONNECT_PORT
COD2_CONNECT_PORT="${COD2_CONNECT_PORT:-$COD2_PORT_DEFAULT}"

RCON_HOST_DEFAULT="127.0.0.1"
read -rp "Host RCON (127.0.0.1 si el gameserver corre en esta misma máquina) [$RCON_HOST_DEFAULT]: " COD2_RCON_HOST
COD2_RCON_HOST="${COD2_RCON_HOST:-$RCON_HOST_DEFAULT}"

read -rp "Puerto RCON [$COD2_CONNECT_PORT]: " COD2_RCON_PORT
COD2_RCON_PORT="${COD2_RCON_PORT:-$COD2_CONNECT_PORT}"

while true; do
    read -rsp "Contraseña RCON del server CoD2: " COD2_RCON_PASSWORD
    echo
    if [[ -n "$COD2_RCON_PASSWORD" ]]; then break; fi
    echo "No puede quedar vacía."
done

TIMEZONE_DEFAULT="America/Guayaquil"
read -rp "Zona horaria de tu comunidad (lista: https://www.php.net/manual/en/timezones.php) [$TIMEZONE_DEFAULT]: " APP_TIMEZONE
APP_TIMEZONE="${APP_TIMEZONE:-$TIMEZONE_DEFAULT}"

echo
echo "-- Panel de administración --"
ADMIN_USER_DEFAULT="admin"
read -rp "Usuario admin del panel [$ADMIN_USER_DEFAULT]: " ADMIN_USER
ADMIN_USER="${ADMIN_USER:-$ADMIN_USER_DEFAULT}"

while true; do
    read -rsp "Contraseña del admin del panel: " ADMIN_PASS
    echo
    if [[ -z "$ADMIN_PASS" ]]; then
        echo "La contraseña no puede quedar vacía."
        continue
    fi
    read -rsp "Confirma la contraseña: " ADMIN_PASS_CONFIRM
    echo
    if [[ "$ADMIN_PASS" != "$ADMIN_PASS_CONFIRM" ]]; then
        echo "Las contraseñas no coinciden, intenta de nuevo."
        continue
    fi
    break
done
unset ADMIN_PASS_CONFIRM

echo
echo "Resumen:"
echo "  Carpeta del proyecto : $APP_DIR"
echo "  Dominio/IP del sitio : $DOMAIN"
echo "  Base de datos        : $DB_NAME"
echo "  Usuario DB           : $DB_USER"
echo "  Log de CoD2          : $COD2_LOG_PATH"
echo "  Usuario admin panel  : $ADMIN_USER"
echo
read -rp "¿Continuar? [s/N]: " CONFIRM
if [[ "${CONFIRM,,}" != "s" ]]; then
    echo "Cancelado."
    exit 0
fi

echo
echo "== Instalando dependencias (composer) =="
composer install --no-dev --optimize-autoloader

echo "== Escribiendo .env =="
if [[ ! -f .env ]]; then
    cp .env.example .env
fi

set_env() {
    local key="$1" value="$2"
    if grep -q "^${key}=" .env; then
        sed -i "s#^${key}=.*#${key}=${value}#" .env
    else
        echo "${key}=${value}" >> .env
    fi
}

set_env "APP_URL" "http://$DOMAIN"
set_env "APP_TIMEZONE" "$APP_TIMEZONE"
set_env "DB_CONNECTION" "mysql"
set_env "DB_HOST" "127.0.0.1"
set_env "DB_PORT" "3306"
set_env "DB_DATABASE" "$DB_NAME"
set_env "DB_USERNAME" "$DB_USER"
set_env "DB_PASSWORD" "$DB_PASS"
set_env "COD2_LOG_PATH" "$COD2_LOG_PATH"
set_env "COD2_CONNECT_IP" "$COD2_CONNECT_IP"
set_env "COD2_CONNECT_PORT" "$COD2_CONNECT_PORT"
set_env "COD2_RCON_HOST" "$COD2_RCON_HOST"
set_env "COD2_RCON_PORT" "$COD2_RCON_PORT"
set_env "COD2_RCON_PASSWORD" "$COD2_RCON_PASSWORD"

php artisan key:generate --force

echo "== Creando base de datos y usuario MySQL =="
mysql -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

echo "== Corriendo migraciones =="
php artisan migrate --force

echo "== Enlazando storage público (imágenes de mapa, íconos de arma) =="
php artisan storage:link

echo "== Configurando usuario admin del panel =="
# Las migraciones ya crearon una fila admin con una contraseña aleatoria (ver
# database/migrations/2026_08_10_090006_create_default_admin_user.php) —
# este comando la actualiza con el usuario/contraseña que se acaban de pedir.
php artisan cod2:admin "$ADMIN_USER" "$ADMIN_PASS"
unset ADMIN_PASS

echo "== Ajustando permisos =="
WEB_USER="www-data"
if id "apache" &>/dev/null && ! id "www-data" &>/dev/null; then
    WEB_USER="apache"
fi
chown -R "$WEB_USER":"$WEB_USER" "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 755 {} \;
find "$APP_DIR" -type f -exec chmod 644 {} \;
# storage/ y bootstrap/cache/ necesitan ser escribibles por el propio server web
# (logs, cache de vistas, sesiones, subida de imágenes de mapa) — 755/644 arriba
# los deja de solo lectura, así que se relajan explícitamente después.
chmod -R 775 "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"

echo "== Configurando sudoers para los botones de control del servicio del panel =="
# El panel (ConsoleController@service, /adm_cod2/console/{server}) hace "sudo
# systemctl <start|stop|restart> cod2server.service" corriendo como $WEB_USER
# para poder reiniciar/detener/iniciar el gameserver desde la web. Sin esta
# regla, esos botones fallan con "sudo: a password is required" (visto en un
# install limpio el 2026-08-20) — quedaba documentado solo en CLAUDE.md, nunca
# se automatizó acá. Acotado a EXACTAMENTE esas 3 combinaciones, nada de
# wildcards ni otros servicios/acciones.
SYSTEMCTL_BIN="$(command -v systemctl || echo /usr/bin/systemctl)"
GAMESERVER_SERVICE="cod2server.service"
if systemctl list-unit-files "$GAMESERVER_SERVICE" &>/dev/null; then
    SUDOERS_RULE="$WEB_USER ALL=(root) NOPASSWD: $SYSTEMCTL_BIN restart $GAMESERVER_SERVICE, $SYSTEMCTL_BIN stop $GAMESERVER_SERVICE, $SYSTEMCTL_BIN start $GAMESERVER_SERVICE"
    echo "$SUDOERS_RULE" > /tmp/cod2-panel-sudoers
    if visudo -cf /tmp/cod2-panel-sudoers &>/dev/null; then
        install -m 0440 -o root -g root /tmp/cod2-panel-sudoers /etc/sudoers.d/cod2-panel
        echo "  Listo: /etc/sudoers.d/cod2-panel"
    else
        echo "  ADVERTENCIA: la regla de sudoers generada no pasó 'visudo -c', no se instaló. Revisa a mano."
    fi
    rm -f /tmp/cod2-panel-sudoers
else
    echo "  $GAMESERVER_SERVICE no existe todavía en este host (¿instalaste cod2-server en otra"
    echo "  máquina?) — se omite. Cuando exista el servicio, corre esto para habilitar los"
    echo "  botones de Reiniciar/Detener/Iniciar del panel:"
    echo "    echo '$WEB_USER ALL=(root) NOPASSWD: $SYSTEMCTL_BIN restart $GAMESERVER_SERVICE, $SYSTEMCTL_BIN stop $GAMESERVER_SERVICE, $SYSTEMCTL_BIN start $GAMESERVER_SERVICE' > /tmp/cod2-panel-sudoers"
    echo "    visudo -cf /tmp/cod2-panel-sudoers && install -m 0440 -o root -g root /tmp/cod2-panel-sudoers /etc/sudoers.d/cod2-panel"
fi

echo "== Configurando cron (schedule:run cada minuto) =="
# Dispara cod2:parse-log cada minuto y geoip:update una vez al mes (ver
# routes/console.php) — un solo cron de sistema alcanza para ambos.
CRON_LINE="* * * * * cd $APP_DIR && php artisan schedule:run >> /dev/null 2>&1"
( crontab -l 2>/dev/null | grep -vF "$APP_DIR" || true; echo "$CRON_LINE" ) | crontab -

echo "== Descargando base de datos GeoIP (opcional, para banderas de país) =="
php artisan geoip:update || echo "  No se pudo descargar ahora — no es crítico, reintentar luego con: php artisan geoip:update"

echo
echo "== Instalación completa =="
echo "  Proyecto            : $APP_DIR"
echo "                        (el DocumentRoot de tu VirtualHost debe apuntar a $APP_DIR/public)"
echo "  Base de datos        : $DB_NAME"
echo "  Usuario DB           : $DB_USER"
echo "  Contraseña DB        : $DB_PASS"
echo "  Usuario admin panel  : $ADMIN_USER (la contraseña es la que ingresaste, no se vuelve a mostrar)"
echo
echo "Guarda la contraseña de la base de datos ahora; no vuelve a mostrarse."
echo "Panel de administración: http://$DOMAIN/adm_cod2/login"
