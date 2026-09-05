{{--
    The whole `@empty` branch of a filtered table: the message when idle, and a
    placeholder while a filter is in flight.

    Both halves answer one question — "is there anything here?" — and they must
    never both be on screen, so one component owns both rather than each call
    site remembering two elements and repeating the column count for each. The
    students table wrote `$canEdit ? 7 : 6` twice for exactly that reason.

    WHY PLACEHOLDERS AT ALL. The dim treatment (`.is-busy`) is the right answer
    whenever rows are already on screen: it keeps the content the officer is
    reading and moves nothing. It cannot help when the panel is EMPTY — a search
    that has cleared the table, or a filter whose first result set has not
    landed. There the officer is looking at blank space, and blank space is
    indistinguishable from "no results". They read a finished answer to a
    question still in flight, and search again.

    ONE ROW, NOT FOUR. The bars are stacked inside the single `colspan` cell the
    message already occupies, rather than rendered as four more `<tr>`s. Placing
    them in real rows meant fighting the table layout at every turn:

      - It needed `display: table-row` to show them, which loses on specificity
        to `.table-cards tr { display: block }` in the mobile card layout, so on
        a phone the placeholders were permanently visible under the message and
        broke the card shape when a request was actually out.
      - It needed a `display` override at all, which is what led to bare
        `wire:loading` on a `<tr>` — Livewire reveals an element by choosing a
        display value from inline, block, table, flex, grid and inline-flex,
        falling back to `inline-block`, which collapses every cell in the row to
        zero width. There is no `table-row` in that list.
      - And it put 4 rows, 28 cells and 28 spans into the snapshot that morphs
        on every keystroke, to decorate something marked `aria-hidden`.

    A cell that is already there, holding bars that are already blocks, needs no
    display value of its own at any breakpoint and works unchanged inside the
    card layout.

    VISIBILITY IS `wire:loading.class`. It is the one loading branch that never
    assigns a display value — it adds and removes a class and leaves the
    stylesheet to say what that means. That matters on the failure path:
    `handleFailure()` runs the loading teardown but never morphs, so a directive
    that wrote an inline `display` would leave it behind after a 500 or a
    dropped request, with nothing coming along to clean it up.

    `.delay.shorter` (100ms) rather than the plain 200ms `<x-ui.busy>` uses. It
    has to clear local latency, where the box answers in 5-14ms and an
    undelayed swap paints bars for a single frame on every debounced keystroke,
    while still firing on the ~150ms round trip to Frankfurt that this exists
    for. 200ms would have been silent in production.

    @props:
      cols   — cells the message spans. Stated once by the caller.
      target — a wire:target expression naming the filters this answers for.
               Without it the placeholder reacts to anything the component does,
               which on a screen that also saves or paginates means it flashes
               over a table nobody is filtering. Name EVERY filter on the page:
               a chip whose property is missing from the list gets no feedback
               at all.
      rows   — how many bars. Four reads as a table without pretending to know
               how many results are coming.

    `aria-hidden` on the bars because there is nothing there to read. A screen
    reader should hear `<x-ui.busy>`'s "Searching…", not four empty shapes.
--}}
@props(['cols', 'target' => null, 'rows' => 4])

<tr class="empty-row" wire:loading.delay.shorter.class="is-waiting"
    @if ($target) wire:target="{{ $target }}" @endif>
    <td colspan="{{ $cols }}" class="empty-state">
        <span class="empty-msg">{{ $slot }}</span>
        <span class="skeleton-stack" aria-hidden="true">
            @for ($r = 0; $r < $rows; $r++)<span class="skeleton-bar"></span>@endfor
        </span>
    </td>
</tr>
