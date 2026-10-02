<x-filament-widgets::widget>
    <x-filament::section class="overflow-hidden">
        <x-slot name="heading">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-950/50 dark:text-primary-400">
                        <x-filament::icon icon="heroicon-o-user-group" class="h-5 w-5" />
                    </div>
                    <div>
                        <h2 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">
                            Today's Roll Call & Attendance
                        </h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            {{ $todayDate }} · Live company attendance status
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <x-filament::button
                        :href="$attendanceUrl"
                        tag="a"
                        size="xs"
                        color="gray"
                        outlined
                        icon="heroicon-m-arrow-top-right-on-square"
                    >
                        Attendance Register
                    </x-filament::button>
                </div>
            </div>
        </x-slot>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 0.875rem;" class="pt-1">
            {{-- Total Workforce --}}
            <a
                href="{{ $attendanceUrl }}"
                class="group relative flex flex-col justify-between rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 transition-all duration-150 hover:shadow-md hover:ring-primary-500/50 dark:bg-white/5 dark:ring-white/10 dark:hover:ring-primary-400/50"
            >
                <div class="flex items-center justify-between">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300">
                        <x-filament::icon icon="heroicon-o-users" class="h-4 w-4" />
                    </div>
                    <span class="text-xs font-semibold text-gray-600 dark:text-gray-400">Total Staff</span>
                </div>
                <div class="mt-3 text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
                    {{ $totalEmployees }}
                </div>
                <div class="mt-1 flex items-center justify-between text-[11px] text-gray-500 dark:text-gray-400">
                    <span>Active headcount</span>
                    <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5 text-primary-500 opacity-0 transition-opacity group-hover:opacity-100" />
                </div>
            </a>

            {{-- Present / Checked In --}}
            <a
                href="{{ $attendanceUrl }}?tableFilters[status][value]=present"
                class="group relative flex flex-col justify-between rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 transition-all duration-150 hover:shadow-md hover:ring-emerald-500/50 dark:bg-white/5 dark:ring-white/10 dark:hover:ring-emerald-400/50"
            >
                <div class="flex items-center justify-between">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-950/50 dark:text-emerald-400">
                        <x-filament::icon icon="heroicon-o-check-circle" class="h-4 w-4" />
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="relative flex h-2 w-2">
                            <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex h-2 w-2 rounded-full bg-emerald-500"></span>
                        </span>
                        <span class="text-xs font-semibold text-emerald-700 dark:text-emerald-400">Checked In</span>
                    </div>
                </div>
                <div class="mt-3 text-2xl font-bold tracking-tight text-emerald-900 dark:text-emerald-300">
                    {{ $presentCount }}
                </div>
                <div class="mt-1 flex items-center justify-between text-[11px] text-emerald-700/80 dark:text-emerald-400/80">
                    <span>Present on shift</span>
                    <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5 text-emerald-500 opacity-0 transition-opacity group-hover:opacity-100" />
                </div>
            </a>

            {{-- Late Arrivals --}}
            <a
                href="{{ $attendanceUrl }}"
                class="group relative flex flex-col justify-between rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 transition-all duration-150 hover:shadow-md hover:ring-amber-500/50 dark:bg-white/5 dark:ring-white/10 dark:hover:ring-amber-400/50"
            >
                <div class="flex items-center justify-between">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-50 text-amber-600 dark:bg-amber-950/50 dark:text-amber-400">
                        <x-filament::icon icon="heroicon-o-clock" class="h-4 w-4" />
                    </div>
                    <span class="text-xs font-semibold text-amber-700 dark:text-amber-400">Late Arrivals</span>
                </div>
                <div class="mt-3 text-2xl font-bold tracking-tight text-amber-900 dark:text-amber-300">
                    {{ $lateCount }}
                </div>
                <div class="mt-1 flex items-center justify-between text-[11px] text-amber-700/80 dark:text-amber-400/80">
                    <span>After shift start</span>
                    <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5 text-amber-500 opacity-0 transition-opacity group-hover:opacity-100" />
                </div>
            </a>

            {{-- On Leave --}}
            <a
                href="{{ url('/admin/time-off/time-offs') }}"
                class="group relative flex flex-col justify-between rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 transition-all duration-150 hover:shadow-md hover:ring-blue-500/50 dark:bg-white/5 dark:ring-white/10 dark:hover:ring-blue-400/50"
            >
                <div class="flex items-center justify-between">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-50 text-blue-600 dark:bg-blue-950/50 dark:text-blue-400">
                        <x-filament::icon icon="heroicon-o-calendar" class="h-4 w-4" />
                    </div>
                    <span class="text-xs font-semibold text-blue-700 dark:text-blue-400">On Leave</span>
                </div>
                <div class="mt-3 text-2xl font-bold tracking-tight text-blue-900 dark:text-blue-300">
                    {{ $onLeaveCount }}
                </div>
                <div class="mt-1 flex items-center justify-between text-[11px] text-blue-700/80 dark:text-blue-400/80">
                    <span>Approved Time Off</span>
                    <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5 text-blue-500 opacity-0 transition-opacity group-hover:opacity-100" />
                </div>
            </a>

            {{-- Not Checked In --}}
            <a
                href="{{ $attendanceUrl }}"
                class="group relative flex flex-col justify-between rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 transition-all duration-150 hover:shadow-md hover:ring-rose-500/50 dark:bg-white/5 dark:ring-white/10 dark:hover:ring-rose-400/50"
            >
                <div class="flex items-center justify-between">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-rose-50 text-rose-600 dark:bg-rose-950/50 dark:text-rose-400">
                        <x-filament::icon icon="heroicon-o-arrow-right-on-rectangle" class="h-4 w-4" />
                    </div>
                    <span class="text-xs font-semibold text-rose-700 dark:text-rose-400">Not Checked In</span>
                </div>
                <div class="mt-3 text-2xl font-bold tracking-tight text-rose-900 dark:text-rose-300">
                    {{ $notCheckedIn }}
                </div>
                <div class="mt-1 flex items-center justify-between text-[11px] text-rose-700/80 dark:text-rose-400/80">
                    <span>Pending arrival / absent</span>
                    <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5 text-rose-500 opacity-0 transition-opacity group-hover:opacity-100" />
                </div>
            </a>

            {{-- Needs Review --}}
            <a
                href="{{ $attendanceUrl }}?tableFilters[verification_status][value]=needs_review"
                class="group relative flex flex-col justify-between rounded-xl bg-white p-4 shadow-sm ring-1 ring-gray-950/5 transition-all duration-150 hover:shadow-md hover:ring-purple-500/50 dark:bg-white/5 dark:ring-white/10 dark:hover:ring-purple-400/50"
            >
                <div class="flex items-center justify-between">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-purple-50 text-purple-600 dark:bg-purple-950/50 dark:text-purple-400">
                        <x-filament::icon icon="heroicon-o-shield-exclamation" class="h-4 w-4" />
                    </div>
                    <span class="text-xs font-semibold text-purple-700 dark:text-purple-400">Needs Review</span>
                </div>
                <div class="mt-3 text-2xl font-bold tracking-tight text-purple-900 dark:text-purple-300">
                    {{ $needsReviewCount }}
                </div>
                <div class="mt-1 flex items-center justify-between text-[11px] text-purple-700/80 dark:text-purple-400/80">
                    <span>Flagged punches</span>
                    <x-filament::icon icon="heroicon-m-arrow-right" class="h-3.5 w-3.5 text-purple-500 opacity-0 transition-opacity group-hover:opacity-100" />
                </div>
            </a>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
