<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Channels\ChannelsServiceProvider::class,
    App\Inbox\InboxServiceProvider::class,
    App\Media\MediaServiceProvider::class,
    App\Comments\CommentsServiceProvider::class,
    App\Bot\BotServiceProvider::class,
    App\Commerce\CommerceServiceProvider::class,
    App\Shipping\ShippingServiceProvider::class,
    App\Shopify\ShopifyServiceProvider::class,
    App\Analytics\AnalyticsServiceProvider::class,
    App\Simulator\SimulatorServiceProvider::class,
    App\Legal\LegalServiceProvider::class,
];
