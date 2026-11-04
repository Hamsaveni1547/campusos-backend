<?php

namespace App\Modules\Finance;

use App\Shared\Settings\SettingsRegistry;
use Illuminate\Support\ServiceProvider;

class FinanceServiceProvider extends ServiceProvider
{
    public function boot(SettingsRegistry $settings): void
    {
        $settings->register('finance', 'Fees', [
            'receipt_no_format' => ['type' => 'string', 'default' => '{INST}/RCPT/{YYYY}/{SEQ:6}', 'rules' => ['string', 'max:60', 'regex:/\{SEQ(:\d+)?\}/'], 'label' => 'Receipt number format ({INST}, {YYYY}, {YY}, {SEQ:n})'],
        ]);
    }
}
