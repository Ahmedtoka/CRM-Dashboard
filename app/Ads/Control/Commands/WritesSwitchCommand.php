<?php

namespace App\Ads\Control\Commands;

use App\Ads\AdsSettings;
use App\Ads\Audit\AdsAudit;
use App\Ads\Control\Write\WriteSwitch;
use Illuminate\Console\Command;

/** The global CRM writes kill switch (R-26). Server access only until B9 re-authentication ships. Stop is always exempt. */
class WritesSwitchCommand extends Command
{
    protected $signature = 'ads:writes
        {--on : Switch CRM writes back on}
        {--off : Switch every CRM write off except Stop (needs --reason)}
        {--reason= : Why (stored in the audit log)}';

    protected $description = 'Show or flip the global CRM ad-writes kill switch (Stop always keeps working)';

    public function handle(AdsSettings $settings): int
    {
        $on = (bool) $this->option('on');
        $off = (bool) $this->option('off');
        $reason = trim((string) $this->option('reason'));

        if ($on && $off) {
            $this->error('Use either --on or --off.');

            return self::FAILURE;
        }
        if ($off && $reason === '') {
            $this->error('--off needs --reason="...".');

            return self::FAILURE;
        }

        if ($on || $off) {
            $before = WriteSwitch::settingEnabled();
            $after = $on;
            if ($before !== $after) {
                $settings->set(WriteSwitch::SETTING, $after);
                AdsAudit::record('settings.writes_enabled_changed', null, ['writes_enabled' => $before], ['writes_enabled' => $after], array_filter(['reason' => $reason]));
            }
        }

        $this->line('config CRM_ADS_WRITES_ENABLED: '.(WriteSwitch::configEnabled() ? 'on' : 'off'));
        $this->line('setting writes_enabled: '.(WriteSwitch::settingEnabled() ? 'on' : 'off'));
        $this->line('effective: '.(WriteSwitch::enabled() ? 'on' : 'off'));
        $this->line('Stop keeps working whatever the switch says.');

        return self::SUCCESS;
    }
}
