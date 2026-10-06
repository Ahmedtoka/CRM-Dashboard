<?php

use App\Ads\AdsServiceProvider;
use App\Analytics\AnalyticsServiceProvider;
use App\Bot\BotServiceProvider;
use App\Channels\ChannelsServiceProvider;
use App\Comments\CommentsServiceProvider;
use App\Commerce\CommerceServiceProvider;
use App\Inbox\InboxServiceProvider;
use App\Legal\LegalServiceProvider;
use App\Media\MediaServiceProvider;
use App\Providers\AppServiceProvider;
use App\Queue\QueueServiceProvider;
use App\Shopify\ShopifyServiceProvider;
use App\Simulator\SimulatorServiceProvider;

return [
    AppServiceProvider::class,
    ChannelsServiceProvider::class,
    InboxServiceProvider::class,
    MediaServiceProvider::class,
    CommentsServiceProvider::class,
    BotServiceProvider::class,
    CommerceServiceProvider::class,
    ShopifyServiceProvider::class,
    AnalyticsServiceProvider::class,
    SimulatorServiceProvider::class,
    LegalServiceProvider::class,
    QueueServiceProvider::class,
    AdsServiceProvider::class,
];
