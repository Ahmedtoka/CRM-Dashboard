<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_home_page_sends_guests_to_login()
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }
}
