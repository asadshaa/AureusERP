<div>
    <style>
        .invoice-container {
            width: 350px;
            background-color: white;
            padding: 20px;
            border-radius: 12px;
        }

        :is(.dark .invoice-container) {
            background-color: rgb(36 36 39);
            border: 1px solid rgb(44 44 47);
        }

        .invoice-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 14px;
            color: #555;
            gap: 8px;
        }

        :is(.dark .invoice-item) {
            color: #d1d5db;
        }

        .invoice-item span {
            font-weight: 600;
        }

        .invoice-item.font-bold span {
            font-weight: 700 !important;
            font-size: 16px;
        }

        .invoice-item.font-semibold span {
            font-weight: 600 !important;
        }

        .invoice-item button {
            flex-shrink: 0;
        }

        .divider {
            border-bottom: 1px solid #ddd;
            margin: 12px 0;
        }

        :is(.dark .divider) {
            border-bottom-color: #374151;
        }

        :is(.dark .total) {
            background-color: rgba(255, 255, 255, 0.05);
            color: #f3f4f6;
        }

        .footer {
            text-align: center;
            font-size: 12px;
            color: #777;
            margin-top: 10px;
        }

        :is(.dark .footer) {
            color: #9ca3af;
        }
    </style>

    <div class="flex flex-col lg:flex-row items-start justify-between gap-6 w-full pt-3">
        @if ($driveInfo = $this->getDriveSyncInfo())
            <div class="w-full lg:max-w-md p-4 rounded-xl border border-gray-200 dark:border-gray-800 bg-gray-50/70 dark:bg-gray-900/60 shadow-xs space-y-3">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2.5">
                        <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                            <x-filament::icon icon="heroicon-o-cloud-arrow-up" class="w-5 h-5" />
                        </span>
                        <div>
                            <h4 class="text-sm font-semibold text-gray-900 dark:text-white">Google Drive Backup</h4>
                            <span class="inline-flex items-center gap-1 text-[11px] font-medium text-emerald-600 dark:text-emerald-400">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                Synced to Cloud
                            </span>
                        </div>
                    </div>
                    @if (! empty($driveInfo['url']))
                        <a href="{{ $driveInfo['url'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-semibold rounded-lg bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200 border border-gray-300 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/60 shadow-xs transition">
                            <span>Open in Drive</span>
                            <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="w-3.5 h-3.5 text-gray-400" />
                        </a>
                    @endif
                </div>

                <div class="text-xs space-y-1.5 border-t border-gray-200/60 dark:border-gray-800 pt-2.5 text-gray-600 dark:text-gray-300">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-gray-500 dark:text-gray-400">Folder Location:</span>
                        <span class="font-medium text-gray-800 dark:text-gray-200 truncate max-w-[220px]" title="{{ $driveInfo['folder_path'] }}">
                            {{ $driveInfo['folder_path'] }}
                        </span>
                    </div>
                    @if (! empty($driveInfo['uploader']))
                        <div class="flex items-center justify-between">
                            <span class="text-gray-500 dark:text-gray-400">Synced By:</span>
                            <span class="font-medium text-gray-800 dark:text-gray-200">{{ $driveInfo['uploader'] }}</span>
                        </div>
                    @endif
                    @if (! empty($driveInfo['synced_at']))
                        <div class="flex items-center justify-between">
                            <span class="text-gray-500 dark:text-gray-400">Synced At:</span>
                            <span class="font-medium text-gray-800 dark:text-gray-200">{{ $driveInfo['synced_at'] }}</span>
                        </div>
                    @endif
                </div>
            </div>
        @else
            <div></div>
        @endif

        <div class="invoice-container ml-auto">
            <div class="invoice-item">
                <span>{{ __('accounts::filament/resources/invoice.table.columns.tax-excluded') }}</span>
                <span>{{ money($subtotal, $currency?->name) }}</span>
            </div>

            @if ($totalTax > 0)
                <div class="invoice-item">
                    <span>{{ __('accounts::filament/resources/invoice.table.columns.tax') }}</span>
                    <span>{{ money($totalTax, $currency?->name) }}</span>
                </div>
            @endif

            @if ($rounding != 0)
                <div class="invoice-item">
                    <span>{{ __('accounts::filament/resources/invoice.form.tabs.other-information.fieldset.accounting.fields.cash-rounding') }}</span>
                    <span>{{ money($rounding, $currency?->name) }}</span>
                </div>
            @endif

            <div class="divider"></div>

            <div class="invoice-item font-semibold">
                <span>{{ __('accounts::filament/resources/invoice.table.columns.total') }}</span>
                <span>{{ money($grandTotal, $currency?->name) }}</span>
            </div>

            <!-- Reconciled Payments Section -->
            @if ($reconciledPayments && ! empty($reconciledPayments['lines']))
                <div class="divider"></div>
                
                @foreach ($reconciledPayments['lines'] ?? [] as $line)
                    <div class="invoice-item items-center">
                        <div class="flex items-center gap-2">
                            {{ ($this->unReconcileAction())(['partial_id' => $line['partial_id']]) }}

                            <div class="flex-1">
                                @if ($url = $this->getResourceUrl($line))
                                    <x-filament::link :href="$url">
                                        {{ $line['ref'] }}
                                    </x-filament::link>
                                @else
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                        {{ $line['ref'] }}
                                    </span>
                                @endif
                                
                                @if (! empty($line['date']))
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        Paid on {{ $line['date'] instanceof \Carbon\CarbonInterface ? $line['date']->format('M d, Y') : \Illuminate\Support\Carbon::parse($line['date'])->format('M d, Y') }}
                                    </div>
                                @endif
                            </div>
                        </div>

                        <span class="font-semibold">
                            {{ $line['amount_currency'] }}
                        </span>
                    </div>
                @endforeach
            @endif

            <!-- Reconcilable Payments Section -->
            @if ($reconcilablePayments && $reconcilablePayments['outstanding'])
                <div class="divider"></div>

                <div class="mt-4 font-semibold">
                    {{ $reconcilablePayments['title'] }}
                </div>

                @foreach ($reconcilablePayments['lines'] ?? [] as $line)
                    <div class="invoice-item items-center">
                        <div class="flex items-center gap-2">
                            {{ ($this->reconcileAction())(['lineId' => $line['id']]) }}

                            <div class="flex-1">
                                @if ($url = $this->getResourceUrl($line))
                                    <x-filament::link :href="$url">
                                        {{ $line['journal_name'] }}
                                    </x-filament::link>
                                @else
                                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                                        {{ $line['journal_name'] }}
                                    </span>
                                @endif
                                
                                @if (! empty($line['date']))
                                    <div class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ $line['date'] instanceof \Carbon\CarbonInterface ? $line['date']->format('M d, Y') : \Illuminate\Support\Carbon::parse($line['date'])->format('M d, Y') }}
                                    </div>
                                @endif
                            </div>
                        </div>
                        
                        <span class="font-semibold">
                            {{ money($line['amount'], $currency?->name) }}
                        </span>
                    </div>
                @endforeach
            @endif

            <!-- Amount due or residual -->
            @if ($record?->state === \Webkul\Account\Enums\MoveState::POSTED)
                <div class="divider"></div>

                <div class="invoice-item total font-bold">
                    <span>
                        Amount Due
                    </span>

                    <span>
                        {{ money($record->amount_residual, $currency?->name) }}
                    </span>
                </div>
            @endif
        </div>
    </div>

    <x-filament-actions::modals />
</div>
