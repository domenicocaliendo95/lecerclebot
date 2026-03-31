<x-filament-panels::page>
    @php
        $statusColors = [
            'confirmed'     => ['bg' => 'bg-emerald-500 dark:bg-emerald-600', 'hover' => 'hover:bg-emerald-600 dark:hover:bg-emerald-500', 'border' => 'border-l-emerald-700 dark:border-l-emerald-300'],
            'pending_match' => ['bg' => 'bg-amber-400 dark:bg-amber-500',     'hover' => 'hover:bg-amber-500 dark:hover:bg-amber-400',     'border' => 'border-l-amber-600 dark:border-l-amber-200'],
            'completed'     => ['bg' => 'bg-sky-500 dark:bg-sky-600',         'hover' => 'hover:bg-sky-600 dark:hover:bg-sky-500',         'border' => 'border-l-sky-700 dark:border-l-sky-300'],
        ];
        $defaultColor = ['bg' => 'bg-gray-400', 'hover' => 'hover:bg-gray-500', 'border' => 'border-l-gray-600'];
    @endphp

    <div
        x-data="calendarApp()"
        x-init="scrollToNow()"
        @keydown.escape.window="$wire.closeDetail()"
        class="space-y-4"
    >

        {{-- ── Header ─────────────────────────────────────────────────── --}}
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3 flex-wrap">
                {{-- Nav buttons --}}
                <div class="flex items-center bg-white dark:bg-white/5 rounded-lg shadow-sm border border-gray-200 dark:border-white/10 overflow-hidden">
                    <button wire:click="previousPeriod" class="px-3 py-2 hover:bg-gray-50 dark:hover:bg-white/5 transition">
                        <x-heroicon-m-chevron-left class="w-4 h-4 text-gray-600 dark:text-gray-400"/>
                    </button>
                    <button wire:click="goToToday" class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5 border-x border-gray-200 dark:border-white/10 transition">
                        Oggi
                    </button>
                    <button wire:click="nextPeriod" class="px-3 py-2 hover:bg-gray-50 dark:hover:bg-white/5 transition">
                        <x-heroicon-m-chevron-right class="w-4 h-4 text-gray-600 dark:text-gray-400"/>
                    </button>
                </div>

                {{-- View mode toggle --}}
                <div class="flex items-center bg-white dark:bg-white/5 rounded-lg shadow-sm border border-gray-200 dark:border-white/10 overflow-hidden text-sm">
                    <button wire:click="setViewMode('day')"
                        @class(['px-3 py-2 font-medium transition', 'bg-primary-500 text-white' => $viewMode === 'day', 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-white/5' => $viewMode !== 'day'])>
                        Giorno
                    </button>
                    <button wire:click="setViewMode('week')"
                        @class(['px-3 py-2 font-medium transition border-l border-gray-200 dark:border-white/10', 'bg-primary-500 text-white' => $viewMode === 'week', 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-white/5' => $viewMode !== 'week'])>
                        Settimana
                    </button>
                </div>

                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                    {{ $this->formattedDate }}
                </h2>
            </div>

            <a href="{{ \App\Filament\Resources\BookingResource::getUrl('create') }}"
               class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold shadow-sm transition bg-primary-600 text-white hover:bg-primary-500">
                <x-heroicon-m-plus class="w-4 h-4"/>
                Nuova prenotazione
            </a>
        </div>

        {{-- ── Week Strip (solo in vista giorno) ─────────────────────── --}}
        @if($viewMode === 'day')
        <div class="flex gap-1.5">
            @foreach($this->weekDays as $wd)
                <button
                    wire:click="setDate('{{ $wd['date'] }}')"
                    wire:key="wd-{{ $wd['date'] }}"
                    @class([
                        'flex-1 flex flex-col items-center py-2 rounded-xl text-xs font-medium transition-all duration-150 cursor-pointer',
                        'bg-primary-500 text-white shadow-md shadow-primary-500/25' => $wd['selected'],
                        'bg-white dark:bg-white/5 border border-gray-200 dark:border-white/10 text-gray-600 dark:text-gray-400 hover:border-primary-300 dark:hover:border-primary-500/50' => !$wd['selected'],
                        'ring-2 ring-primary-400 ring-offset-1 dark:ring-offset-gray-900' => $wd['today'] && !$wd['selected'],
                    ])
                >
                    <span class="text-[10px] uppercase tracking-wider opacity-70">{{ $wd['label'] }}</span>
                    <span class="text-lg font-bold leading-tight mt-0.5">{{ $wd['day'] }}</span>
                    @if($wd['count'] > 0)
                        <span @class(['mt-1 w-1.5 h-1.5 rounded-full', 'bg-white/70' => $wd['selected'], 'bg-primary-400' => !$wd['selected']])></span>
                    @endif
                </button>
            @endforeach
        </div>
        @endif

        {{-- ── Filtri + Stats ────────────────────────────────────────── --}}
        <div class="flex flex-col sm:flex-row sm:items-center gap-3">
            {{-- Filtro giocatore --}}
            <div class="relative">
                <x-heroicon-m-magnifying-glass class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400 pointer-events-none"/>
                <input type="text"
                       wire:model.live.debounce.300ms="filterPlayer"
                       placeholder="Cerca giocatore..."
                       class="pl-9 pr-3 py-2 text-sm rounded-lg border border-gray-200 dark:border-white/10 bg-white dark:bg-white/5 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-primary-500 focus:border-primary-500 w-full sm:w-56 transition">
            </div>

            {{-- Filtro stato --}}
            <div class="flex items-center gap-1.5">
                @foreach([
                    'confirmed'     => ['label' => 'Confermate', 'dot' => 'bg-emerald-500'],
                    'pending_match' => ['label' => 'In attesa',  'dot' => 'bg-amber-500'],
                    'completed'     => ['label' => 'Completate', 'dot' => 'bg-sky-500'],
                ] as $status => $meta)
                    @php $active = in_array($status, $filterStatuses); @endphp
                    <button
                        wire:click="toggleStatus('{{ $status }}')"
                        @class([
                            'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium border transition',
                            'bg-white dark:bg-white/10 border-gray-300 dark:border-white/20 text-gray-900 dark:text-white' => $active,
                            'bg-gray-50 dark:bg-white/5 border-gray-200 dark:border-white/10 text-gray-400 dark:text-gray-500' => !$active,
                        ])
                    >
                        <span class="w-2 h-2 rounded-full {{ $meta['dot'] }} {{ $active ? '' : 'opacity-30' }}"></span>
                        {{ $meta['label'] }}
                    </button>
                @endforeach
            </div>

            {{-- Stats compatte --}}
            <div class="flex items-center gap-4 ml-auto text-xs text-gray-500 dark:text-gray-400">
                <span><strong class="text-gray-900 dark:text-white">{{ $this->stats['total'] }}</strong> prenotazioni</span>
                <span class="text-emerald-600 dark:text-emerald-400"><strong>{{ $this->stats['confirmed'] }}</strong> conf.</span>
                <span class="text-amber-600 dark:text-amber-400"><strong>{{ $this->stats['pending'] }}</strong> attesa</span>
                <span class="text-violet-600 dark:text-violet-400"><strong>€{{ $this->stats['revenue'] }}</strong></span>
            </div>
        </div>

        {{-- ══════════════════════════════════════════════════════════════
             VISTA GIORNALIERA
             ══════════════════════════════════════════════════════════════ --}}
        @if($viewMode === 'day')
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-white/10 overflow-hidden">
            <div id="cal-timeline" class="overflow-y-auto overscroll-contain" style="max-height: 65vh;">
                <div class="relative" style="height: 1140px;">

                    {{-- Gridlines --}}
                    @for ($h = 8; $h <= 22; $h++)
                        <div class="absolute left-0 right-0 flex items-start" style="top: {{ ($h - 8) * 80 }}px;">
                            <div class="w-16 shrink-0 -mt-2 text-right pr-3">
                                <span class="text-[11px] font-medium text-gray-400 dark:text-gray-500 tabular-nums select-none">{{ sprintf('%02d:00', $h) }}</span>
                            </div>
                            <div class="flex-1 border-t border-gray-200 dark:border-white/[0.06]"></div>
                        </div>
                    @endfor
                    @for ($h = 8; $h < 22; $h++)
                        <div class="absolute left-16 right-0 border-t border-dashed border-gray-100 dark:border-white/[0.03]" style="top: {{ ($h - 8) * 80 + 40 }}px;"></div>
                    @endfor

                    {{-- Current time --}}
                    @if($this->currentTimePosition !== null && $this->selectedDate === today()->format('Y-m-d'))
                        <div class="absolute left-0 right-0 z-20 pointer-events-none" style="top: {{ $this->currentTimePosition }}px;">
                            <div class="flex items-center">
                                <div class="w-16 shrink-0 text-right pr-2">
                                    <span class="text-[10px] font-bold text-red-500 tabular-nums">{{ now()->format('H:i') }}</span>
                                </div>
                                <div class="relative flex-1">
                                    <div class="absolute -left-1.5 -top-1.5 w-3 h-3 rounded-full bg-red-500 shadow-sm shadow-red-500/50"></div>
                                    <div class="h-0.5 bg-red-500 shadow-sm shadow-red-500/30"></div>
                                </div>
                            </div>
                        </div>
                    @endif

                    {{-- Drop zone (behind bookings) --}}
                    <div class="absolute left-16 right-0 top-0 bottom-0 z-0"
                         @click="clickToCreate($event, '{{ $selectedDate }}')"
                         @dragover.prevent="dragOver($event)"
                         @dragleave="dragLeave()"
                         @drop.prevent="drop($event, '{{ $selectedDate }}')">
                    </div>

                    {{-- Drag ghost indicator --}}
                    <div x-show="draggingId && ghostTop !== null" x-cloak
                         class="absolute left-[4.25rem] right-3 z-5 rounded-lg border-2 border-dashed border-primary-400 bg-primary-500/10 pointer-events-none transition-[top] duration-75"
                         :style="`top: ${ghostTop}px; height: ${ghostHeight}px;`">
                    </div>

                    {{-- Booking blocks --}}
                    @forelse($this->bookings as $booking)
                        @php $c = $statusColors[$booking['status']] ?? $defaultColor; $isShort = $booking['height'] < 60; @endphp
                        <div
                            wire:click="selectBooking({{ $booking['id'] }})"
                            wire:key="booking-{{ $booking['id'] }}"
                            data-booking-block
                            data-booking-id="{{ $booking['id'] }}"
                            data-duration="{{ $booking['height'] }}"
                            draggable="true"
                            @dragstart="dragStart($event, {{ $booking['id'] }}, {{ $booking['height'] - 4 }})"
                            @dragend="dragEnd()"
                            :class="{ 'opacity-40 pointer-events-none': draggingId && draggingId != {{ $booking['id'] }} }"
                            class="absolute left-[4.25rem] right-3 z-10 rounded-lg border-l-4 {{ $c['bg'] }} {{ $c['hover'] }} {{ $c['border'] }}
                                   cursor-grab active:cursor-grabbing shadow-sm hover:shadow-lg hover:scale-x-[1.01] transition-all duration-150 overflow-hidden"
                            style="top: {{ $booking['top'] + 2 }}px; height: {{ $booking['height'] - 4 }}px;"
                        >
                            <div class="h-full px-3 py-1.5 flex {{ $isShort ? 'items-center gap-3' : 'flex-col justify-between' }}">
                                <div class="min-w-0">
                                    <div class="text-sm font-semibold text-white leading-tight truncate">
                                        {{ $booking['player1'] }}
                                        @if($booking['player2'])
                                            <span class="font-normal opacity-75">vs</span> {{ $booking['player2'] }}
                                        @endif
                                    </div>
                                    @unless($isShort)
                                        <div class="text-xs text-white/70 mt-0.5">{{ $booking['start'] }} – {{ $booking['end'] }}</div>
                                    @endunless
                                </div>
                                <div class="flex items-center gap-2 {{ $isShort ? 'ml-auto shrink-0' : '' }}">
                                    @if($booking['is_peak'])
                                        <span class="text-[10px] bg-white/20 text-white px-1.5 py-0.5 rounded font-medium">PEAK</span>
                                    @endif
                                    <span class="text-xs font-bold text-white/90 tabular-nums">€{{ number_format($booking['price'], 0) }}</span>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
                            <div class="text-center">
                                <x-heroicon-o-calendar class="w-16 h-16 text-gray-200 dark:text-gray-700 mx-auto mb-3"/>
                                <p class="text-sm text-gray-400 dark:text-gray-500 font-medium">Nessuna prenotazione</p>
                                <p class="text-xs text-gray-300 dark:text-gray-600 mt-1">Clicca su uno slot per crearne una</p>
                            </div>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- ══════════════════════════════════════════════════════════════
             VISTA SETTIMANALE
             ══════════════════════════════════════════════════════════════ --}}
        @else
        <div class="bg-white dark:bg-gray-900 rounded-xl shadow-sm border border-gray-200 dark:border-white/10 overflow-hidden">
            <div id="cal-timeline" class="overflow-y-auto overflow-x-auto overscroll-contain" style="max-height: 65vh;">
                <div class="flex" style="min-width: 800px;">

                    {{-- Time labels --}}
                    <div class="w-14 shrink-0 sticky left-0 z-20 bg-white dark:bg-gray-900">
                        <div class="h-10 border-b border-gray-200 dark:border-white/[0.06]"></div>
                        <div class="relative" style="height: 1120px;">
                            @for ($h = 8; $h <= 22; $h++)
                                <div class="absolute left-0 right-0 -mt-2 text-right pr-2" style="top: {{ ($h - 8) * 80 }}px;">
                                    <span class="text-[11px] font-medium text-gray-400 dark:text-gray-500 tabular-nums select-none">{{ sprintf('%02d:00', $h) }}</span>
                                </div>
                            @endfor
                        </div>
                    </div>

                    {{-- Day columns --}}
                    @foreach($this->weekDays as $dayIdx => $wd)
                        <div class="flex-1 min-w-[110px] border-l border-gray-200 dark:border-white/[0.06]" wire:key="week-col-{{ $wd['date'] }}">

                            {{-- Day header --}}
                            <div @class([
                                'h-10 sticky top-0 z-10 flex flex-col items-center justify-center border-b cursor-pointer transition',
                                'bg-primary-50 dark:bg-primary-900/20 border-gray-200 dark:border-white/[0.06]' => $wd['today'],
                                'bg-white dark:bg-gray-900 border-gray-200 dark:border-white/[0.06]' => !$wd['today'],
                            ]) wire:click="switchToDay('{{ $wd['date'] }}')">
                                <span class="text-[10px] uppercase tracking-wider {{ $wd['today'] ? 'text-primary-600 dark:text-primary-400' : 'text-gray-400 dark:text-gray-500' }}">{{ $wd['label'] }}</span>
                                <span @class([
                                    'text-sm font-bold leading-none',
                                    'text-primary-600 dark:text-primary-400' => $wd['today'],
                                    'text-gray-900 dark:text-white' => !$wd['today'],
                                ])>{{ $wd['day'] }}</span>
                            </div>

                            {{-- Day body --}}
                            <div class="relative" style="height: 1120px;">
                                {{-- Grid lines --}}
                                @for ($h = 8; $h <= 22; $h++)
                                    <div class="absolute left-0 right-0 border-t border-gray-100 dark:border-white/[0.04]" style="top: {{ ($h - 8) * 80 }}px;"></div>
                                @endfor
                                @for ($h = 8; $h < 22; $h++)
                                    <div class="absolute left-0 right-0 border-t border-dashed border-gray-50 dark:border-white/[0.02]" style="top: {{ ($h - 8) * 80 + 40 }}px;"></div>
                                @endfor

                                {{-- Current time line --}}
                                @if($this->todayColumnIndex === $dayIdx && $this->currentTimePosition !== null)
                                    <div class="absolute left-0 right-0 z-20 pointer-events-none" style="top: {{ $this->currentTimePosition }}px;">
                                        <div class="relative">
                                            <div class="absolute -left-1.5 -top-1.5 w-3 h-3 rounded-full bg-red-500"></div>
                                            <div class="h-0.5 bg-red-500"></div>
                                        </div>
                                    </div>
                                @endif

                                {{-- Drop zone --}}
                                <div class="absolute inset-0 z-0"
                                     @click="clickToCreate($event, '{{ $wd['date'] }}')"
                                     @dragover.prevent="dragOver($event)"
                                     @dragleave="dragLeave()"
                                     @drop.prevent="drop($event, '{{ $wd['date'] }}')">
                                </div>

                                {{-- Drag ghost --}}
                                <div x-show="draggingId && ghostTop !== null" x-cloak
                                     class="absolute left-1 right-1 z-5 rounded border-2 border-dashed border-primary-400 bg-primary-500/10 pointer-events-none transition-[top] duration-75"
                                     :style="`top: ${ghostTop}px; height: ${ghostHeight}px;`">
                                </div>

                                {{-- Bookings --}}
                                @foreach($this->weekBookings[$wd['date']] ?? [] as $booking)
                                    @php $c = $statusColors[$booking['status']] ?? $defaultColor; @endphp
                                    <div
                                        wire:click="selectBooking({{ $booking['id'] }})"
                                        wire:key="wb-{{ $booking['id'] }}"
                                        data-booking-block
                                        draggable="true"
                                        @dragstart="dragStart($event, {{ $booking['id'] }}, {{ max($booking['height'] - 2, 30) }})"
                                        @dragend="dragEnd()"
                                        :class="{ 'opacity-40 pointer-events-none': draggingId && draggingId != {{ $booking['id'] }} }"
                                        class="absolute left-1 right-1 z-10 rounded-md border-l-[3px] {{ $c['bg'] }} {{ $c['hover'] }} {{ $c['border'] }}
                                               cursor-grab active:cursor-grabbing shadow-sm hover:shadow-md transition-all duration-150 overflow-hidden"
                                        style="top: {{ $booking['top'] + 1 }}px; height: {{ $booking['height'] - 2 }}px;"
                                    >
                                        <div class="px-1.5 py-1 h-full">
                                            <div class="text-[11px] font-semibold text-white leading-tight truncate">{{ $booking['player1'] }}</div>
                                            @if($booking['height'] > 50)
                                                <div class="text-[10px] text-white/70 truncate">{{ $booking['start'] }}–{{ $booking['end'] }}</div>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
        @endif

        {{-- ── Slide-over: Overlay ───────────────────────────────────── --}}
        <div x-cloak x-show="$wire.selectedBooking"
             x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
             class="fixed inset-0 z-40 bg-black/20 dark:bg-black/40" @click="$wire.closeDetail()"></div>

        {{-- ── Slide-over: Panel ─────────────────────────────────────── --}}
        <div x-cloak x-show="$wire.selectedBooking"
             x-transition:enter="transform transition ease-out duration-300" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
             class="fixed top-0 right-0 h-full w-full sm:w-[26rem] z-50 bg-white dark:bg-gray-900 shadow-2xl border-l border-gray-200 dark:border-white/10 overflow-y-auto">

            @if($selectedBooking)
                @php
                    $sLabel = match($selectedBooking['status']) { 'confirmed' => 'Confermata', 'pending_match' => 'In attesa', 'completed' => 'Completata', default => $selectedBooking['status'] };
                    $sBadge = match($selectedBooking['status']) { 'confirmed' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400', 'pending_match' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400', 'completed' => 'bg-sky-100 text-sky-700 dark:bg-sky-900/30 dark:text-sky-400', default => 'bg-gray-100 text-gray-700' };
                    $sDot = match($selectedBooking['status']) { 'confirmed' => 'bg-emerald-500', 'pending_match' => 'bg-amber-500', 'completed' => 'bg-sky-500', default => 'bg-gray-500' };
                @endphp

                {{-- Header --}}
                <div class="sticky top-0 z-10 flex items-center justify-between px-5 py-4 bg-white/80 dark:bg-gray-900/80 backdrop-blur-sm border-b border-gray-200 dark:border-white/10">
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold {{ $sBadge }}">
                        <span class="w-1.5 h-1.5 rounded-full {{ $sDot }}"></span>
                        {{ $sLabel }}
                    </span>
                    <button wire:click="closeDetail" class="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 transition">
                        <x-heroicon-m-x-mark class="w-5 h-5"/>
                    </button>
                </div>

                <div class="px-5 py-6 space-y-6">
                    {{-- Time --}}
                    <div>
                        <div class="text-3xl font-bold text-gray-900 dark:text-white tabular-nums">{{ $selectedBooking['start'] }} – {{ $selectedBooking['end'] }}</div>
                        <div class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $selectedBooking['date'] }}</div>
                    </div>

                    {{-- Players --}}
                    <div class="space-y-3">
                        <h4 class="text-[11px] font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Giocatori</h4>
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-emerald-100 dark:bg-emerald-900/30 flex items-center justify-center shrink-0">
                                <span class="text-sm font-bold text-emerald-600 dark:text-emerald-400">{{ strtoupper(mb_substr($selectedBooking['player1'], 0, 1)) }}</span>
                            </div>
                            <div class="min-w-0">
                                <div class="text-sm font-semibold text-gray-900 dark:text-white truncate">{{ $selectedBooking['player1'] }}</div>
                                @if($selectedBooking['player1_phone'])
                                    <div class="text-xs text-gray-500 dark:text-gray-400 tabular-nums">{{ $selectedBooking['player1_phone'] }}</div>
                                @endif
                            </div>
                        </div>
                        @if($selectedBooking['player2'])
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-full bg-sky-100 dark:bg-sky-900/30 flex items-center justify-center shrink-0">
                                    <span class="text-sm font-bold text-sky-600 dark:text-sky-400">{{ strtoupper(mb_substr($selectedBooking['player2'], 0, 1)) }}</span>
                                </div>
                                <div class="min-w-0">
                                    <div class="text-sm font-semibold text-gray-900 dark:text-white truncate">{{ $selectedBooking['player2'] }}</div>
                                    @if($selectedBooking['player2_phone'])
                                        <div class="text-xs text-gray-500 dark:text-gray-400 tabular-nums">{{ $selectedBooking['player2_phone'] }}</div>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>

                    {{-- Details --}}
                    <div class="space-y-3">
                        <h4 class="text-[11px] font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">Dettagli</h4>
                        <div class="grid grid-cols-2 gap-3">
                            <div class="p-3 rounded-lg bg-gray-50 dark:bg-white/5">
                                <div class="text-[11px] text-gray-500 dark:text-gray-400">Prezzo</div>
                                <div class="text-lg font-bold text-gray-900 dark:text-white mt-0.5">€{{ $selectedBooking['price'] }}</div>
                            </div>
                            <div class="p-3 rounded-lg bg-gray-50 dark:bg-white/5">
                                <div class="text-[11px] text-gray-500 dark:text-gray-400">Tariffa</div>
                                <div class="text-lg font-bold text-gray-900 dark:text-white mt-0.5">{{ $selectedBooking['is_peak'] ? 'Peak' : 'Standard' }}</div>
                            </div>
                        </div>

                        @php
                            $payBadge = fn($s) => match($s) {
                                'paid'    => ['Pagato',    'text-emerald-700 bg-emerald-100 dark:text-emerald-400 dark:bg-emerald-900/20'],
                                'pending' => ['In attesa', 'text-amber-700 bg-amber-100 dark:text-amber-400 dark:bg-amber-900/20'],
                                default   => [$s,          'text-gray-700 bg-gray-100 dark:text-gray-400 dark:bg-white/5'],
                            };
                        @endphp

                        <div class="space-y-2">
                            @php [$pL1, $pC1] = $payBadge($selectedBooking['payment_p1']); @endphp
                            <div class="flex items-center justify-between p-3 rounded-lg bg-gray-50 dark:bg-white/5">
                                <span class="text-xs text-gray-500 dark:text-gray-400">Pagamento G1</span>
                                <span class="text-[11px] font-semibold px-2 py-0.5 rounded {{ $pC1 }}">{{ $pL1 }}</span>
                            </div>
                            @if($selectedBooking['player2'])
                                @php [$pL2, $pC2] = $payBadge($selectedBooking['payment_p2']); @endphp
                                <div class="flex items-center justify-between p-3 rounded-lg bg-gray-50 dark:bg-white/5">
                                    <span class="text-xs text-gray-500 dark:text-gray-400">Pagamento G2</span>
                                    <span class="text-[11px] font-semibold px-2 py-0.5 rounded {{ $pC2 }}">{{ $pL2 }}</span>
                                </div>
                            @endif
                            <div class="flex items-center justify-between p-3 rounded-lg bg-gray-50 dark:bg-white/5">
                                <span class="text-xs text-gray-500 dark:text-gray-400">Google Calendar</span>
                                @if($selectedBooking['has_gcal'])
                                    <span class="flex items-center gap-1 text-[11px] font-semibold text-emerald-600 dark:text-emerald-400">
                                        <x-heroicon-m-check class="w-3.5 h-3.5"/> Sincronizzato
                                    </span>
                                @else
                                    <span class="text-[11px] text-gray-400 dark:text-gray-500">Non sincronizzato</span>
                                @endif
                            </div>
                        </div>
                    </div>

                    <a href="{{ $selectedBooking['edit_url'] }}"
                       class="flex items-center justify-center gap-2 w-full px-4 py-2.5 rounded-lg text-sm font-semibold shadow-sm transition bg-primary-600 text-white hover:bg-primary-500">
                        <x-heroicon-m-pencil-square class="w-4 h-4"/> Modifica prenotazione
                    </a>
                </div>
            @endif
        </div>
    </div>

    <script>
        function calendarApp() {
            return {
                draggingId: null,
                ghostTop: null,
                ghostHeight: 76,

                scrollToNow() {
                    this.$nextTick(() => {
                        const el = document.getElementById('cal-timeline');
                        if (!el) return;
                        @if($this->currentTimePosition !== null)
                            el.scrollTop = Math.max(0, {{ $this->currentTimePosition }} - 200);
                        @endif
                    });
                },

                snapToGrid(y) {
                    const totalMinutes = Math.floor(y / 80 * 60) + 480;
                    return Math.round(totalMinutes / 30) * 30;
                },

                minutesToTime(minutes) {
                    const h = Math.floor(minutes / 60);
                    const m = minutes % 60;
                    return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
                },

                clickToCreate(event, date) {
                    if (this.draggingId) return;
                    const rect = event.currentTarget.getBoundingClientRect();
                    const y = event.clientY - rect.top + event.currentTarget.scrollTop;
                    const snapped = this.snapToGrid(y);
                    if (snapped < 480 || snapped >= 1320) return;
                    this.$wire.createAtSlot(date, this.minutesToTime(snapped));
                },

                dragStart(event, id, height) {
                    this.draggingId = id;
                    this.ghostHeight = height;
                    event.dataTransfer.effectAllowed = 'move';
                    event.dataTransfer.setData('text/plain', String(id));
                    // Transparent drag image
                    const ghost = document.createElement('div');
                    ghost.style.width = '1px';
                    ghost.style.height = '1px';
                    document.body.appendChild(ghost);
                    event.dataTransfer.setDragImage(ghost, 0, 0);
                    setTimeout(() => ghost.remove(), 0);
                },

                dragOver(event) {
                    if (!this.draggingId) return;
                    event.dataTransfer.dropEffect = 'move';
                    const rect = event.currentTarget.getBoundingClientRect();
                    const y = event.clientY - rect.top;
                    const snapped = this.snapToGrid(y);
                    this.ghostTop = ((snapped - 480) / 60) * 80;
                },

                dragLeave() {
                    this.ghostTop = null;
                },

                dragEnd() {
                    this.draggingId = null;
                    this.ghostTop = null;
                },

                drop(event, date) {
                    if (!this.draggingId) return;
                    const rect = event.currentTarget.getBoundingClientRect();
                    const y = event.clientY - rect.top;
                    const snapped = this.snapToGrid(y);
                    if (snapped < 480 || snapped >= 1320) { this.dragEnd(); return; }
                    const id = this.draggingId;
                    this.$wire.moveBooking(id, date, this.minutesToTime(snapped));
                    this.dragEnd();
                },
            };
        }
    </script>
</x-filament-panels::page>
