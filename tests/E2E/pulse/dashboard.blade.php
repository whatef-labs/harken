{{-- The Pulse dashboard for end-to-end runs: the Harken cards, plus Pulse's own servers card. --}}
<x-pulse>
    <livewire:harken.replicas cols="full" />

    <livewire:harken.messages cols="6" />

    <livewire:harken.handlers cols="6" />

    <livewire:pulse.servers cols="full" />
</x-pulse>
