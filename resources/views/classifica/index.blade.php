@extends('layouts.public')

@section('title', 'Classifica — Le Cercle Tennis Club')

@section('content')

<div class="mb-6">
    <h1 class="text-2xl font-bold text-green-800">Classifica giocatori</h1>
    <p class="text-gray-500 text-sm mt-1">Aggiornata in tempo reale dopo ogni partita confermata.</p>
</div>

@if($players->isEmpty())
    <div class="bg-white rounded-xl shadow p-8 text-center text-gray-400">
        Nessuna partita disputata ancora. Torna presto!
    </div>
@else
<div class="bg-white rounded-xl shadow overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-green-700 text-white text-xs uppercase tracking-wide">
            <tr>
                <th class="px-4 py-3 text-left w-10">#</th>
                <th class="px-4 py-3 text-left">Giocatore</th>
                <th class="px-4 py-3 text-center">ELO</th>
                <th class="px-4 py-3 text-center">Partite</th>
                <th class="px-4 py-3 text-center">Vittorie</th>
                <th class="px-4 py-3 text-center">Win %</th>
                <th class="px-4 py-3 text-left">Livello</th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @foreach($players as $i => $player)
            @php
                $winPct = $player->matches_played > 0
                    ? round($player->matches_won / $player->matches_played * 100)
                    : 0;
                $medal = match($i) {
                    0 => '🥇',
                    1 => '🥈',
                    2 => '🥉',
                    default => $i + 1,
                };
            @endphp
            <tr class="hover:bg-green-50 transition-colors">
                <td class="px-4 py-3 font-bold text-gray-500 text-center">{{ $medal }}</td>
                <td class="px-4 py-3 font-semibold">{{ $player->name }}</td>
                <td class="px-4 py-3 text-center">
                    <span class="inline-block bg-green-100 text-green-800 font-bold px-2 py-0.5 rounded-full text-xs">
                        {{ $player->elo_rating }}
                    </span>
                </td>
                <td class="px-4 py-3 text-center text-gray-600">{{ $player->matches_played }}</td>
                <td class="px-4 py-3 text-center text-gray-600">{{ $player->matches_won }}</td>
                <td class="px-4 py-3 text-center">
                    <div class="flex items-center gap-1 justify-center">
                        <div class="w-16 bg-gray-200 rounded-full h-1.5">
                            <div class="bg-green-500 h-1.5 rounded-full" style="width: {{ $winPct }}%"></div>
                        </div>
                        <span class="text-xs text-gray-500">{{ $winPct }}%</span>
                    </div>
                </td>
                <td class="px-4 py-3 text-gray-500 text-xs">
                    @if($player->is_fit && $player->fit_rating)
                        FIT {{ $player->fit_rating }}
                    @elseif($player->self_level)
                        {{ ucfirst($player->self_level) }}
                    @else
                        —
                    @endif
                </td>
                <td class="px-4 py-3 text-right">
                    <a href="{{ route('giocatore', $player->id) }}"
                       class="text-green-700 hover:underline text-xs font-medium">
                        Profilo →
                    </a>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

@endsection
