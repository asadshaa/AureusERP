<x-filament-widgets::widget>
    @php
        $emp = $employee ?? [];
        $leaves = $leaveSummary ?? ['cards' => []];
        $recent = $recentLeaves ?? [];
        $urls = $urls ?? [];
    @endphp

    <div class="space-y-6">
        {{-- Top Section: Employee Profile Hero Card --}}
        <div class="relative overflow-hidden rounded-2xl border border-gray-200/80 bg-gradient-to-br from-white via-gray-50/50 to-primary-50/30 p-6 shadow-xs transition-all dark:border-white/10 dark:from-gray-900 dark:via-gray-900/90 dark:to-primary-950/20">
            <div class="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
                {{-- Left: Avatar + Identity + Tags --}}
                <div class="flex flex-col sm:flex-row items-start sm:items-center gap-5">
                    {{-- Avatar / Initials --}}
                    <div class="relative flex-shrink-0">
                        @if (!empty($emp['avatar_url']))
                            <img
                                src="{{ $emp['avatar_url'] }}"
                                alt="{{ $emp['name'] }}"
                                class="h-20 w-20 rounded-2xl object-cover ring-4 ring-primary-500/20 shadow-md dark:ring-primary-400/20"
                            />
                        @else
                            <div class="flex h-20 w-20 items-center justify-center rounded-2xl bg-gradient-to-br from-primary-600 to-primary-800 text-2xl font-bold text-white shadow-md ring-4 ring-primary-500/20 dark:from-primary-500 dark:to-primary-700">
                                {{ $emp['initials'] }}
                            </div>
                        @endif
                        <span class="absolute -bottom-1 -right-1 flex h-5 w-5 items-center justify-center rounded-full bg-emerald-500 ring-2 ring-white dark:ring-gray-900" title="Active Employee">
                            <span class="h-2 w-2 rounded-full bg-white"></span>
                        </span>
                    </div>

                    {{-- Name, Title, Badges --}}
                    <div class="space-y-1.5">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
                                {{ $emp['name'] }}
                            </h2>
                            <span class="inline-flex items-center rounded-md bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-700 dark:bg-gray-800 dark:text-gray-300 border border-gray-200 dark:border-gray-700">
                                {{ $emp['number'] }}
                            </span>
                            <span class="inline-flex items-center rounded-md bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-950/40 dark:text-emerald-400 dark:ring-emerald-500/30">
                                {{ $emp['employment_type'] }}
                            </span>
                        </div>

                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-gray-600 dark:text-gray-300">
                            <span class="flex items-center gap-1.5 font-medium text-gray-900 dark:text-gray-100">
                                <x-heroicon-m-briefcase class="h-4 w-4 text-primary-500" />
                                {{ $emp['job_title'] }}
                            </span>
                            <span class="text-gray-300 dark:text-gray-600">•</span>
                            <span class="flex items-center gap-1.5">
                                <x-heroicon-m-building-office-2 class="h-4 w-4 text-primary-500" />
                                {{ $emp['department'] }}
                            </span>
                            <span class="text-gray-300 dark:text-gray-600">•</span>
                            <span class="flex items-center gap-1.5">
                                <x-heroicon-m-user class="h-4 w-4 text-primary-500" />
                                Line Manager: <strong class="text-gray-800 dark:text-gray-200">{{ $emp['manager_name'] }}</strong>
                            </span>
                        </div>

                        {{-- Secondary Meta: Email, Phone, Joined --}}
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400 pt-1">
                            @if (!empty($emp['work_email']))
                                <span class="flex items-center gap-1">
                                    <x-heroicon-m-envelope class="h-3.5 w-3.5 text-gray-400" />
                                    {{ $emp['work_email'] }}
                                </span>
                            @endif
                            @if (!empty($emp['work_phone']) && $emp['work_phone'] !== '—')
                                <span class="flex items-center gap-1">
                                    <x-heroicon-m-phone class="h-3.5 w-3.5 text-gray-400" />
                                    {{ $emp['work_phone'] }}
                                </span>
                            @endif
                            @if (!empty($emp['work_location']))
                                <span class="flex items-center gap-1">
                                    <x-heroicon-m-map-pin class="h-3.5 w-3.5 text-gray-400" />
                                    {{ $emp['work_location'] }}
                                </span>
                            @endif
                            @if (!empty($emp['joining_date']))
                                <span class="flex items-center gap-1">
                                    <x-heroicon-m-calendar class="h-3.5 w-3.5 text-gray-400" />
                                    Joined: {{ $emp['joining_date'] }} @if($emp['tenure']) ({{ $emp['tenure'] }}) @endif
                                </span>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Right: Quick Action Buttons --}}
                <div class="flex flex-wrap items-center gap-2.5 lg:flex-col lg:items-end">
                    @if (!empty($urls['request_leave']))
                        <a
                            href="{{ $urls['request_leave'] }}"
                            class="inline-flex items-center justify-center gap-2 rounded-xl bg-primary-600 px-4 py-2.5 text-sm font-semibold text-white shadow-xs hover:bg-primary-500 transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 dark:bg-primary-500 dark:hover:bg-primary-400"
                        >
                            <x-heroicon-m-calendar-days class="h-4 w-4" />
                            Request Time Off
                        </a>
                    @endif

                    <div class="flex items-center gap-2">
                        @if (!empty($urls['my_attendance']))
                            <a
                                href="{{ $urls['my_attendance'] }}"
                                class="inline-flex items-center gap-1.5 rounded-xl border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 shadow-xs hover:bg-gray-50 transition-colors dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700/80"
                            >
                                <x-heroicon-m-clock class="h-3.5 w-3.5 text-primary-500" />
                                Attendance
                            </a>
                        @endif

                        @if (!empty($urls['profile']))
                            <a
                                href="{{ $urls['profile'] }}"
                                class="inline-flex items-center gap-1.5 rounded-xl border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 shadow-xs hover:bg-gray-50 transition-colors dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700/80"
                            >
                                <x-heroicon-m-user-circle class="h-3.5 w-3.5 text-primary-500" />
                                My Profile
                            </a>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Leave Balances Overview Section --}}
        <div class="rounded-2xl border border-gray-200/80 bg-white p-6 shadow-xs dark:border-white/10 dark:bg-gray-900">
            {{-- Section Header --}}
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-5 border-b border-gray-100 dark:border-gray-800">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-950/50 dark:text-primary-400">
                            <x-heroicon-o-chart-pie class="h-5 w-5" />
                        </span>
                        <h3 class="text-lg font-bold text-gray-950 dark:text-white">
                            My Leave Balances & Allowances
                        </h3>
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Overview of your current year time off entitlements, used days, and remaining balances.
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <div class="rounded-xl bg-gray-50 px-3.5 py-1.5 text-xs font-semibold text-gray-700 dark:bg-gray-800/80 dark:text-gray-200 border border-gray-200/70 dark:border-gray-700">
                        Total Balance: <strong class="text-primary-600 dark:text-primary-400 text-sm font-bold">{{ number_format($leaves['total_left'] ?? 0, 1) }}</strong> / {{ number_format($leaves['total_allocated'] ?? 0, 1) }} Days Left
                    </div>

                    @if (($leaves['total_pending'] ?? 0) > 0)
                        <span class="inline-flex items-center gap-1 rounded-xl bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 dark:bg-amber-950/40 dark:text-amber-400 ring-1 ring-inset ring-amber-600/20">
                            <x-heroicon-m-clock class="h-3.5 w-3.5" />
                            {{ number_format($leaves['total_pending'], 1) }} Pending Approval
                        </span>
                    @endif
                </div>
            </div>

            {{-- Leave Type Cards Grid --}}
            <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @forelse ($leaves['cards'] ?? [] as $card)
                    @php
                        $colorScheme = match($card['theme']) {
                            'danger'    => ['border' => 'border-rose-200 dark:border-rose-900/50', 'bg' => 'bg-rose-50/40 dark:bg-rose-950/20', 'icon' => 'text-rose-600 dark:text-rose-400', 'bar' => 'bg-rose-500', 'badge' => 'text-rose-700 dark:text-rose-300'],
                            'warning'   => ['border' => 'border-amber-200 dark:border-amber-900/50', 'bg' => 'bg-amber-50/40 dark:bg-amber-950/20', 'icon' => 'text-amber-600 dark:text-amber-400', 'bar' => 'bg-amber-500', 'badge' => 'text-amber-700 dark:text-amber-300'],
                            'success'   => ['border' => 'border-emerald-200 dark:border-emerald-900/50', 'bg' => 'bg-emerald-50/40 dark:bg-emerald-950/20', 'icon' => 'text-emerald-600 dark:text-emerald-400', 'bar' => 'bg-emerald-500', 'badge' => 'text-emerald-700 dark:text-emerald-300'],
                            'info'      => ['border' => 'border-sky-200 dark:border-sky-900/50', 'bg' => 'bg-sky-50/40 dark:bg-sky-950/20', 'icon' => 'text-sky-600 dark:text-sky-400', 'bar' => 'bg-sky-500', 'badge' => 'text-sky-700 dark:text-sky-300'],
                            'secondary' => ['border' => 'border-purple-200 dark:border-purple-900/50', 'bg' => 'bg-purple-50/40 dark:bg-purple-950/20', 'icon' => 'text-purple-600 dark:text-purple-400', 'bar' => 'bg-purple-500', 'badge' => 'text-purple-700 dark:text-purple-300'],
                            default     => ['border' => 'border-primary-200 dark:border-primary-900/50', 'bg' => 'bg-primary-50/40 dark:bg-primary-950/20', 'icon' => 'text-primary-600 dark:text-primary-400', 'bar' => 'bg-primary-500', 'badge' => 'text-primary-700 dark:text-primary-300'],
                        };
                    @endphp

                    <div class="relative flex flex-col justify-between rounded-xl border {{ $colorScheme['border'] }} {{ $colorScheme['bg'] }} p-4.5 transition-all hover:shadow-md dark:shadow-none">
                        <div>
                            {{-- Header: Type name & icon --}}
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm font-bold text-gray-900 dark:text-white truncate" title="{{ $card['name'] }}">
                                    {{ $card['name'] }}
                                </span>
                                <span class="{{ $colorScheme['icon'] }}">
                                    @if ($card['theme'] === 'danger')
                                        <x-heroicon-o-heart class="h-5 w-5" />
                                    @elseif ($card['theme'] === 'warning')
                                        <x-heroicon-o-sparkles class="h-5 w-5" />
                                    @elseif ($card['theme'] === 'success')
                                        <x-heroicon-o-sun class="h-5 w-5" />
                                    @elseif ($card['theme'] === 'info')
                                        <x-heroicon-o-user-group class="h-5 w-5" />
                                    @elseif ($card['theme'] === 'secondary')
                                        <x-heroicon-o-academic-cap class="h-5 w-5" />
                                    @else
                                        <x-heroicon-o-calendar-days class="h-5 w-5" />
                                    @endif
                                </span>
                            </div>

                            {{-- Main Highlight: Days Left --}}
                            <div class="mt-3 flex items-baseline gap-2">
                                <span class="text-3xl font-extrabold tracking-tight text-gray-950 dark:text-white">
                                    {{ number_format($card['left'], 1) }}
                                </span>
                                <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                    Days Left
                                </span>
                            </div>

                            {{-- Progress Bar --}}
                            <div class="mt-3.5 space-y-1">
                                <div class="flex justify-between text-2xs text-gray-500 dark:text-gray-400">
                                    <span>Remaining</span>
                                    <span>{{ $card['percentage'] }}%</span>
                                </div>
                                <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200/80 dark:bg-gray-800">
                                    <div
                                        class="h-full rounded-full {{ $colorScheme['bar'] }} transition-all duration-500"
                                        style="width: {{ $card['percentage'] }}%"
                                    ></div>
                                </div>
                            </div>
                        </div>

                        {{-- Breakdown Footer --}}
                        <div class="mt-4 border-t border-gray-200/60 pt-3 dark:border-white/5">
                            <div class="grid grid-cols-3 text-center text-xs">
                                <div class="pr-1 text-left">
                                    <span class="block text-3xs font-medium uppercase text-gray-400 dark:text-gray-500">Allocated</span>
                                    <span class="font-bold text-gray-800 dark:text-gray-200">{{ number_format($card['allocated'], 1) }}</span>
                                </div>
                                <div class="px-1 text-center border-x border-gray-200/50 dark:border-white/5">
                                    <span class="block text-3xs font-medium uppercase text-gray-400 dark:text-gray-500">Used</span>
                                    <span class="font-bold text-gray-800 dark:text-gray-200">{{ number_format($card['taken'], 1) }}</span>
                                </div>
                                <div class="pl-1 text-right">
                                    <span class="block text-3xs font-medium uppercase text-gray-400 dark:text-gray-500">Pending</span>
                                    <span class="font-bold text-amber-600 dark:text-amber-400">{{ number_format($card['pending'], 1) }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="col-span-full py-8 text-center text-sm text-gray-500 dark:text-gray-400">
                        No active leave categories configured for your company yet.
                    </div>
                @endforelse
            </div>

            {{-- Recent Requests Subsection --}}
            @if (!empty($recent))
                <div class="mt-8 border-t border-gray-100 pt-5 dark:border-gray-800">
                    <div class="flex items-center justify-between pb-3">
                        <h4 class="text-xs font-bold uppercase tracking-wider text-gray-600 dark:text-gray-400">
                            Recent Time Off Applications
                        </h4>
                        @if (!empty($urls['request_leave']))
                            <a href="{{ $urls['request_leave'] }}" class="text-xs font-semibold text-primary-600 hover:text-primary-500 dark:text-primary-400">
                                Apply New Leave →
                            </a>
                        @endif
                    </div>

                    <div class="grid grid-cols-1 gap-2.5 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($recent as $r)
                            @php
                                $badgeStyle = match($r['status_color']) {
                                    'success' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-950/40 dark:text-emerald-400 dark:ring-emerald-500/30',
                                    'warning' => 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-950/40 dark:text-amber-400 dark:ring-amber-500/30',
                                    'danger'  => 'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-950/40 dark:text-rose-400 dark:ring-rose-500/30',
                                    'info'    => 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-950/40 dark:text-sky-400 dark:ring-sky-500/30',
                                    default   => 'bg-gray-100 text-gray-700 ring-gray-600/20 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700',
                                };
                            @endphp

                            <div class="rounded-xl border border-gray-200/70 bg-gray-50/60 p-3 dark:border-gray-800 dark:bg-gray-800/40">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-xs font-bold text-gray-900 dark:text-white truncate">
                                        {{ $r['type'] }}
                                    </span>
                                    <span class="inline-flex items-center rounded-md px-1.5 py-0.5 text-3xs font-semibold ring-1 ring-inset {{ $badgeStyle }}">
                                        {{ $r['status_label'] }}
                                    </span>
                                </div>
                                <div class="mt-1 flex items-center justify-between text-2xs text-gray-500 dark:text-gray-400">
                                    <span>{{ $r['date_range'] }}</span>
                                    <span class="font-semibold text-gray-700 dark:text-gray-300">{{ $r['days'] }} day{{ $r['days'] > 1 ? 's' : '' }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-filament-widgets::widget>
