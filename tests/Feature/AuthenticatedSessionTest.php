<?php

namespace Tests\Feature;

use App\Livewire\ScannerDashboard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuthenticatedSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_strategy_owner_can_sign_in_and_see_editable_settings(): void
    {
        $user = User::factory()->create([
            'email' => 'scanner-owner@example.test',
            'password' => 'local-password',
        ]);
        $user->tradingStrategies()->create([
            'name' => 'Scanner',
            'is_active' => true,
            'minimum_turnover_24h_usdt' => '1000000.00000000',
        ]);

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'local-password',
        ]);

        $response->assertRedirect(route('scanner.dashboard'));
        $this->assertAuthenticatedAs($user);

        Livewire::test(ScannerDashboard::class)
            ->assertSee('Strategy Settings')
            ->assertSee('Minimum 24H Turnover (USDT)');
    }

    public function test_guest_sees_sign_in_hint_but_cannot_edit_strategy_settings(): void
    {
        $user = User::factory()->create();
        $user->tradingStrategies()->create([
            'name' => 'Scanner',
            'is_active' => true,
            'minimum_turnover_24h_usdt' => '1000000.00000000',
        ]);

        Livewire::test(ScannerDashboard::class)
            ->assertSee('Sign in as the strategy owner')
            ->assertDontSee('Save Settings')
            ->assertDontSee('Strategy Settings');
    }
}
