@php
$rate = fn (float $value) => $value >= 100 ? number_format($value) : number_format($value, 1);
@endphp
<x-pulse::card :cols="$cols" :rows="$rows" :class="$class">
    <x-pulse::card-header
        name="Harken"
        x-bind:title="`Time: {{ number_format($time) }}ms; Run at: ${formatDate('{{ $runAt }}')};`"
        details="past {{ $this->periodForHumans() }}"
    >
        <x-slot:icon>
            <x-pulse::icons.arrows-left-right />
        </x-slot:icon>
        <x-slot:actions>
            <div class="flex flex-wrap gap-4">
                <div class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400 font-medium">
                    <div class="h-0.5 w-3 rounded-full bg-[#9333ea]"></div>
                    Received
                </div>
                <div class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400 font-medium">
                    <div class="h-0.5 w-3 rounded-full bg-[rgba(107,114,128,0.5)]"></div>
                    Published
                </div>
                <div class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400 font-medium">
                    <div class="h-0.5 w-3 rounded-full bg-[#e11d48]"></div>
                    Failed
                </div>
                <div class="flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400 font-medium">
                    <div class="h-0.5 w-3 rounded-full bg-[#eab308]"></div>
                    Dropped
                </div>
            </div>
        </x-slot:actions>
    </x-pulse::card-header>

    <x-pulse::scroll :expand="$expand" wire:poll.5s="">
        @if ($connections->isEmpty())
            <x-pulse::no-results />
        @else
            <div class="grid gap-3 mx-px mb-px">
                @foreach ($connections as $connection => $stats)
                    <div wire:key="harken-messages-{{ $connection }}">
                        @if ($connections->count() > 1)
                            <h3 class="font-bold text-gray-700 dark:text-gray-300">{{ $connection }}</h3>
                        @endif

                        <div class="grid grid-cols-3 gap-3 text-center">
                            <div class="flex flex-col justify-center @sm:block">
                                <span class="text-xl uppercase font-bold text-gray-700 dark:text-gray-300 tabular-nums">
                                    {{ number_format($stats->totals['harken_received']) }}
                                </span>
                                <span class="text-xs uppercase font-bold text-gray-500 dark:text-gray-400">
                                    Received
                                </span>
                                <p class="text-xs text-gray-500 dark:text-gray-400 tabular-nums" title="Average over the time Harken reported in this period, and the busiest point of the graph">
                                    {{ $rate($stats->totals['harken_received'] / $stats->seconds) }}/s, peak {{ $rate($stats->peak) }}/s
                                </p>
                            </div>
                            <div class="flex flex-col justify-center @sm:block">
                                <span class="text-xl uppercase font-bold text-gray-700 dark:text-gray-300 tabular-nums">
                                    {{ number_format($stats->totals['harken_published']) }}
                                </span>
                                <span class="text-xs uppercase font-bold text-gray-500 dark:text-gray-400">
                                    Published
                                </span>
                                <p class="text-xs text-gray-500 dark:text-gray-400 tabular-nums">
                                    {{ $rate($stats->totals['harken_published'] / $stats->seconds) }}/s
                                </p>
                            </div>
                            <div class="flex flex-col justify-center @sm:block">
                                <span class="text-xl uppercase font-bold tabular-nums {{ $stats->totals['harken_failed'] + $stats->totals['harken_dropped'] > 0 ? 'text-red-400' : 'text-gray-700 dark:text-gray-300' }}">
                                    {{ number_format($stats->totals['harken_failed']) }} / {{ number_format($stats->totals['harken_dropped']) }}
                                </span>
                                <span class="text-xs uppercase font-bold text-gray-500 dark:text-gray-400">
                                    Failed / Dropped
                                </span>
                                <p class="text-xs text-gray-500 dark:text-gray-400 tabular-nums">
                                    {{ number_format($stats->totals['harken_reconnects']) }} reconnects, {{ number_format($stats->totals['harken_takeovers']) }} takeovers
                                </p>
                            </div>
                        </div>

                        <div class="mt-3 relative">
                            <div
                                wire:ignore
                                class="h-14"
                                x-data="harkenMessagesChart({
                                    connection: @js($connection),
                                    readings: @js($stats->readings),
                                    bucketSeconds: {{ $bucketSeconds }},
                                })"
                            >
                                <canvas x-ref="canvas" class="ring-1 ring-gray-900/5 dark:ring-gray-100/10 bg-gray-50 dark:bg-gray-800 rounded-md shadow-sm"></canvas>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-pulse::scroll>
</x-pulse::card>

@script
<script>
Alpine.data('harkenMessagesChart', (config) => ({
    init() {
        const series = [
            ['harken_received', 'Received', '#9333ea'],
            ['harken_published', 'Published', 'rgba(107,114,128,0.5)'],
            ['harken_failed', 'Failed', '#e11d48'],
            ['harken_dropped', 'Dropped', '#eab308'],
        ]

        let chart = new Chart(
            this.$refs.canvas,
            {
                type: 'line',
                data: {
                    labels: this.labels(config.readings),
                    datasets: series.map(([type, label, color], order) => ({
                        label,
                        borderColor: color,
                        data: this.scale(config.readings[type]),
                        order,
                    })),
                },
                options: {
                    maintainAspectRatio: false,
                    layout: {
                        autoPadding: false,
                        padding: {
                            top: 1,
                        },
                    },
                    datasets: {
                        line: {
                            borderWidth: 2,
                            borderCapStyle: 'round',
                            pointHitRadius: 10,
                            pointStyle: false,
                            tension: 0.2,
                            spanGaps: false,
                            segment: {
                                borderColor: (ctx) => ctx.p0.raw === 0 && ctx.p1.raw === 0 ? 'transparent' : undefined,
                            }
                        }
                    },
                    scales: {
                        x: {
                            display: false,
                        },
                        y: {
                            display: false,
                            min: 0,
                            max: this.highest(config.readings),
                        },
                    },
                    plugins: {
                        legend: {
                            display: false,
                        },
                        tooltip: {
                            mode: 'index',
                            position: 'nearest',
                            intersect: false,
                            callbacks: {
                                beforeBody: (context) => context
                                    .map(item => `${item.dataset.label}: ${item.formattedValue}/s`)
                                    .join(', '),
                                label: () => null,
                            },
                        },
                    },
                },
            }
        )

        Livewire.on('harken-messages-chart-update', ({ connections }) => {
            if (chart === undefined) {
                return
            }

            if (connections[config.connection] === undefined && chart) {
                chart.destroy()
                chart = undefined
                return
            }

            const readings = connections[config.connection]

            chart.data.labels = this.labels(readings)
            chart.options.scales.y.max = this.highest(readings)
            series.forEach(([type], index) => chart.data.datasets[index].data = this.scale(readings[type]))
            chart.update()
        })
    },
    labels(readings) {
        return Object.keys(readings.harken_received).map(formatDate)
    },
    scale(data) {
        return Object.values(data).map(value => value === null ? null : Math.round(value / config.bucketSeconds * 10) / 10)
    },
    highest(readings) {
        return Math.max(1, ...Object.values(readings).map(dataset => Math.max(...Object.values(dataset)))) / config.bucketSeconds
    }
}))
</script>
@endscript
