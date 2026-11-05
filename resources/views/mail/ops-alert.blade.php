<x-mail::message>
# {{ $incident->title }}

@if ($state === 'resolved')
Resolved at {{ $incident->resolved_at?->toDateTimeString() }} UTC (opened {{ $incident->opened_at->toDateTimeString() }} UTC).
@else
Since {{ $incident->opened_at->toDateTimeString() }} UTC.

{{ $incident->detail }}

What to do: see docs/operations.md, section "{{ $incident->key }}".
@endif
</x-mail::message>
