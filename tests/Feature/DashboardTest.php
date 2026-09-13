<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The starter-kit Welcome/Dashboard placeholders are gone: `/` and `/dashboard`
 * send signed-in users straight to the inbox and guests to the login page.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/')->assertRedirect('/login');
    }

    public function test_authenticated_users_are_sent_to_the_inbox()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get('/dashboard')->assertRedirect(route('inbox', absolute: false));
        $this->get('/')->assertRedirect(route('inbox', absolute: false));
    }
}
