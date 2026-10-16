<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header
        name="Harken Handlers"
        x-bind:title="`Time: {{ number_format($time) }}ms; Run at: ${formatDate('{{ $runAt }}')};`"
        details="{{ $threshold }}ms threshold, past {{ $this->periodForHumans() }}"
    >
        <x-slot:icon>
            <x-pulse::icons.command-line />
        </x-slot:icon>
        <x-slot:actions>
            <x-pulse::select
                wire:model.live="orderBy"
                id="select-harken-handlers-order-by"
                label="Sort by"
                :options="[
                    'handled' => 'handled',
                    'failed' => 'failed',
                    'slow' => 'slow',
                    'time' => 'total time',
                ]"
                @change="loading = true"
            />
        </x-slot:actions>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.5s="">
        @if ($handlers->isEmpty())
            <x-pulse::no-results />
        @else
            <x-pulse::table>
                <colgroup>
                    <col width="100%" />
                    <col width="0%" />
                    <col width="0%" />
                    <col width="0%" />
                    <col width="0%" />
                </colgroup>
                <x-pulse::thead>
                    <tr>
                        <x-pulse::th>Route</x-pulse::th>
                        <x-pulse::th class="text-right">Handled</x-pulse::th>
                        <x-pulse::th class="text-right">Failed</x-pulse::th>
                        <x-pulse::th class="text-right">Slow</x-pulse::th>
                        <x-pulse::th class="text-right">Average</x-pulse::th>
                    </tr>
                </x-pulse::thead>
                <tbody>
                    @foreach ($handlers->take(100) as $handler)
                        <tr wire:key="{{ $handler->route }}-spacer" class="h-2 first:h-0"></tr>
                        <tr wire:key="{{ $handler->route }}-row">
                            <x-pulse::td class="max-w-[1px]">
                                <code class="block text-xs text-gray-900 dark:text-gray-100 truncate" title="{{ $handler->route }}">
                                    {{ $handler->route }}
                                </code>
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 font-bold">
                                {{ number_format($handler->handled) }}
                            </x-pulse::td>
                            <x-pulse::td numeric class="font-bold {{ $handler->failed > 0 ? 'text-red-400' : 'text-gray-700 dark:text-gray-300' }}">
                                {{ number_format($handler->failed) }}
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300 font-bold">
                                {{ number_format($handler->slow) }}
                            </x-pulse::td>
                            <x-pulse::td numeric class="text-gray-700 dark:text-gray-300">
                                @if ($handler->average_ms === null)
                                    &ndash;
                                @else
                                    <strong>{{ $handler->average_ms < 0.01 ? '<0.01' : number_format($handler->average_ms, $handler->average_ms >= 10 ? 0 : 2) }}</strong> ms
                                @endif
                            </x-pulse::td>
                        </tr>
                    @endforeach
                </tbody>
            </x-pulse::table>
        @endif

        @if ($handlers->count() > 100)
            <div class="mt-2 text-xs text-gray-400 text-center">Limited to 100 entries</div>
        @endif
    </x-pulse::scroll>
</x-pulse::card>
