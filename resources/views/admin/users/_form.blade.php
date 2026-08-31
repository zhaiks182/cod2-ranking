@if($errors->any())
    <div class="rounded-lg border border-red-900 bg-red-950/40 px-3 py-2 text-sm text-red-300">
        @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
    </div>
@endif

<div>
    <label class="block text-[11px] uppercase tracking-wide text-slate-500 mb-1">Usuario</label>
    <input type="text" name="username" value="{{ old('username', $user->username ?? '') }}" required autocomplete="off" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-slate-200 font-mono text-sm">
</div>

<div>
    <label class="block text-[11px] uppercase tracking-wide text-slate-500 mb-1">Contraseña {{ isset($user) ? '(dejar vacío para no cambiarla)' : '' }}</label>
    <input type="password" name="password" autocomplete="new-password" class="w-full bg-panel2 border border-slate-700 rounded-lg px-3 py-2 text-slate-200 font-mono text-sm">
    <p class="text-xs text-slate-600 mt-1">Mínimo 8 caracteres.</p>
</div>

<div>
    <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" name="is_super_admin" value="1" id="cod2-super-admin-toggle" {{ old('is_super_admin', $user->is_super_admin ?? false) ? 'checked' : '' }}
            onchange="document.getElementById('cod2-modules-block').classList.toggle('opacity-40', this.checked); document.getElementById('cod2-modules-block').classList.toggle('pointer-events-none', this.checked)"
            class="rounded border-slate-700 bg-panel2 text-cyan-500">
        <span class="text-slate-200 font-medium">Super-admin</span>
    </label>
    <p class="text-xs text-slate-600 mt-1 ml-6">Acceso total a todo el panel, incluida esta pantalla de usuarios. Ignora los módulos de abajo.</p>
</div>

<div id="cod2-modules-block" class="{{ old('is_super_admin', $user->is_super_admin ?? false) ? 'opacity-40 pointer-events-none' : '' }}">
    <label class="block text-[11px] uppercase tracking-wide text-slate-500 mb-2">Módulos permitidos</label>
    <div class="grid sm:grid-cols-2 gap-2">
        @foreach(\App\Models\User::MODULES as $key => $label)
            @php $checked = in_array($key, old('permissions', $user->permissions ?? []), true); @endphp
            <label class="flex items-center gap-2 text-sm bg-panel2 border border-slate-700 rounded-lg px-3 py-2">
                <input type="checkbox" name="permissions[]" value="{{ $key }}" {{ $checked ? 'checked' : '' }} class="rounded border-slate-700 bg-panel text-cyan-500">
                <span class="text-slate-300">{{ $label }}</span>
            </label>
        @endforeach
    </div>
</div>
