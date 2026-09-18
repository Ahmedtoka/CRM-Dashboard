<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered()
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen()
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('inbox', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password()
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    /**
     * Final fix wave I2: an Inertia logout must force a full page load so no
     * client-side module state (notification channels, prefs, items) survives
     * into the next user's session in the same tab.
     */
    public function test_inertia_logout_forces_a_full_page_reload()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout', [], ['X-Inertia' => 'true']);

        $this->assertGuest();
        $response->assertStatus(409)->assertHeader('X-Inertia-Location', url('/'));
    }
}
