<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ResetUserPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resets_only_the_selected_existing_users_password(): void
    {
        $selectedUser = User::factory()->create(['password' => 'original-password']);
        $otherUser = User::factory()->create(['password' => 'other-original-password']);
        $newPassword = 'new-local-password-2026';

        $this->artisan('user:reset-password')
            ->expectsQuestion('User email or ID', $selectedUser->email)
            ->expectsQuestion('New password', $newPassword)
            ->expectsQuestion('Confirm new password', $newPassword)
            ->expectsOutputToContain('Password updated for the selected user.')
            ->doesntExpectOutputToContain($newPassword)
            ->assertExitCode(0);

        $this->assertTrue(Hash::check($newPassword, $selectedUser->fresh()->password));
        $this->assertTrue(Hash::check('other-original-password', $otherUser->fresh()->password));
    }

    public function test_it_rejects_a_password_shorter_than_twelve_characters(): void
    {
        $user = User::factory()->create(['password' => 'original-password']);

        $this->artisan('user:reset-password')
            ->expectsQuestion('User email or ID', (string) $user->id)
            ->expectsQuestion('New password', 'short')
            ->expectsOutputToContain('Password must be at least 12 characters.')
            ->assertExitCode(1);

        $this->assertTrue(Hash::check('original-password', $user->fresh()->password));
    }

    public function test_it_does_not_change_the_password_when_confirmation_does_not_match(): void
    {
        $user = User::factory()->create(['password' => 'original-password']);

        $this->artisan('user:reset-password')
            ->expectsQuestion('User email or ID', $user->email)
            ->expectsQuestion('New password', 'new-local-password-2026')
            ->expectsQuestion('Confirm new password', 'different-local-password')
            ->expectsOutputToContain('Password confirmation did not match.')
            ->assertExitCode(1);

        $this->assertTrue(Hash::check('original-password', $user->fresh()->password));
    }

    public function test_it_does_not_create_a_user_for_an_unknown_identifier(): void
    {
        $userCount = User::query()->count();

        $this->artisan('user:reset-password')
            ->expectsQuestion('User email or ID', 'missing@example.test')
            ->expectsOutputToContain('No existing user matches that email or ID.')
            ->assertExitCode(1);

        $this->assertSame($userCount, User::query()->count());
    }
}
