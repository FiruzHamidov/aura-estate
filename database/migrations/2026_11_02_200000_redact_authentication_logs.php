<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('api_request_logs')) {
            DB::table('api_request_logs')->where(function ($query) {
                $query->whereIn('path', ['api/login', 'api/register', 'api/reviews/request-code'])
                    ->orWhere('path', 'like', 'api/sms/%')->orWhere('path', 'like', 'api/password/%')
                    ->orWhere('path', 'like', 'api/telegram/%')->orWhere('path', 'like', 'api/agents/%/reviews');
            })->update(['request_query' => null, 'request_body' => null, 'error_message' => null]);
        }

        if (Schema::hasTable('osonsms_log')) {
            DB::table('osonsms_log')->where(function ($query) {
                $query->where('message', 'like', 'Ваш код авторизации:%')
                    ->orWhere('message', 'like', 'Ваш код для сброса пароля:%');
            })->update(['message' => '[redacted]', 'server_response' => null]);
        }
    }

    public function down(): void
    {
        // Removed credentials must not be restored.
    }
};
