@php
    use Illuminate\Support\Str;

    $actor = auth()->user();
@endphp

<div class="space-y-4">
    @forelse ($verifications as $verification)
        @php
            $canSeeEvidence = $actor && $actor->can('viewLocationEvidence', $verification);
        @endphp

        <x-filament::section compact>
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="font-medium">
                    {{ Str::headline($verification->action->value) }}
                    · {{ $verification->server_recorded_at->copy()->setTimezone($timezone)->format('d M Y, h:i A') }}
                </div>
                <x-filament::badge :color="$verification->result->getColor()">
                    {{ Str::headline($verification->result->value) }}
                </x-filament::badge>
            </div>

            <dl class="mt-3 grid grid-cols-1 gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                <div><dt class="inline font-medium">Method:</dt> <dd class="inline">{{ Str::headline($verification->method->value) }}</dd></div>
                <div><dt class="inline font-medium">Recorded:</dt> <dd class="inline">{{ $verification->accepted ? 'Yes' : 'No' }}</dd></div>

                @if ($verification->workLocation)
                    <div><dt class="inline font-medium">Workplace:</dt> <dd class="inline">{{ $verification->workLocation->name }}</dd></div>
                @endif
                @if ($verification->distance_meters !== null)
                    <div><dt class="inline font-medium">Distance from workplace:</dt> <dd class="inline">{{ number_format((float) $verification->distance_meters, 0) }} m</dd></div>
                @endif
                @if ($verification->accuracy_meters !== null)
                    <div><dt class="inline font-medium">GPS accuracy:</dt> <dd class="inline">±{{ number_format((float) $verification->accuracy_meters, 0) }} m</dd></div>
                @endif

                @if (! empty($verification->flags))
                    <div class="sm:col-span-2"><dt class="inline font-medium">Flags:</dt> <dd class="inline">{{ collect($verification->flags)->map(fn ($flag) => Str::headline($flag))->implode(', ') }}</dd></div>
                @endif

                @if ($verification->review_status)
                    <div class="sm:col-span-2">
                        <dt class="inline font-medium">Review:</dt>
                        <dd class="inline">
                            {{ Str::headline($verification->review_status) }}
                            @if ($verification->reviewer) by {{ $verification->reviewer->name }} @endif
                            @if ($verification->review_note) — {{ $verification->review_note }} @endif
                        </dd>
                    </div>
                @endif

                @if ($verification->action->value === 'hr_correction' && ! empty($verification->metadata))
                    <div class="sm:col-span-2">
                        <dt class="inline font-medium">Correction by {{ $verification->user?->name ?? 'HR' }}:</dt>
                        <dd class="inline">
                            {{ $verification->metadata['reason'] ?? 'no reason recorded (direct edit)' }}
                            (in {{ $verification->metadata['before']['check_in'] ?? '—' }} → {{ $verification->metadata['after']['check_in'] ?? '—' }},
                            out {{ $verification->metadata['before']['check_out'] ?? '—' }} → {{ $verification->metadata['after']['check_out'] ?? '—' }})
                        </dd>
                    </div>
                @endif

                @if ($canSeeEvidence)
                    @if ($verification->latitude !== null)
                        <div class="sm:col-span-2">
                            <dt class="inline font-medium">Coordinates:</dt>
                            <dd class="inline">{{ $verification->latitude }}, {{ $verification->longitude }}</dd>
                        </div>
                    @endif
                    @if ($verification->ip_address)
                        <div><dt class="inline font-medium">IP address:</dt> <dd class="inline">{{ $verification->ip_address }}</dd></div>
                    @endif
                    @if ($verification->user_agent)
                        <div class="sm:col-span-2"><dt class="inline font-medium">Device:</dt> <dd class="inline break-all">{{ $verification->user_agent }}</dd></div>
                    @endif
                @endif
            </dl>
        </x-filament::section>
    @empty
        <p class="text-sm">No verification evidence is available for this record.</p>
    @endforelse
</div>
