{{--
    "Working on it" for a Livewire round trip.

    @props:
      target — a wire:target expression, so the indicator answers for ONE action
               rather than for anything the component happens to be doing. On a
               page where saving the register and changing the date both take a
               moment, an indicator that cannot tell them apart is worse than
               none: it appears next to the filter while you are saving.
      label  — what is being waited for, in the user's words.

    `wire:loading.delay` is deliberate. Most of these round trips finish inside
    200ms and a spinner that appears and vanishes in that window reads as a
    glitch, not as feedback. Livewire holds it back for ~200ms, so it shows up
    only when there is genuinely something to wait for.
--}}
@props(['target' => null, 'label' => 'Updating…'])

<span class="busy" wire:loading.delay @if ($target) wire:target="{{ $target }}" @endif>{{ $label }}</span>
