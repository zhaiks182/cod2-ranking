@extends('layouts.app')

@section('title', __('Notificaciones'))

@section('content')
<div class="max-w-2xl mx-auto space-y-6">
    <h1 class="text-lg font-semibold">{{ __('Notificaciones') }}</h1>

    @if($notifications->isEmpty())
        <div class="rounded-xl border border-slate-800 bg-panel px-4 py-10 text-center text-sm text-slate-500">
            {{ __('No tenés notificaciones todavía.') }}
        </div>
    @else
        <div class="rounded-xl border border-slate-800 bg-panel divide-y divide-slate-800/60">
            @foreach($notifications as $notification)
                <a href="{{ route('gallery.show', $notification->data['gallery_item_id']) }}" class="block px-4 py-3 hover:bg-slate-800/30 {{ $notification->read_at ? '' : 'bg-slate-800/20' }}">
                    <span class="text-sm text-slate-200">
                        <span class="font-medium">{{ $notification->data['actor_name'] }}</span>
                        {{ __('comentó tu video/imagen') }} "<span class="text-slate-300">{{ $notification->data['gallery_item_title'] }}</span>"
                    </span>
                    <div class="text-xs text-slate-500 mt-0.5">{{ $notification->created_at->diffForHumans() }}</div>
                </a>
            @endforeach
        </div>

        {{ $notifications->links() }}
    @endif
</div>
@endsection
