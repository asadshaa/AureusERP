<x-filament-panels::page>
    @php
        $hasMultiFactorAuth = \Filament\Facades\Filament::hasMultiFactorAuthentication();
        $hasEmployeeLeaves = !empty($leaveSummary['cards'] ?? []);
        $hasSideColumn = $hasMultiFactorAuth || $hasEmployeeLeaves;
    @endphp

    <div
        @class([
            'grid grid-cols-1 gap-6',
            'lg:grid-cols-3!' => $hasSideColumn,
        ])
    >
        <div
            @class([
                'lg:col-span-2' => $hasSideColumn,
            ])
        >
            <form
                wire:submit="updateProfile"
                wire:key="profile-form"
                x-data="{ isProcessing: false }"
                x-on:submit="if (isProcessing) $event.preventDefault()"
                x-on:form-processing-started="isProcessing = true"
                x-on:form-processing-finished="isProcessing = false"
                class="fi-form grid gap-y-6"
            >
                <div class="flex flex-col gap-6">
                    {{ $this->editProfileForm }}

                    <x-filament::actions
                        :actions="$this->getUpdateProfileFormActions()"
                        :full-width="false"
                    />
                </div>
            </form>
        </div>

        @if ($hasSideColumn)
            <div class="lg:col-span-1 flex flex-col gap-6">
                @if ($hasEmployeeLeaves)
                    <div class="rounded-xl border border-gray-200/80 bg-white p-5 shadow-xs dark:border-white/10 dark:bg-gray-900">
                        <div class="flex items-center justify-between pb-3 border-b border-gray-100 dark:border-gray-800">
                            <div class="flex items-center gap-2">
                                <x-heroicon-o-chart-pie class="h-5 w-5 text-primary-500" />
                                <h3 class="text-sm font-bold text-gray-950 dark:text-white">
                                    My Leave Entitlements
                                </h3>
                            </div>
                            <span class="inline-flex items-center rounded-md bg-primary-50 px-2 py-0.5 text-xs font-bold text-primary-700 dark:bg-primary-950/40 dark:text-primary-300">
                                {{ number_format($leaveSummary['total_left'] ?? 0, 1) }} / {{ number_format($leaveSummary['total_allocated'] ?? 0, 1) }} Days
                            </span>
                        </div>

                        <div class="mt-4 space-y-3">
                            @foreach ($leaveSummary['cards'] as $card)
                                @php
                                    $pct = $card['allocated'] > 0 ? min(100, max(0, (int) round(($card['left'] / $card['allocated']) * 100))) : 0;
                                    $lower = strtolower($card['name']);
                                    $badgeColor = str_contains($lower, 'sick') ? 'text-rose-600 dark:text-rose-400' : (str_contains($lower, 'casual') ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400');
                                    $barColor = str_contains($lower, 'sick') ? 'bg-rose-500' : (str_contains($lower, 'casual') ? 'bg-amber-500' : 'bg-emerald-500');
                                @endphp
                                <div class="rounded-lg border border-gray-100 bg-gray-50/70 p-3 dark:border-gray-800 dark:bg-gray-800/40">
                                    <div class="flex items-center justify-between text-xs font-semibold">
                                        <span class="text-gray-900 dark:text-white">{{ $card['name'] }}</span>
                                        <span class="{{ $badgeColor }} font-bold">{{ number_format($card['left'], 1) }} Left</span>
                                    </div>
                                    <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                        <div class="h-full rounded-full {{ $barColor }}" style="width: {{ $pct }}%"></div>
                                    </div>
                                    <div class="mt-2 flex justify-between text-3xs text-gray-500 dark:text-gray-400">
                                        <span>Allocated: {{ number_format($card['allocated'], 1) }}</span>
                                        <span>Used: {{ number_format($card['taken'], 1) }}</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-4 pt-3 border-t border-gray-100 dark:border-gray-800 text-center">
                            <a
                                href="{{ url('/admin/time-off/dashboard/my-time-offs/create') }}"
                                class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-primary-50 px-3 py-2 text-xs font-semibold text-primary-700 hover:bg-primary-100 transition-colors dark:bg-primary-950/40 dark:text-primary-300 dark:hover:bg-primary-900/60"
                            >
                                <x-heroicon-m-plus class="h-3.5 w-3.5" />
                                Request Time Off
                            </a>
                        </div>
                    </div>
                @endif

                @if ($hasMultiFactorAuth)
                    <div>
                        {{ $this->multiFactorAuthenticationSchema }}
                    </div>
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
