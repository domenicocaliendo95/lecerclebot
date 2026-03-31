@php
    $history = $getState() ?? [];
@endphp

@if(empty($history))
    <p class="text-sm text-gray-400 italic">Nessun messaggio in questa sessione.</p>
@else
<div class="space-y-3 max-h-[600px] overflow-y-auto pr-1">
    @foreach($history as $message)
    @php
        $isUser  = ($message['role'] ?? '') === 'user';
        $content = $message['content'] ?? '';
    @endphp
    <div class="flex {{ $isUser ? 'justify-end' : 'justify-start' }}">
        <div class="flex items-end gap-2 max-w-[80%] {{ $isUser ? 'flex-row-reverse' : '' }}">

            {{-- Avatar --}}
            <div class="shrink-0 w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold
                        {{ $isUser ? 'bg-green-600 text-white' : 'bg-gray-200 text-gray-600' }}">
                {{ $isUser ? 'U' : 'B' }}
            </div>

            {{-- Bubble --}}
            <div class="rounded-2xl px-4 py-2 text-sm leading-relaxed whitespace-pre-wrap
                        {{ $isUser
                            ? 'bg-green-600 text-white rounded-br-sm'
                            : 'bg-gray-100 text-gray-800 rounded-bl-sm' }}">
                {{ $content }}
            </div>

        </div>
    </div>
    @endforeach
</div>
@endif
