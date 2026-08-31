@extends('layouts.admin')

@section('title', 'Usuarios')

@section('content')
<div class="space-y-4">
    <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
            <h1 class="text-lg font-semibold">Usuarios</h1>
            <p class="text-xs text-slate-500 mt-1">Cuentas del panel admin y a qué módulos tiene acceso cada una. Solo super-admins ven esta pantalla.</p>
        </div>
        <a href="{{ route('admin.users.create') }}" class="px-3 py-1.5 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-white text-sm font-semibold">+ Nuevo usuario</a>
    </div>

    <div class="rounded-xl border border-slate-800 bg-panel overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-[11px] uppercase tracking-wide text-slate-500 border-b border-slate-800">
                    <th class="px-4 py-2 font-medium">Usuario</th>
                    <th class="px-4 py-2 font-medium">Acceso</th>
                    <th class="px-4 py-2 font-medium text-right">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @foreach($users as $u)
                    <tr class="border-b border-slate-800/60 last:border-0">
                        <td class="px-4 py-2 font-medium">
                            {{ $u->username }}
                            @if($u->id === auth()->id())
                                <span class="text-slate-600 text-xs">(vos)</span>
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            @if($u->is_super_admin)
                                <span class="px-1.5 py-0.5 rounded bg-amber-950 text-amber-400 border border-amber-900 text-[10px] uppercase tracking-wide">Super-admin</span>
                            @elseif(empty($u->permissions))
                                <span class="text-slate-600 text-xs">Sin módulos asignados</span>
                            @else
                                <div class="flex flex-wrap gap-1">
                                    @foreach($u->permissions as $mod)
                                        <span class="px-1.5 py-0.5 rounded bg-slate-800 text-slate-300 text-[10px]">{{ \App\Models\User::MODULES[$mod] ?? $mod }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            <a href="{{ route('admin.users.edit', $u) }}" class="text-cyan-400 hover:underline text-xs">Editar</a>
                            @if($u->id !== auth()->id())
                                <form method="POST" action="{{ route('admin.users.destroy', $u) }}" class="inline" onsubmit="return confirm('¿Borrar al usuario {{ $u->username }}? No se puede deshacer.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="ml-3 text-red-400 hover:underline text-xs">Borrar</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </div>
</div>
@endsection
