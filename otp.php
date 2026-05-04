<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = App\Models\User::where('email', 'test@tupay.com')->firstOrFail();
$g2fa = new PragmaRX\Google2FA\Google2FA();
$code = $g2fa->getCurrentOtp($user->google2fa_secret);

echo PHP_EOL;
echo '==============================' . PHP_EOL;
echo '  2FA Code : ' . $code . PHP_EOL;
echo '  Valid for : ~30 seconds' . PHP_EOL;
echo '==============================' . PHP_EOL . PHP_EOL;
