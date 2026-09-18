<?php

use App\Models\BotFlow;
use App\Models\BotKnowledgeEntry;

it('seeds 7 flows with the expected main menu options and the closing scripts', function () {
    expect(BotFlow::count())->toBe(7);

    $mainMenu = BotFlow::active('main_menu');
    expect($mainMenu)->not->toBeNull()
        ->and($mainMenu->definition['steps']['menu']['options'])->toHaveCount(7);

    expect(BotKnowledgeEntry::where('key', 'script.flow_return_recorded')->exists())->toBeTrue();
});

it('keeps an owner edit and does not duplicate rows when the seed migration runs again', function () {
    $flow = BotFlow::active('main_menu');
    $flow->title_ar = 'قائمة معدلة يدويًا';
    $flow->save();

    (require database_path('migrations/2026_09_17_100050_seed_levoile_flows_and_scripts.php'))->up();

    expect(BotFlow::count())->toBe(7)
        ->and(BotFlow::active('main_menu')->title_ar)->toBe('قائمة معدلة يدويًا');
});
