<?php

it('never wipes the application cache on deploy: locks, done marks and back-offs live there (F-053)', function () {
    $script = file_get_contents(base_path('deploy.sh'));

    expect($script)->not->toContain('optimize:clear')->not->toContain('cache:clear')
        ->and($script)->toContain('php artisan config:clear')->toContain('php artisan route:clear')
        ->toContain('php artisan view:clear')->toContain('php artisan event:clear');
});
