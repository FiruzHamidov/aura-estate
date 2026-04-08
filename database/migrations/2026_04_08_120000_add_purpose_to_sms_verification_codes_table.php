<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sms_verification_codes', function (Blueprint $table) {
            $table->string('purpose')->default('login')->after('phone');
        });

        Schema::table('sms_verification_codes', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->unique(['phone', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::table('sms_verification_codes', function (Blueprint $table) {
            $table->dropUnique(['phone', 'purpose']);
            $table->dropColumn('purpose');
            $table->unique('phone');
        });
    }
};
