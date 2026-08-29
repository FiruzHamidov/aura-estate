<?php

namespace App\Support;

use Illuminate\Http\Request;

final class ResidentialDiagnostics
{
    public static function applies(Request $request): bool
    {
        return $request->is('api/lead-requests', 'api/chat', 'api/chat/*',
            'api/new-buildings', 'api/new-buildings/*', 'api/admin/new-buildings',
            'api/admin/new-buildings/*', 'api/payment-programs', 'api/payment-programs/*');
    }
}
