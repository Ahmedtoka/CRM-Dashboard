<?php
// Cloudways cron (Cron Job Management -> PHP -> cron-schedule.php): runs the Laravel scheduler every minute.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
chdir(__DIR__);
passthru(escapeshellarg(PHP_BINARY).' artisan schedule:run >> /dev/null 2>&1', $code);
exit($code);
