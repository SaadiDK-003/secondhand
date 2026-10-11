<x-filament-widgets::widget>
    <section class="sh-welcome">
        <div>
            <p class="sh-eyebrow">MARKETPLACE OPERATIONS</p>
            <h2>Welcome back, {{ $name }}.</h2>
            <p>Keep good listings moving and your community safe. Everything you need is right here.</p>
        </div>
        <div class="sh-welcome-actions">
            <x-filament::button tag="a" :href="url('/admin/listings?filters[status][value]=pending')" icon="heroicon-o-clipboard-document-check">Review ads</x-filament::button>
            <x-filament::button tag="a" :href="url('/')" color="gray" icon="heroicon-o-arrow-top-right-on-square">View marketplace</x-filament::button>
        </div>
    </section>
</x-filament-widgets::widget>
