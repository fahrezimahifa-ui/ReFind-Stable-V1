<?php

use App\Support\Workspace;

final class AcademicSnapshot
{
    private const TABLES = ['users', 'buildings', 'categories', 'item_statuses', 'campus_locations', 'location_floors', 'items', 'item_photos', 'item_matches', 'claims', 'claim_photos', 'handovers', 'notifications', 'item_status_history', '_workspace_owner', '_write_operations', '_foundation_probe'];

    public static function admin(): PDO
    {
        if (PHP_SAPI !== 'cli' || Workspace::profile()['profile'] !== 'academic-test' || Workspace::profile()['database'] !== 'refind_laravel_b_test') {
            throw new RuntimeException('Restoration is restricted to synthetic B test.');
        }
        $p = Workspace::profile();
        $secret = json_decode(file_get_contents(Workspace::ROOT.'/state/owner-credentials.json'), true, 16, JSON_THROW_ON_ERROR);
        $pdo = new PDO('mysql:host=127.0.0.1;port=3307;dbname=refind_laravel_b_test;charset=utf8mb4', 'root', $secret['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
        $row = $pdo->query('SELECT @@port AS port,@@datadir AS datadir')->fetch(PDO::FETCH_ASSOC);
        if ((int) $row['port'] !== 3307 || Workspace::norm($row['datadir']) !== Workspace::norm(Workspace::ROOT.'/state/mariadb-data') || $pdo->query('SELECT workspace_id FROM _workspace_owner')->fetchColumn() !== $p['workspace_id']) {
            throw new RuntimeException('Wrong restore server or schema owner.');
        }
        $pdo->exec("SET time_zone='+00:00'");

        return $pdo;
    }

    private static function media(): array
    {
        $out = [];
        foreach (['items', 'evidence', 'claims', 'avatars'] as $collection) {
            foreach (glob(Workspace::media().'/'.$collection.'/*') as $file) {
                Workspace::assertPath($file);
                if (! is_file($file) || ! preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/D', basename($file))) {
                    throw new RuntimeException('Unexpected authoritative-media entry.');
                }
                $out[$collection.'/'.basename($file)] = base64_encode(file_get_contents($file));
            }
        }ksort($out);

        return $out;
    }

    private static function assertTarget(PDO $pdo): void
    {
        $row = $pdo->query('SELECT @@port AS port,@@datadir AS datadir,DATABASE() AS db')->fetch(PDO::FETCH_ASSOC);
        if (PHP_SAPI !== 'cli' || Workspace::profile()['profile'] !== 'academic-test' || (int) $row['port'] !== 3307 || $row['db'] !== 'refind_laravel_b_test' || Workspace::norm($row['datadir']) !== Workspace::norm(Workspace::ROOT.'/state/mariadb-data')) {
            throw new RuntimeException('Foreign restoration connection refused.');
        }
        if ($pdo->query('SELECT workspace_id FROM _workspace_owner')->fetchColumn() !== Workspace::profile()['workspace_id']) {
            throw new RuntimeException('Foreign restoration owner.');
        }
    }

    public static function capture(PDO $pdo): array
    {
        self::assertTarget($pdo);
        $out = ['version' => 1, 'profile' => 'academic-test', 'database' => 'refind_laravel_b_test', 'workspace_id' => Workspace::profile()['workspace_id'], 'tables' => [], 'media' => self::media()];
        foreach (self::TABLES as $table) {
            $out['tables'][$table] = ['ddl' => $pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1], 'rows' => $pdo->query('SELECT * FROM `'.$table.'` ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC)];
        }

        return $out;
    }

    public static function save(PDO $pdo): string
    {
        $id = bin2hex(random_bytes(16));
        $file = Workspace::storage().'/snapshots/'.$id.'.json';
        Workspace::assertPath($file);
        $data = json_encode(self::capture($pdo), JSON_THROW_ON_ERROR);
        $envelope = json_encode(['sha256' => hash('sha256', $data), 'payload' => $data], JSON_THROW_ON_ERROR);
        $f = fopen($file, 'xb');
        if (! $f) {
            throw new RuntimeException('Snapshot exists.');
        }try {
            if (fwrite($f, $envelope) !== strlen($envelope) || ! fflush($f) || ! fsync($f)) {
                throw new RuntimeException('Snapshot flush failed.');
            }
        } finally {
            fclose($f);
        }

        return $id;
    }

    public static function restore(PDO $pdo, string $id): void
    {
        self::assertTarget($pdo); // Validate the actual supplied connection, not a different handle.
        if (! preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new RuntimeException('Invalid snapshot ID.');
        }
        $file = Workspace::storage().'/snapshots/'.$id.'.json';
        Workspace::assertPath($file);
        $e = json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        if (hash('sha256', $e['payload']) !== $e['sha256']) {
            throw new RuntimeException('Snapshot integrity failed.');
        }$data = json_decode($e['payload'], true, 64, JSON_THROW_ON_ERROR);
        if ($data['profile'] !== 'academic-test' || $data['database'] !== 'refind_laravel_b_test' || $data['workspace_id'] !== Workspace::profile()['workspace_id'] || array_keys($data['tables']) !== self::TABLES) {
            throw new RuntimeException('Foreign restore refused.');
        }
        foreach ($data['tables'] as $table => $snapshot) {
            if (preg_replace('/ AUTO_INCREMENT=\d+/', '', $pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1]) !== preg_replace('/ AUTO_INCREMENT=\d+/', '', $snapshot['ddl'])) {
                throw new RuntimeException('Schema drift; restore refused.');
            }
        }
        foreach ($data['media'] as $relative => $bytes) {
            if (! preg_match('~^(items|evidence|claims|avatars)/[a-f0-9]{32}\.(jpg|png|webp)$~D', $relative) || base64_decode($bytes, true) === false) {
                throw new RuntimeException('Invalid snapshot media.');
            }
        }
        $receipt = Workspace::storage().'/snapshots/'.$id.'.restore.json';
        file_put_contents($receipt, json_encode(['state' => 'database_started', 'snapshot' => $id]));
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            $pdo->beginTransaction();
            foreach (array_reverse(self::TABLES) as $table) {
                $pdo->exec('DELETE FROM `'.$table.'`');
            }
            foreach ($data['tables'] as $table => $snapshot) {
                foreach ($snapshot['rows'] as $row) {
                    $columns = array_keys($row);
                    foreach ($columns as $column) {
                        if (! preg_match('/^[a-z_]+$/D', $column)) {
                            throw new RuntimeException('Unexpected column.');
                        }
                    }$s = $pdo->prepare('INSERT INTO `'.$table.'` (`'.implode('`,`', $columns).'`) VALUES('.implode(',', array_fill(0, count($columns), '?')).')');
                    $s->execute(array_values($row));
                }
            }
            $pdo->commit();
        } catch (Throwable$error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }throw $error;
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
        // Offline B-test maintenance: reset only the captured table counters after data commit.
        // These ALTERs are intentionally outside the data transaction (DDL implicitly commits).
        foreach ($data['tables'] as $table => $snapshot) {
            if (stripos($snapshot['ddl'], 'auto_increment') !== false) {
                $next = preg_match('/ AUTO_INCREMENT=(\d+)/', $snapshot['ddl'], $counter) ? (int) $counter[1] : 1;
                $pdo->exec('ALTER TABLE `'.$table.'` AUTO_INCREMENT='.$next);
            }
        }
        file_put_contents($receipt, json_encode(['state' => 'media_started', 'snapshot' => $id]));
        foreach (self::media() as $relative => $bytes) {
            if (! array_key_exists($relative, $data['media'])) {
                $path = Workspace::media().'/'.$relative;
                Workspace::assertPath($path);
                if (! unlink($path)) {
                    throw new RuntimeException('Restore cleanup failed.');
                }
            }
        }
        foreach ($data['media'] as $relative => $bytes) {
            $path = Workspace::media().'/'.$relative;
            Workspace::assertPath($path);
            $tmp = $path.'.restore-'.bin2hex(random_bytes(8));
            if (file_put_contents($tmp, base64_decode($bytes)) === false || ! rename($tmp, $path)) {
                throw new RuntimeException('Media restore failed.');
            }
        }
        if (self::capture($pdo) !== $data) {
            throw new RuntimeException('Restoration verification failed.');
        }
        file_put_contents($receipt, json_encode(['state' => 'verified', 'snapshot' => $id]));
    }
}
