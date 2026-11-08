<?php

namespace Tests\Feature;

use App\Http\Requests\StoreExchangeAccountRequest;
use App\Models\ExchangeAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ExchangeAccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_exchange_account_defaults_to_disabled_testnet(): void
    {
        $account = new ExchangeAccount;

        $this->assertSame('testnet', config('bybit.default_environment'));
        $this->assertFalse(config('bybit.live_trading_enabled'));
        $this->assertSame('testnet', $account->environment);
        $this->assertFalse($account->is_enabled);
    }

    public function test_exchange_account_credentials_are_encrypted_at_rest_and_hidden_from_serialization(): void
    {
        $user = User::factory()->create();
        $account = $user->exchangeAccounts()->create($this->validSettings());

        $storedApiKey = DB::table('exchange_accounts')->where('id', $account->id)->value('api_key');
        $storedApiSecret = DB::table('exchange_accounts')->where('id', $account->id)->value('api_secret');

        $this->assertNotSame('fake-testnet-key', $storedApiKey);
        $this->assertNotSame('fake-testnet-secret', $storedApiSecret);
        $this->assertSame('fake-testnet-key', Crypt::decryptString($storedApiKey));
        $this->assertSame('fake-testnet-secret', Crypt::decryptString($storedApiSecret));
        $this->assertSame('fake-testnet-key', $account->fresh()->api_key);
        $this->assertArrayNotHasKey('api_key', $account->toArray());
        $this->assertArrayNotHasKey('api_secret', $account->toArray());
        $this->assertTrue($user->exchangeAccounts->contains($account));
    }

    public function test_exchange_account_settings_validation_accepts_testnet_configuration(): void
    {
        $validator = Validator::make($this->validSettings(), (new StoreExchangeAccountRequest)->rules());

        $this->assertTrue($validator->passes());
    }

    public function test_exchange_account_settings_validation_rejects_unknown_environment_and_missing_credentials(): void
    {
        $settings = $this->validSettings();
        $settings['environment'] = 'sandbox';
        $settings['api_secret'] = '';

        $validator = Validator::make($settings, (new StoreExchangeAccountRequest)->rules());

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('environment', $validator->errors()->toArray());
        $this->assertArrayHasKey('api_secret', $validator->errors()->toArray());
    }

    /** @return array<string, string|bool> */
    private function validSettings(): array
    {
        return [
            'name' => 'Testnet account',
            'environment' => 'testnet',
            'api_key' => 'fake-testnet-key',
            'api_secret' => 'fake-testnet-secret',
            'is_enabled' => false,
        ];
    }
}
