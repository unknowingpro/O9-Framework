<?php
declare(strict_types=1);

return [
    // Active identity-verification provider. 'off' disables verification
    // entirely (IdentityVerificationService::start() throws). 'manual' is
    // the only provider that ships in core; register more via
    // IdentityProviderFactory::extend().
    'mode' => env('IDENTITY_MODE', 'manual'),
];
