<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header
        name="Harken Replicas"
        x-bind:title="`Time: {{ number_format($time) }}ms; Run at: ${formatDate('{{ $runAt }}')};`"
        details="harken:start processes"
    >
        <x-slot:icon>
            <x-pulse::icons.server />
        </x-slot:icon>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.5s="">
        @if ($replicas->isEmpty())
            <x-pulse::no-results />
        @else
            <x-pulse::table>
                <colgroup>
                    <col width="100%" />
                    <col width="0%" />
                    <col width="0%" />
                    <col width="0%" />
                    <col width="0%" />
                    <col width="0%" />
                </colgroup>
                <x-pulse::thead>
                    <tr>
                        <x-pulse::th>Replica</x-pulse::th>
                        <x-pulse::th>Role</x-pulse::th>
                        <x-pulse::th>MQTT</x-pulse::th>
                        <x-pulse::th class="text-right">Received</x-pulse::th>
                        <x-pulse::th class="text-right">Memory</x-pulse::th>
                        <x-pulse::th class="text-right">Outbox</x-pulse::th>
                    </tr>
                </x-pulse::thead>
                <tbody>
                    @foreach ($replicas as $replica)
                        <tr wire:key="{{ $replica->client_id }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ $replica->client_id }}-row" class="{{ $replica->running ? '' : 'opacity-25' }}" title="Reported {{ $replica->updated_at->fromNow() }}">
                            <x-pulse::td class="max-w-[1px]">
                                <div class="flex items-center gap-2">
                                    @if ($replica->running)
                                        <div class="w-5 flex justify-center flex-shrink-0">
                                            <div class="h-1 w-1 bg-green-500 rounded-full animate-pulse"></div>
                                        </div>
                                    @else
                                        <x-pulse::icons.signal-slash class="w-5 h-5 stroke-red-500 flex-shrink-0" />
                                    @endif
                                    <div class="overflow-hidden">
                                        <p class="text-sm font-bold text-gray-600 dark:text-gray-300 truncate" title="{{ $replica->name }}">{{ $replica->name }}</p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 truncate" title="{{ $replica->client_id }}">
                                            {{ $replica->running ? $replica->connection : $replica->state.' · '.$replica->updated_at->fromNow() }}
                                        </p>
                                    </div>
                                </div>
                            </x-pulse::td>
                            <x-pulse::td class="whitespace-nowrap">
                                @if ($replica->role === 'leader')
                                    <span class="text-xs font-bold text-purple-400" title="{{ $replica->last_gap_seconds === null ? '' : 'Last takeover: '.number_format($replica->last_gap_seconds, 2).' s without a leader' }}">LEADER</span>
                                @elseif ($replica->role === 'standby')
                                    <span class="text-xs text-gray-500 dark:text-gray-400">standby</span>
                                @else
                                    <span class="text-xs text-gray-400 dark:text-gray-600">&ndash;</span>
                                @endif
                            </x-pulse::td>
                            <x-pulse::td class="whitespace-nowrap">
                                <span class="text-xs {{ $replica->mqtt === 'connected' ? 'text-gray-700 dark:text-gray-300' : 'font-bold text-red-400' }}">{{ $replica->mqtt }}</span>
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">
                                @if ($replica->received_per_second === null)
                                    &ndash;
                                @else
                                    <strong>{{ number_format($replica->received_per_second, $replica->received_per_second >= 100 ? 0 : 1) }}</strong>/s
                                @endif
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">
                                @if ($replica->memory_mb === null)
                                    &ndash;
                                @else
                                    <strong>{{ number_format($replica->memory_mb) }}</strong> MB
                                @endif
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 font-bold">
                                {{ $replica->outbox_waiting === null ? '–' : number_format($replica->outbox_waiting) }}
                            </x-pulse::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse::table>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
