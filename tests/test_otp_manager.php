<?php
require_once __DIR__ . '/../src/Ricktorious/Ecommerce/User/OneTimePasswordManager.php';

use Ricktorious\Ecommerce\User\OneTimePasswordManager;

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$tempPath = sys_get_temp_dir() . '/otp_tokens_' . bin2hex(random_bytes(4)) . '.json';
$manager = new OneTimePasswordManager($tempPath);

$issue = $manager->issue('waheed.rahman@ricktorious.com', 1);
assert_true(isset($issue['otp']) && strlen($issue['otp']) === 6, 'Expected a 6-digit OTP to be generated.');

$valid = $manager->verify('waheed.rahman@ricktorious.com', $issue['otp']);
assert_true($valid, 'OTP should verify immediately after issuing.');

$manager->consume('waheed.rahman@ricktorious.com');
assert_true(!$manager->verify('waheed.rahman@ricktorious.com', $issue['otp']), 'OTP should not verify after consumption.');

unlink($tempPath);

echo "OneTimePasswordManager tests passed\n";
