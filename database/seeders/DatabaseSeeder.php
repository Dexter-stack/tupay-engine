<?php

namespace Database\Seeders;

use App\Models\ExchangeRate;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey();

        $user = User::updateOrCreate(
            ['email' => 'test@tupay.com'],
            [
                'name' => 'Test User',
                'password' => Hash::make('password'),
                'google2fa_secret' => $secret,
                'two_factor_confirmed_at' => now(),
            ],
        );

        Wallet::updateOrCreate(
            ['user_id' => $user->id, 'currency' => 'NGN'],
            ['balance' => 10_000_000],
        );

        Wallet::updateOrCreate(
            ['user_id' => $user->id, 'currency' => 'CNY'],
            ['balance' => 0],
        );

        ExchangeRate::create([
            'from_currency' => 'NGN',
            'to_currency' => 'CNY',
            'rate' => '0.00520000',
            'fetched_at' => now(),
        ]);

        $this->command->info('');
        $this->command->info('========================================');
        $this->command->info('  Test user seeded successfully');
        $this->command->info('  Email:    test@tupay.com');
        $this->command->info('  Password: password');
        $this->command->info("  2FA Secret: {$secret}");
        $this->command->info('  NGN Balance: 10,000,000 kobo (100,000 NGN)');
        $this->command->info('  CNY Balance: 0 fen');
        $this->command->info('');
        $this->command->warn('  Use an authenticator app (Google Authenticator,');
        $this->command->warn('  Authy) to scan the secret above and generate TOTP codes.');
        $this->command->info('========================================');
        $this->command->info('');
    }
}
