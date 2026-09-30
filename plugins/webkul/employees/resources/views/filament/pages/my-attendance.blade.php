<x-filament-panels::page>
    @php
        $state = $this->getTodayState();
        $recent = $this->getRecentRecords();
        $remote = ($state['mode'] ?? null) === 'remote';
        $checkedIn = ($state['state'] ?? null) === 'checked_in';
        $checkedOut = ($state['state'] ?? null) === 'checked_out';
    @endphp

    <div
        class="mx-auto w-full max-w-xl space-y-4"
        x-data="{
            phase: 'idle',
            busy: false,
            remote: @js($remote),
            newId() {
                return (window.crypto && typeof crypto.randomUUID === 'function') ? crypto.randomUUID() : null
            },
            async report(action, code, requestId) {
                this.phase = 'verifying'
                await $wire.reportLocationFailure(action, code, requestId)
            },
            async attempt(action) {
                if (this.busy) { return }
                this.busy = true
                const method = action === 'check_in' ? 'checkIn' : 'checkOut'
                const requestId = this.newId()

                try {
                    if (this.remote) {
                        this.phase = 'verifying'
                        await $wire[method]({ client_request_id: requestId })
                        return
                    }

                    if (! window.isSecureContext) { await this.report(action, 'insecure_context', requestId); return }
                    if (! ('geolocation' in navigator)) { await this.report(action, 'unsupported', requestId); return }

                    this.phase = 'locating'
                    const queryPosition = (opts) => new Promise((resolve, reject) => {
                        navigator.geolocation.getCurrentPosition(resolve, reject, opts)
                    })

                    let position
                    try {
                        position = await queryPosition({ enableHighAccuracy: true, timeout: 8000, maximumAge: 0 })
                    } catch (geoErr) {
                        if (geoErr && geoErr.code !== 1) {
                            position = await queryPosition({ enableHighAccuracy: false, timeout: 10000, maximumAge: 0 })
                        } else {
                            throw geoErr
                        }
                    }

                    // Some browsers (desktop Edge/Chrome) hand back a cached fix even
                    // with maximumAge: 0. Ask once more for a fresh one; if it is still
                    // old, send it as-is -- the server judges freshness, the browser
                    // never rewrites the reading's timestamp.
                    if (position.timestamp && (Date.now() - position.timestamp) > 60000) {
                        try {
                            position = await queryPosition({ enableHighAccuracy: true, timeout: 8000, maximumAge: 0 })
                        } catch (retryErr) {
                            // keep the first reading
                        }
                    }

                    this.phase = 'verifying'
                    await $wire[method]({
                        latitude: position.coords.latitude,
                        longitude: position.coords.longitude,
                        accuracy: position.coords.accuracy,
                        captured_at: position.timestamp ? Math.round(position.timestamp) : null,
                        client_now: Date.now(),
                        client_request_id: requestId,
                    })
                } catch (error) {
                    if (error && typeof error.code === 'number') {
                        const code = { 1: 'permission_denied', 2: 'position_unavailable', 3: 'timeout' }[error.code] || 'position_unavailable'
                        await this.report(action, code, requestId)
                    } else {
                        throw error
                    }
                } finally {
                    this.phase = 'idle'
                    this.busy = false
                }
            },
        }"
    >
        @if ($state === null)
            <x-filament::section>
                <p>{{ __('employees::attendance.results.employee_not_eligible') }}</p>
            </x-filament::section>
        @else
            <x-filament::section>
                <div class="flex items-center justify-between gap-3">
                    <div>
                        @if ($checkedIn)
                            <div class="text-lg font-semibold">Checked in at {{ $state['check_in'] }}</div>
                        @elseif ($checkedOut)
                            <div class="text-lg font-semibold">Checked out at {{ $state['check_out'] }}</div>
                            <div class="text-sm">Worked {{ $state['worked'] }} today.</div>
                        @else
                            <div class="text-lg font-semibold">Not checked in</div>
                        @endif

                        @if ($remote)
                            <div class="text-sm">Remote work: no location is needed.</div>
                        @elseif ($state['location'])
                            <div class="text-sm">{{ $state['location'] }}</div>
                        @endif
                    </div>

                    <x-filament::badge :color="$checkedIn ? 'success' : ($checkedOut ? 'gray' : 'warning')">
                        {{ $checkedIn ? 'In' : ($checkedOut ? 'Done' : 'Out') }}
                    </x-filament::badge>
                </div>
            </x-filament::section>

            @if ($outcome)
                <x-filament::section>
                    <div class="flex items-start gap-3" role="status" aria-live="polite">
                        <x-filament::badge :color="$outcome['ok'] ? 'success' : 'danger'">
                            {{ $outcome['ok'] ? 'Done' : 'Not recorded' }}
                        </x-filament::badge>
                        <p class="text-sm">{{ $outcome['message'] }}</p>
                    </div>
                </x-filament::section>
            @endif

            @if (($state['mode'] ?? null) === 'none' && ! $checkedIn)
                <x-filament::section>
                    <p class="text-sm">{{ __('employees::attendance.results.no_location_configured') }}</p>
                </x-filament::section>
            @elseif (! $checkedOut)
                <x-filament::button
                    size="xl"
                    class="w-full"
                    :color="$checkedIn ? 'warning' : 'success'"
                    x-on:click="attempt('{{ $checkedIn ? 'check_out' : 'check_in' }}')"
                    x-bind:disabled="busy"
                >
                    <span x-show="phase === 'idle'">
                        {{ $outcome && ! $outcome['ok'] ? 'Try again — ' : '' }}{{ $checkedIn ? 'Check Out' : 'Check In' }}
                    </span>
                    <span x-show="phase === 'locating'" x-cloak>Requesting location…</span>
                    <span x-show="phase === 'verifying'" x-cloak>Verifying location…</span>
                </x-filament::button>
            @endif

            <p class="text-xs">{{ __('employees::attendance.privacy_note') }}</p>

            <x-filament::section heading="My recent attendance" :collapsible="true">
                @forelse ($recent as $row)
                    <div class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <div>
                            <div class="font-medium">{{ $row['date'] }}</div>
                            <div class="text-sm">{{ $row['check_in'] }} – {{ $row['check_out'] }} · {{ $row['worked'] }}</div>
                        </div>
                        <div class="flex items-center gap-3">
                            <x-filament::badge :color="$row['review'] ? 'warning' : 'gray'">
                                {{ $row['review'] ? 'Under review' : $row['status'] }}
                            </x-filament::badge>
                            {{ ($this->requestCorrectionAction)(['record' => $row['id']]) }}
                        </div>
                    </div>
                @empty
                    <p class="text-sm">No attendance yet.</p>
                @endforelse

                <div class="pt-3">
                    {{ $this->requestMissingDayAction }}
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
