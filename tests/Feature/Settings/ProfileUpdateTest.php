<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/settings/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    /**
     * Accounts are managed by admins (deactivation keeps attribution history);
     * users can no longer hard-delete themselves.
     */
    public function test_users_cannot_delete_their_own_account()
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->delete('/settings/profile', [
                'password' => 'password',
            ])
            ->assertStatus(405);

        $this->assertAuthenticated();
        $this->assertNotNull($user->fresh());
    }

    public function test_deactivated_users_lose_access_to_account_settings()
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->actingAs($user)->get('/settings/profile')->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
