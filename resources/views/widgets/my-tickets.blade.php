@use('Illuminate\Support\Collection')
@use('KraftInk\Plugins\Support\Models\Ticket')
@php
    /** @var Collection<int, Ticket> $tickets */
@endphp
<section class="card h-100">
    <div class="card-body">
        <h2 class="h5 card-title mb-3">{{ __('support::widgets.my_tickets') }}</h2>
        <ul class="list-unstyled mb-0">
            @forelse ($tickets as $ticket)
                <li class="mb-2">
                    <a href="{{ route('plugins.support.public.tickets.show', ['id' => $ticket->id]) }}">{{ $ticket->subject }}</a>
                </li>
            @empty
                <li class="text-body-secondary">{{ __('support::widgets.empty') }}</li>
            @endforelse
        </ul>
    </div>
</section>
