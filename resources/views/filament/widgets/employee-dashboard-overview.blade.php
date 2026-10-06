<x-filament-widgets::widget>
    @php
        $emp = $employee ?? [];
        $leaves = $leaveSummary ?? ['cards' => []];
        $recent = $recentLeaves ?? [];
        $urls = $urls ?? [];
        $cards = $leaves['cards'] ?? [];
    @endphp

    <x-filament::section
        icon="heroicon-o-calendar-days"
        icon-color="primary"
    >
        <x-slot name="heading">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-base font-semibold text-gray-950 dark:text-white">
                    {{ $emp['name'] ?? 'My Leave Overview' }}
                </span>
                @if (!empty($emp['number']))
                    <x-filament::badge color="gray" size="sm">
                        {{ $emp['number'] }}
                    </x-filament::badge>
                @endif
                @if (!empty($emp['job_title']) && $emp['job_title'] !== '—')
                    <x-filament::badge color="primary" size="sm">
                        {{ $emp['job_title'] }}
                    </x-filament::badge>
                @endif
                @if (!empty($emp['department']) && $emp['department'] !== '—')
                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        • {{ $emp['department'] }}
                    </span>
                @endif
            </div>
        </x-slot>

        <x-slot name="description">
            <div class="flex flex-wrap items-center gap-x-2 text-xs text-gray-500 dark:text-gray-400">
                <span>Annual leave entitlement & balance summary ({{ number_format($leaves['total_allocated'] ?? 25, 0) }} Days Total Allocation).</span>
                @if (!empty($emp['manager_name']) && $emp['manager_name'] !== 'Not Assigned')
                    <span>• Manager: <strong class="text-gray-700 dark:text-gray-300">{{ $emp['manager_name'] }}</strong></span>
                @endif
            </div>
        </x-slot>

        <x-slot name="afterHeader">
            <div class="flex items-center gap-2">
                @if (!empty($urls['request_leave']))
                    <x-filament::button
                        tag="a"
                        :href="$urls['request_leave']"
                        size="xs"
                        color="primary"
                        icon="heroicon-m-plus"
                    >
                        Request Time Off
                    </x-filament::button>
                @endif

                @if (!empty($urls['profile']))
                    <x-filament::button
                        tag="a"
                        :href="$urls['profile']"
                        size="xs"
                        color="gray"
                        outlined
                        icon="heroicon-m-user"
                    >
                        My Profile
                    </x-filament::button>
                @endif
            </div>
        </x-slot>

        {{-- 4 Clean, Aligned Summary Cards --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {{-- Card 1: Total Leave Balance --}}
            <div class="flex flex-col justify-between rounded-xl border border-gray-200/80 bg-gray-50/50 p-4 transition-all dark:border-white/10 dark:bg-white/5">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        Total Balance
                    </span>
                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-950/60 dark:text-primary-400">
                        <x-filament::icon icon="heroicon-o-check-badge" class="h-4 w-4" />
                    </span>
                </div>

                <div class="mt-3">
                    <div class="flex items-baseline gap-1.5">
                        <span class="text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
                            {{ number_format($leaves['total_left'] ?? 0, 1) }}
                        </span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            / {{ number_format($leaves['total_allocated'] ?? 25, 1) }} days left
                        </span>
                    </div>
                </div>

                <div class="mt-3 flex items-center justify-between border-t border-gray-200/60 pt-2.5 text-2xs text-gray-500 dark:border-white/5 dark:text-gray-400">
                    <span>Used: <strong class="text-gray-700 dark:text-gray-300">{{ number_format($leaves['total_taken'] ?? 0, 1) }}</strong></span>
                    @if (($leaves['total_pending'] ?? 0) > 0)
                        <span class="text-amber-600 dark:text-amber-400 font-medium">Pending: {{ number_format($leaves['total_pending'], 1) }}</span>
                    @else
                        <span class="text-emerald-600 dark:text-emerald-400 font-medium">Active</span>
                    @endif
                </div>
            </div>

            {{-- Cards 2, 3, 4: Annual (12), Casual (8), Sick (5) --}}
            @foreach ($cards as $card)
                @php
                    $iconComponent = match($card['icon'] ?? '') {
                        'heroicon-o-sun'      => 'heroicon-o-sun',
                        'heroicon-o-sparkles' => 'heroicon-o-sparkles',
                        'heroicon-o-heart'    => 'heroicon-o-heart',
                        default               => 'heroicon-o-calendar',
                    };

                    $iconStyle = match($card['color'] ?? '') {
                        'success' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400',
                        'info'    => 'bg-sky-50 text-sky-600 dark:bg-sky-950/60 dark:text-sky-400',
                        'danger'  => 'bg-rose-50 text-rose-600 dark:bg-rose-950/60 dark:text-rose-400',
                        default   => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
                    };

                    $badgeColor = match($card['color'] ?? '') {
                        'success' => 'success',
                        'info'    => 'info',
                        'danger'  => 'danger',
                        default   => 'gray',
                    };
                @endphp

                <div class="flex flex-col justify-between rounded-xl border border-gray-200/80 bg-gray-50/50 p-4 transition-all dark:border-white/10 dark:bg-white/5">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400 truncate">
                            {{ $card['name'] }}
                        </span>
                        <span class="flex h-7 w-7 items-center justify-center rounded-lg {{ $iconStyle }}">
                            <x-filament::icon :icon="$iconComponent" class="h-4 w-4" />
                        </span>
                    </div>

                    <div class="mt-3">
                        <div class="flex items-baseline gap-1.5">
                            <span class="text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
                                {{ number_format($card['left'], 1) }}
                            </span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                / {{ number_format($card['allocated'], 1) }} days left
                            </span>
                        </div>
                    </div>

                    <div class="mt-3 flex items-center justify-between border-t border-gray-200/60 pt-2.5 text-2xs text-gray-500 dark:border-white/5 dark:text-gray-400">
                        <span>Used: <strong class="text-gray-700 dark:text-gray-300">{{ number_format($card['taken'], 1) }}</strong></span>
                        <x-filament::badge :color="$badgeColor" size="xs">
                            {{ number_format($card['allocated'], 0) }} Alloc
                        </x-filament::badge>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Subtle Recent Leave Activity (if any) --}}
        @if (!empty($recent))
            <div class="mt-4 pt-3 border-t border-gray-100 dark:border-white/5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-2xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">
                        Recent Applications
                    </span>
                    <div class="flex flex-wrap items-center gap-3">
                        @foreach ($recent as $r)
                            @php
                                $rColor = match($r['status_color']) {
                                    'success' => 'success',
                                    'warning' => 'warning',
                                    'danger'  => 'danger',
                                    'info'    => 'info',
                                    default   => 'gray',
                                };
                            @endphp
                            <div class="flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300">
                                <span class="font-medium text-gray-900 dark:text-white">{{ $r['type'] }}</span>
                                <span class="text-gray-400 dark:text-gray-500">({{ $r['days'] }}d, {{ $r['date_range'] }})</span>
                                <x-filament::badge :color="$rColor" size="xs">
                                    {{ $r['status_label'] }}
                                </x-filament::badge>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
