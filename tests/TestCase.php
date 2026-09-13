<?php

namespace Tests;

use App\Channels\Adapters\FakeChannelAdapter;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FakeChannelAdapter::reset();
    }
}
