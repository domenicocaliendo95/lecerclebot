@extends('layouts.public')

@section('title', $player->name . ' — Le Cercle Tennis Club')

@section('content')

{{-- Back --}}
<a href="{{ route('classifica') }}" class="text-green-700 text-sm hover:underline">← Classifica</a>

{{-- Header card --}}
<div class="mt-4 bg-white rounded-xl shadow p-6 flex flex-col sm:flex-row sm:items-center gap-4">
    <div class="flex-1">
        <div class="flex items-center gap-2">
            <h1 class="text-2xl font-bold">{{ $player->name }}</h1>
            @if($rank <= 3)
                <span class="text-xl">{{ ['🥇','🥈','🥉'][$rank-1] }}</span>
            @endif
        </div>
        <p class="text-gray-500 text-sm mt-1">
            @if($player->is_fit && $player->fit_rating)
                Tesserato FIT — classifica <strong>{{ $player->fit_rating }}</strong>
            @elseif($player->self_level)
                Livello: <strong>{{ ucfirst($player->self_level) }}</strong>
            @endif
        </p>
    </div>
    <div class="flex gap-6 text-center">
        <div>
            <div class="text-3xl font-bold text-green-700">{{ $player->elo_rating }}</div>
            <div class="text-xs text-gray-400 uppercase tracking-wide">ELO</div>
        </div>
        <div>
            <div class="text-3xl font-bold text-gray-700">{{ $rank }}°</div>
            <div class="text-xs text-gray-400 uppercase tracking-wide">Posizione</div>
        </div>
        <div>
            <div class="text-3xl font-bold text-gray-700">{{ $player->matches_played }}</div>
            <div class="text-xs text-gray-400 uppercase tracking-wide">Partite</div>
        </div>
        <div>
            @php $winPct = $player->matches_played > 0 ? round($player->matches_won / $player->matches_played * 100) : 0; @endphp
            <div class="text-3xl font-bold text-gray-700">{{ $winPct }}%</div>
            <div class="text-xs text-gray-400 uppercase tracking-wide">Vittorie</div>
        </div>
    </div>
</div>

<div class="mt-6 grid grid-cols-1 lg:grid-cols-2 gap-6">

    {{-- ELO history chart --}}
    <div class="bg-white rounded-xl shadow p-6">
        <h2 class="font-semibold text-gray-700 mb-4">Andamento ELO</h2>
        @if($eloHistory->isEmpty())
            <p class="text-gray-400 text-sm">Nessuna variazione ELO registrata ancora.</p>
        @else
        {{-- Simple SVG line chart --}}
        @php
            $points   = $eloHistory->map(fn($e) => $e->elo_after)->values()->toArray();
            $labels   = $eloHistory->map(fn($e) => $e->created_at->format('d/m'))->values()->toArray();
            $min      = min($points) - 50;
            $max      = max($points) + 50;
            $range    = $max - $min ?: 1;
            $w        = 400;
            $h        = 150;
            $n        = count($points);
            $coords   = collect($points)->map(function($v, $i) use ($n, $w, $h, $min, $range) {
                $x = $n > 1 ? ($i / ($n - 1)) * $w : $w / 2;
                $y = $h - (($v - $min) / $range) * $h;
                return "$x,$y";
            })->implode(' ');
        @endphp
        <div class="overflow-x-auto">
        <svg viewBox="0 0 {{ $w }} {{ $h + 20 }}" class="w-full" preserveAspectRatio="none">
            {{-- Grid lines --}}
            @foreach([0, 0.5, 1] as $t)
            <line x1="0" y1="{{ $h - $t * $h }}" x2="{{ $w }}" y2="{{ $h - $t * $h }}"
                  stroke="#e5e7eb" stroke-width="1"/>
            <text x="2" y="{{ $h - $t * $h - 2 }}" font-size="8" fill="#9ca3af">
                {{ round($min + $t * $range) }}
            </text>
            @endforeach
            {{-- Line --}}
            <polyline points="{{ $coords }}" fill="none" stroke="#15803d" stroke-width="2" stroke-linejoin="round"/>
            {{-- Dots + labels --}}
            @foreach($points as $i => $v)
            @php
                $x = $n > 1 ? ($i / ($n - 1)) * $w : $w / 2;
                $y = $h - (($v - $min) / $range) * $h;
            @endphp
            <circle cx="{{ $x }}" cy="{{ $y }}" r="3" fill="#15803d"/>
            <text x="{{ $x }}" y="{{ $h + 15 }}" font-size="7" fill="#6b7280" text-anchor="middle">
                {{ $labels[$i] }}
            </text>
            @endforeach
        </svg>
        </div>

        {{-- Last 5 changes --}}
        <ul class="mt-3 space-y-1 text-xs text-gray-600">
            @foreach($eloHistory->reverse()->take(5) as $entry)
            <li class="flex justify-between">
                <span>{{ $entry->created_at->format('d/m/Y') }}</span>
                <span class="{{ $entry->delta >= 0 ? 'text-green-600' : 'text-red-500' }} font-semibold">
                    {{ $entry->delta >= 0 ? '+' : '' }}{{ $entry->delta }}
                </span>
                <span class="text-gray-400">→ {{ $entry->elo_after }}</span>
            </li>
            @endforeach
        </ul>
        @endif
    </div>

    {{-- Recent matches --}}
    <div class="bg-white rounded-xl shadow p-6">
        <h2 class="font-semibold text-gray-700 mb-4">Ultime partite</h2>
        @if($recentMatches->isEmpty())
            <p class="text-gray-400 text-sm">Nessuna partita completata ancora.</p>
        @else
        <ul class="space-y-2">
            @foreach($recentMatches as $booking)
            @php
                $isPlayer1 = $booking->player1_id === $player->id;
                $opponent  = $isPlayer1 ? $booking->player2 : $booking->player1;
                $result    = $booking->result;
                $won       = $result && $result->winner_id === $player->id;
                $lost      = $result && $result->winner_id !== null && $result->winner_id !== $player->id;
            @endphp
            <li class="flex items-center justify-between text-sm border-b border-gray-100 pb-2">
                <div>
                    <span class="font-medium">vs {{ $opponent?->name ?? '—' }}</span>
                    <span class="text-gray-400 text-xs ml-2">{{ $booking->booking_date->format('d/m/Y') }}</span>
                </div>
                <div class="flex items-center gap-2">
                    @if($result?->score)
                        <span class="text-gray-500 text-xs">{{ $result->score }}</span>
                    @endif
                    @if($won)
                        <span class="bg-green-100 text-green-700 text-xs font-bold px-2 py-0.5 rounded-full">Vinta</span>
                    @elseif($lost)
                        <span class="bg-red-100 text-red-600 text-xs font-bold px-2 py-0.5 rounded-full">Persa</span>
                    @else
                        <span class="bg-gray-100 text-gray-500 text-xs px-2 py-0.5 rounded-full">—</span>
                    @endif
                </div>
            </li>
            @endforeach
        </ul>
        @endif
    </div>

</div>

@endsection
