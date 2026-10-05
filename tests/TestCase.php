<?php

namespace Tests;

use App\Ads\Platforms\Fake\FakeAdsDriver;
use App\Bot\Flow\Orders\FakeOmsClient;
use App\Channels\Adapters\FakeChannelAdapter;
use App\Commerce\FakeCommerceProvider;
use App\Shopify\Client\FakeShopifyTransport;
use App\TestLinks\TestSessionSteps;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FakeChannelAdapter::reset();

        // Its static store registry (orders found by `crm-order-{id}` tag) must not
        // leak between tests: SQLite reuses order ids after each rollback.
        FakeCommerceProvider::reset();

        // Same reasoning: customers/webhooks created through the fake Shopify
        // transport (Task 10) must not leak between tests.
        FakeShopifyTransport::reset();

        // OMS statuses set by one bot test must not answer another's lookup.
        FakeOmsClient::reset();

        // The test-link step recorder's "already recorded" memo is static: SQLite
        // reuses conversation ids after each rollback, so it must not leak either.
        TestSessionSteps::reset();

        // The fake ad writer's fault queue and race hook are static: one write test must not fail the next.
        FakeAdsDriver::reset();
    }
}
