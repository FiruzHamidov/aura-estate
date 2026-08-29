<?php

namespace Tests\Support;

use App\Models\BuildingFloorPlan;
use App\Models\DeveloperUnit;
use App\Models\NewBuilding;
use App\Models\UnitLayout;
use App\Services\Crm\PublicLeadIntake;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PDO;
use Symfony\Component\Process\Process;

/** Disposable QA only: never restores into the source database or existing storage. */
final class ResidentialMysqlRecovery
{
    public static function run(string $root, string $run, string $database, array $leadInput, callable $check): array
    {
        if (! app()->environment('testing') || ! preg_match('/^aura_residential_qa_[a-f0-9]{8}$/', $database)
            || realpath($run) !== $root.'/'.$database || ! preg_match('#^/(private/)?tmp/aura-residential-mysql\.[A-Za-z0-9]+$#', $root)
            || config('database.default') !== 'residential_mysql' || DB::selectOne('SELECT DATABASE() AS name')->name !== $database) {
            throw new \LogicException('Recovery rehearsal requires the dedicated generated MySQL fixture.');
        }
        $server = DB::selectOne('SELECT @@datadir AS datadir, @@skip_networking AS networking');
        if (realpath($server->datadir) !== $root.'/data' || (int) $server->networking !== 1) {
            throw new \LogicException('Refusing a shared or network-accessible server.');
        }
        $previousUmask = umask(0077);
        try {
            return self::recover($root, $run, $database, $leadInput, $check);
        } finally {
            umask($previousUmask);
        }
    }

    private static function recover(string $root, string $run, string $database, array $leadInput, callable $check): array
    {
        $building = NewBuilding::findOrFail($leadInput['context']['building_id']);
        $unit = DeveloperUnit::findOrFail($leadInput['context']['unit_id']);
        $source = DB::connection()->getPdo();
        $image = imagecreatetruecolor(800, 600);
        imagefill($image, 0, 0, imagecolorallocate($image, 240, 245, 255));
        imagestring($image, 5, 30, 30, 'SYNTHETIC MYSQL RECOVERY TEST', imagecolorallocate($image, 0, 40, 130));
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        $path = 'new-buildings/'.$building->id.'/qa-recovery.png';
        Storage::disk('public')->put($path, $bytes);
        Storage::disk('public')->put('qa-unrelated.txt', 'Synthetic public asset retained by full backup');
        $building->photos()->create(['path' => $path]);
        $unit->photos()->create(['path' => $path]);
        UnitLayout::create(['new_building_id' => $building->id, 'code' => 'QA recovery', 'image_path' => $path]);
        BuildingFloorPlan::create(['new_building_id' => $building->id, 'block_id' => $unit->block_id, 'entrance_id' => $unit->entrance_id, 'floor_from' => 1, 'floor_to' => 1, 'image_path' => $path]);
        $check(Artisan::call('residential:media-migrate', ['--building' => $building->id, '--apply' => true, '--confirm' => true]) === 0, 'MySQL migration copies four media types to private storage');
        $journals = DB::table('residential_media_migrations')->orderBy('id')->get();
        $check($journals->count() === 4, 'four MySQL recovery journals exist');
        $check(Artisan::call('residential:media-migrate', ['--quarantine' => $journals->first()->id, '--confirm' => true]) === 0, 'quarantine removes only the verified shared public original');
        $check(! Storage::disk('public')->exists($path), 'private media source is not publicly present at backup time');

        // The fixture has no other writers. A consistent SQL dump alone cannot make media atomic on a live service.
        $tablesBefore = self::tables($source);
        $filesBefore = self::files($run);
        $backedUpLeadId = (int) DB::table('lead_intakes')->sole()->lead_id;
        $dump = $run.'/database.sql';
        $archive = $run.'/media.tar';
        if (file_exists($dump) || file_exists($archive)) {
            throw new \LogicException('Refusing to overwrite backup artifacts.');
        }
        $options = ['--no-defaults', '--protocol=SOCKET', '--socket='.$root.'/mysql.sock', '--user=root', '--password='];
        $execute = static function (array $arguments, $input = null) use ($run): void {
            $process = new Process($arguments, $run, ['MYSQL_TEST_LOGIN_FILE' => $run.'/no-login.cnf', 'MYSQL_PWD' => false]);
            $process->setTimeout(60);
            if ($input !== null) {
                $process->setInput($input);
            }
            $process->mustRun();
        };
        $execute(['mysqldump', ...$options, '--single-transaction', '--skip-lock-tables', '--routines', '--events', '--triggers', '--hex-blob', '--no-tablespaces', '--set-gtid-purged=OFF', '--skip-add-drop-table', '--skip-add-locks', '--skip-comments', '--result-file='.$dump, $database]);
        $check(filesize($dump) > 0 && ! preg_match('/^(?:CREATE DATABASE|USE\s)/mi', file_get_contents($dump)), 'SQL dump contains no database-switching statements');
        $execute(['tar', '-cf', $archive, '-C', $run, 'public', 'private']);
        $checksums = ['database.sql' => hash_file('sha256', $dump), 'media.tar' => hash_file('sha256', $archive)];
        file_put_contents($run.'/backup-manifest.json', json_encode(['source_database' => $database, 'checksums' => $checksums, 'tables' => $tablesBefore, 'files' => $filesBefore], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $check(self::tables($source) === $tablesBefore && self::files($run) === $filesBefore, 'backup reads do not modify fixture tables or media');

        // Explicitly demonstrate the recovery-point boundary; never overwrite this later accepted request.
        $later = app(PublicLeadIntake::class)->accept([...$leadInput, 'idempotency_key' => 'qa-after-backup-only', 'name' => 'QA accepted after backup']);
        $sourceAfter = self::tables($source);
        $check(DB::table('leads')->count() === 2 && DB::table('lead_intakes')->count() === 2, 'source retains a request accepted after the backup');

        $restoredDatabase = $database.'_restore_'.bin2hex(random_bytes(4));
        $restoredRoot = $run.'/restored';
        if (! mkdir($restoredRoot, 0700)) {
            throw new \LogicException('Recovery directory must be new.');
        }
        $source->exec('CREATE DATABASE `'.$restoredDatabase.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $check(hash_file('sha256', $dump) === $checksums['database.sql'] && hash_file('sha256', $archive) === $checksums['media.tar'], 'backup checksums verified immediately before recovery');
        $input = fopen($dump, 'rb');
        try {
            $execute(['mysql', ...$options, $restoredDatabase], $input);
        } finally {
            fclose($input);
        }
        // Archive is generated above from link-free QA paths, not an arbitrary uploaded tarball.
        $execute(['tar', '-xkf', $archive, '-C', $restoredRoot]);
        $restored = new PDO('mysql:unix_socket='.$root.'/mysql.sock;dbname='.$restoredDatabase, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $check(self::tables($restored) === $tablesBefore, 'restored MySQL table definitions, row counts and exact data hashes match the backup');
        $check(self::files($restoredRoot) === $filesBefore, 'all public/private restored file paths and SHA-256 hashes match');
        $check(! file_exists($restoredRoot.'/public/'.$path), 'restore does not republish a quarantined private image');
        $check((int) $restored->query('SELECT COUNT(*) FROM leads')->fetchColumn() === 1 && (int) $restored->query('SELECT COUNT(*) FROM lead_intakes')->fetchColumn() === 1, 'restored database honestly reflects the backup point, not later requests');
        $check(self::tables($source) === $sourceAfter && self::files($run) === $filesBefore, 'recovery leaves live fixture and later intake unchanged');

        $sourceConfig = config('database.connections.residential_mysql');
        config(['database.connections.residential_recovered' => [...$sourceConfig, 'database' => $restoredDatabase], 'database.default' => 'residential_recovered', 'filesystems.disks.public.root' => $restoredRoot.'/public', 'filesystems.disks.residential.root' => $restoredRoot.'/private']);
        Storage::forgetDisk(['public', 'residential']);
        try {
            $repeat = app(PublicLeadIntake::class)->accept($leadInput);
            $check(DB::table('leads')->count() === 1 && DB::table('lead_intakes')->count() === 1 && (int) $repeat['lead_id'] === $backedUpLeadId, 'application retries the backed-up receipt without duplicating or inventing later requests');
            foreach ($journals as $journal) {
                $check(hash_file('sha256', Storage::disk('residential')->path($journal->backup_path)) === $journal->source_sha256, 'restored private original is byte-identical for '.$journal->entity_type);
                $values = json_decode($journal->new_values, true, 512, JSON_THROW_ON_ERROR);
                $variants = is_string($values['variants']) ? json_decode($values['variants'], true, 512, JSON_THROW_ON_ERROR) : $values['variants'];
                foreach ([$values['path'] ?? $values['image_path'], $values['original_path'], ...array_column($variants, 'path')] as $mediaPath) {
                    $check(getimagesize(Storage::disk('residential')->path($mediaPath))['mime'] === 'image/webp', 'restored application resolves WebP '.$mediaPath);
                }
            }
        } finally {
            DB::purge('residential_recovered');
            config(['database.default' => 'residential_mysql', 'filesystems.disks.public.root' => $run.'/public', 'filesystems.disks.residential.root' => $run.'/private']);
            Storage::forgetDisk(['public', 'residential']);
        }
        $check(self::tables($source) === $sourceAfter, 'application recovery checks never mutate the source database');

        return ['source_database' => $database, 'restored_database' => $restoredDatabase, 'restored_root' => $restoredRoot, 'checksums' => $checksums, 'tables' => count($tablesBefore), 'files' => count($filesBefore), 'later_request_retained_in_source' => $later['lead_id'], 'promotion_performed' => false];
    }

    private static function tables(PDO $pdo): array
    {
        $tables = $pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_COLUMN);
        sort($tables);
        $result = [];
        foreach ($tables as $table) {
            if (! preg_match('/^[a-z0-9_]+$/', $table)) {
                throw new \LogicException('Unexpected QA table name.');
            }
            $rows = $pdo->query('SELECT * FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
            $encoded = array_map(static fn ($row) => json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $rows);
            sort($encoded);
            $ddl = $pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
            // MySQL dump/restore may spell out the charset implied by a utf8mb4 collation.
            // Compare column metadata as well; never ignore actual charset/type/precision differences.
            $ddl = preg_replace('/ CHARACTER SET utf8mb4(?= COLLATE utf8mb4_)/', '', $ddl);
            $columns = $pdo->prepare('SELECT COLUMN_NAME, ORDINAL_POSITION, COLUMN_DEFAULT, IS_NULLABLE, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, CHARACTER_OCTET_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE, DATETIME_PRECISION, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_TYPE, COLUMN_KEY, EXTRA, COLUMN_COMMENT, GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
            $columns->execute([$table]);
            $schema = [$ddl, $columns->fetchAll(PDO::FETCH_ASSOC)];
            $result[$table] = ['rows' => count($rows), 'sha256' => hash('sha256', json_encode($encoded, JSON_THROW_ON_ERROR)), 'schema_sha256' => hash('sha256', json_encode($schema, JSON_THROW_ON_ERROR))];
        }

        return $result;
    }

    private static function files(string $root): array
    {
        $result = [];
        foreach (['public', 'private'] as $disk) {
            $path = $root.'/'.$disk;
            if (! is_dir($path) || is_link($path)) {
                throw new \LogicException('Require real QA media directories.');
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $entry) {
                if ($entry->isLink() || ! $entry->isFile()) {
                    throw new \LogicException('Refusing non-regular media in QA backup.');
                }
                $relative = substr($entry->getPathname(), strlen($root) + 1);
                $result[$relative] = hash_file('sha256', $entry->getPathname());
            }
        }
        ksort($result);

        return $result;
    }
}
