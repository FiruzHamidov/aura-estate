<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ResidentialSchema
{
    public static function create(): void
    {
        if (! app()->environment('testing') || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            throw new \LogicException('Residential tests require isolated SQLite :memory:.');
        }
        Schema::dropAllTables();
        self::createTables();
    }

    public static function assertIsolatedMysql(string $root): void
    {
        $root = realpath($root);
        if (! app()->environment('testing') || config('database.default') !== 'residential_mysql'
            || ! $root || ! preg_match('#^/(private/)?tmp/aura-residential-mysql\.[A-Za-z0-9]+$#', $root)
            || (fileperms($root) & 0077) !== 0) {
            throw new \LogicException('MySQL fixtures require a private, dedicated testing sandbox.');
        }
        $server = DB::selectOne('SELECT DATABASE() AS db, @@datadir AS datadir, @@skip_networking AS isolated');
        if (! preg_match('/^aura_residential_qa_[a-f0-9]{8}$/', $server->db)
            || realpath($server->datadir) !== $root.'/data' || (int) $server->isolated !== 1) {
            throw new \LogicException('Refusing MySQL outside the dedicated socket-only sandbox.');
        }
    }

    public static function createMysql(string $root): void
    {
        self::assertIsolatedMysql($root);
        if ((int) DB::selectOne('SELECT COUNT(*) AS total FROM information_schema.tables WHERE table_schema = DATABASE()')->total !== 0) {
            throw new \LogicException('MySQL fixture database must be new and empty; existing tables are never dropped.');
        }
        self::createTables();
    }

    private static function createTables(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->unique();
            $table->foreignId('role_id');
            $table->foreignId('branch_id')->nullable();
            $table->string('status')->default('active');
            $table->string('auth_method')->default('password');
            $table->string('photo')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('deletion_requested_at')->nullable();
            $table->timestamps();
        });
        foreach ([
            '2025_06_23_004353_create_locations_table.php',
            '2025_09_05_111317_create_construction_stages_table.php', '2025_09_05_111318_create_developers_table.php',
            '2025_09_05_111319_create_features_table.php', '2025_09_05_111320_create_materials_table.php',
            '2025_09_05_111321_create_new_buildings_table.php', '2025_09_05_111322_create_new_building_blocks_table.php',
            '2025_09_05_111323_create_developer_units_table.php', '2025_09_05_111324_create_developer_unit_photos_table.php',
            '2025_09_05_111325_create_feature_new_building_table.php', '2025_09_05_111326_create_new_building_photos_table.php',
            '2025_12_03_171331_add_description_to_developers_table.php',
            '2025_12_03_180553_add_district_to_new_buildings_table.php', '2025_12_11_151438_add_ceiling_height_to_new_buildings_table.php',
            '2025_12_11_112422_add_moderation_status_and_window_view_to_developer_units_table.php',
            '2026_03_07_120100_create_crm_audit_logs_table.php',
            '2026_08_28_130000_expand_residential_complex_inventory.php',
            '2026_08_28_140000_add_residential_media_metadata.php',
            '2026_08_28_141000_allow_unknown_unit_bathrooms.php',
            '2026_08_28_175000_allow_unknown_building_characteristics.php',
            '2026_08_28_200000_add_residential_media_variants.php',
            '2026_08_28_210000_create_residential_media_migrations.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
    }
}
