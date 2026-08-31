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
     * "servers" incluye la consola RCON y la config de servidores temporales
     * porque esas dos viven DENTRO de /adm_cod2/servers, no en pantallas
     * propias -- separarlas hubiera sido granularidad sin caso de uso real.
     *
     * @var array<string, string>
     */
    public const MODULES = [
        'servers' => 'Servidores (incluye consola RCON)',
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
