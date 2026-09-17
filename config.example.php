<?php
// Copy to config.php on BigRock. Never commit the real API secret.
return [
    'livekit_url' => 'wss://livekit.meet.mciedu.com',
    'livekit_api_key' => 'replace-with-livekit-key',
    'livekit_api_secret' => 'replace-with-a-long-random-secret',
    'host_access_key' => 'replace-with-a-separate-host-control-key',
    // Generate with: php -r "echo password_hash('YOUR-4-TO-12-DIGIT-PIN', PASSWORD_DEFAULT), PHP_EOL;"
    'permanent_meeting_pin_hash' => 'replace-with-password-hash',
    'max_participants' => 1000,
    'token_ttl_seconds' => 21600,
];
