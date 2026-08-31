<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Un super-admin ve y puede tocar TODO (incluida esta misma pantalla de
            // usuarios) sin importar lo que diga `permissions` -- necesario para que
            // exista al menos una cuenta que nunca pueda auto-bloquearse afuera de un
            // modulo por error de configuracion.
            $table->boolean('is_super_admin')->default(false)->after('username');
            // Lista de modulos (User::MODULES) a los que este usuario tiene acceso --
            // null/[] para un usuario nuevo (sin acceso a nada hasta que un
            // super-admin se lo asigne desde /adm_cod2/usuarios).
            $table->json('permissions')->nullable()->after('is_super_admin');
        });

        // El/los admin(s) que ya existian antes de este cambio quedan como
        // super-admin -- sin esto, el dueño quedaria bloqueado de su propio panel
        // apenas se despliegue esta migracion.
        DB::table('users')->update(['is_super_admin' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_super_admin', 'permissions']);
        });
    }
};
