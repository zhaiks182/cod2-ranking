<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'username', 'is_super_admin', 'permissions'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Modulos del panel admin (2026-08-31, sistema de roles) -- clave usada
     * tanto en `users.permissions` como en el middleware `module:<clave>`
     * que gatea cada grupo de rutas (ver bootstrap/app.php y routes/web.php).
     *
     * "servers" es SOLO consola RCON (kick/ban/mensaje/mapa/comando/reiniciar
     * servicio) y ver la lista de servers reales -- crear/editar/borrar un
     * server (toca la contraseña RCON de produccion) quedo reservado a
     * super-admin desde el 2026-09-01, ver "Modulo servers no debe permitir
     * editar/borrar el server" en CLAUDE.md: un modulo otorgable de un
     * checkbox no debe poder tocar la config del gameserver real.
     *
     * "hosted-servers" (servidores temporales self-service) se separo de
     * "servers" el mismo dia, a pedido del dueño -- antes vivian juntos
     * porque la config de puertos estaba embebida en /adm_cod2/servers, pero
     * son dos responsabilidades distintas (el gameserver real de Pug Latam
     * vs. el feature publico de servers temporales) y alguien con acceso a
     * uno no necesariamente debe tener el otro.
     *
     * @var array<string, string>
     */
    public const MODULES = [
        'servers' => 'Servidores (consola RCON de Pug Latam)',
        'hosted-servers' => 'Servidores temporales (self-service)',
        'matches' => 'Partidas',
        'demos' => 'Demos',
        'maps' => 'Mapas',
        'players' => 'Jugadores (países, fusionar, borrar, íconos)',
        'bans' => 'Bans',
        'seasons' => 'Temporadas',
        'backups' => 'Respaldos',
        'discord' => 'Configuración de Discord',
        'audit' => 'Auditoría',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'permissions' => 'array',
        ];
    }

    /**
     * Un super-admin siempre tiene acceso a todo, sin importar `permissions`
     * -- garantiza que nunca quede un panel sin nadie que pueda arreglar los
     * permisos de los demas. Un usuario nuevo sin `permissions` asignado
     * (null) no tiene acceso a ningun modulo todavia.
     */
    public function hasModule(string $module): bool
    {
        if ($this->is_super_admin) {
            return true;
        }

        return in_array($module, $this->permissions ?? [], true);
    }
}
