<?php
use App\Http\Requests\StoreExchangeAccountRequest;
use App\Models\ExchangeAccount;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
$_ENV['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = ':memory:';
$_SERVER['DB_CONNECTION'] = 'sqlite';
$_SERVER['DB_DATABASE'] = ':memory:';
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Artisan::call('migrate:fresh', ['--force' => true]);

$settings = ['name' => 'Local Testnet', 'environment' => 'testnet', 'api_key' => 'fake-key', 'api_secret' => 'fake-secret', 'is_enabled' => false];
$validator = Validator::make($settings, (new StoreExchangeAccountRequest)->rules());
if ($validator->fails()) throw new RuntimeException('Valid settings rejected: '.$validator->errors()->toJson());
$invalid = $settings;
$invalid['environment'] = 'unknown';
$invalid['api_secret'] = '';
$validator = Validator::make($invalid, (new StoreExchangeAccountRequest)->rules());
if ($validator->passes()) throw new RuntimeException('Invalid settings passed validation.');

$user = User::factory()->create();
$account = $user->exchangeAccounts()->create($settings);
$storedKey = DB::table('exchange_accounts')->where('id', $account->id)->value('api_key');
$storedSecret = DB::table('exchange_accounts')->where('id', $account->id)->value('api_secret');
if ($storedKey === 'fake-key' || Crypt::decryptString($storedKey) !== 'fake-key') throw new RuntimeException('API key encryption check failed.');
if ($storedSecret === 'fake-secret' || Crypt::decryptString($storedSecret) !== 'fake-secret') throw new RuntimeException('API secret encryption check failed.');
if ($account->fresh()->api_secret !== 'fake-secret') throw new RuntimeException('Encrypted secret did not decrypt on model read.');
if (array_key_exists('api_key', $account->toArray()) || array_key_exists('api_secret', $account->toArray())) throw new RuntimeException('Credential serialization hiding failed.');
if (!$user->exchangeAccounts->contains($account)) throw new RuntimeException('User relationship check failed.');
$default = new ExchangeAccount;
if ($default->environment !== 'testnet' || $default->is_enabled !== false) throw new RuntimeException('Safe account defaults check failed.');
echo "Phase 1 checks passed: migrations, validation, encrypted credentials, serialization hiding, relationship, and safe defaults.\n";
