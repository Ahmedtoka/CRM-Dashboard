<?php

namespace App\Ads\Doctor\Checks;

use App\Ads\Doctor\DoctorRow;
use App\Ads\Sync\HistoryWindow;
use Symfony\Component\Process\Process;

class AppCheck extends DoctorCheck
{
    protected function section(): string
    {
        return 'App';
    }

    public function run(): array
    {
        $rows = [];
        $env = (string) app()->environment();
        $prod = $env === 'production';

        if (is_dir(base_path('.git'))) {
            $p = new Process(['git', 'rev-parse', 'HEAD'], base_path(), null, null, 10);
            $p->run();
            $sha = trim($p->getOutput());
            $rows[] = $p->isSuccessful() && $sha !== ''
                ? DoctorRow::ok('App', 'deployed commit', substr($sha, 0, 12))
                : DoctorRow::warn('App', 'deployed commit', 'git failed', 'git rev-parse HEAD did not answer');
        } else {
            $rows[] = DoctorRow::ok('App', 'deployed commit', 'no .git, panel deploy');
        }

        $rows[] = DoctorRow::ok('App', 'APP_ENV', $env);
        $debug = (bool) config('app.debug');
        $rows[] = DoctorRow::by(! ($prod && $debug), 'fail', 'App', 'APP_DEBUG', $debug ? 'true' : 'false', 'Debug must be off in production: error pages leak settings.');
        $driver = (string) config('crm.ads.drivers.meta', 'fake');
        $rows[] = DoctorRow::by(! $prod || $driver === 'live', 'fail', 'App', 'Meta driver', $driver, 'Production must run the live driver; fake data would replace real spend.');
        $rows[] = DoctorRow::ok('App', 'history start', HistoryWindow::start()->toDateString());
        $mailer = (string) config('mail.default');
        $rows[] = DoctorRow::by($mailer !== 'log', 'warn', 'App', 'MAIL_MAILER', $mailer, 'Mailer is "log": alert emails will not leave the server.');

        return $rows;
    }
}
