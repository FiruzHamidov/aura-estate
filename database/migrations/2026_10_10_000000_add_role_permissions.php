<?php

use App\Services\Rbac\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->json('permissions')->nullable();
            $table->unsignedInteger('permissions_version')->default(1);
        });

        $catalog = new PermissionCatalog;
        DB::table('roles')->orderBy('id')->chunkById(100, function ($roles) use ($catalog) {
            foreach ($roles as $role) {
                DB::table('roles')->where('id', $role->id)->update([
                    'permissions' => json_encode($catalog->defaults($role->slug), JSON_THROW_ON_ERROR),
                ]);
            }
        });

        Schema::create('role_permission_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('role_id')->index();
            $table->unsignedBigInteger('actor_id')->nullable()->index();
            $table->string('role_name');
            $table->json('before');
            $table->json('after');
            $table->unsignedInteger('version');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permission_audits');
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['permissions', 'permissions_version']);
        });
    }
};
