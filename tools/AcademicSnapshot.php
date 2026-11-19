<?php

use App\Support\Workspace;

final class AcademicSnapshot
{
    private const TABLES = ['users', 'buildings', 'categories', 'item_statuses', 'campus_locations', 'location_floors', 'items', 'item_photos', 'item_matches', 'claims', 'claim_photos', 'handovers', 'notifications', 'item_status_history', '_workspace_owner', '_write_operations', '_foundation_probe'];

    private static function synthetic(): bool
    {
        return in_array(Workspace::profile()['profile'], ['academic-dev', 'academic-test', ...Workspace::DEV_PROFILES], true);
    }

    /**
     * phase6-dev shares the owned 3307 server: before replacing its rows prove from live state (not only
     * process records) that no phase6-dev web worker is listening and no app-principal session is connected.
     */
    private static function assertPhase6Quiescent(PDO $pdo): void
    {
        if (! Workspace::isDev()) {
            return;
        }
        $record = Workspace::ROOT.'/state/'.Workspace::definition()['records'];
        foreach (is_file($record) ? json_decode(file_get_contents($record), true, 32, JSON_THROW_ON_ERROR) : [] as $name => $value) {
            if (str_starts_with($name, 'web-')) {
                throw new RuntimeException('Stop phase6-dev web processes before snapshot restoration.');
            }
        }
        foreach (Workspace::definition()['ports'] as $port) {
            $socket = @fsockopen('127.0.0.1', $port, $code, $message, 0.3);
            if ($socket) {
                fclose($socket);
                throw new RuntimeException('A listener is still active on a phase6-dev port; restore refused.');
            }
        }
        // An in-process test may hold this process's own already-resolved app connection; exclude only that one.
        $own = [0];
        $container = class_exists(\Illuminate\Container\Container::class) ? \Illuminate\Container\Container::getInstance() : null;
        if ($container && $container->resolved('db')) {
            foreach ($container->make('db')->getConnections() as $connection) {
                // Never (re)connect here: only an already open PDO identifies this process's own session.
                $raw = method_exists($connection, 'getRawPdo') ? $connection->getRawPdo() : null;
                if ($raw instanceof PDO) {
                    try {
                        $own[] = (int) $raw->query('SELECT CONNECTION_ID()')->fetchColumn();
                    } catch (Throwable) {/* A killed or closed session is not live. */
                    }
                }
            }
        }
        // Idle pooled sessions of earlier in-process test applications cannot interfere; any app session that is
        // executing, holds an InnoDB transaction, or holds the workflow advisory lock blocks restoration.
        $marks = implode(',', array_fill(0, count($own), '?'));
        $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.PROCESSLIST p WHERE p.USER=? AND p.ID<>CONNECTION_ID() AND p.ID NOT IN ($marks) AND (p.COMMAND<>'Sleep' OR EXISTS (SELECT 1 FROM information_schema.INNODB_TRX t WHERE t.trx_mysql_thread_id=p.ID))");
        $s->execute([Workspace::profile()['username'], ...$own]);
        if ((int) $s->fetchColumn() !== 0) {
            throw new RuntimeException('phase6-dev application sessions are active; restore refused.');
        }
        $s = $pdo->prepare('SELECT IS_USED_LOCK(?)');
        $s->execute(['refind-matching-'.Workspace::profile()['database']]);
        if ($s->fetchColumn() !== null) {
            throw new RuntimeException('The phase6-dev workflow lock is held; restore refused.');
        }
    }

    private static function tables(): array
    {
        return Workspace::profile()['profile'] === 'academic-test' ? self::TABLES : array_values(array_diff(self::TABLES, ['_foundation_probe']));
    }

    public static function admin(): PDO
    {
        if (PHP_SAPI !== 'cli' || ! self::synthetic() || Workspace::profile()['database'] !== Workspace::PROFILES[Workspace::profile()['profile']]['database']) {
            throw new RuntimeException('Restoration is restricted to synthetic B profiles.');
        }
        $p = Workspace::profile();
        $secret = json_decode(file_get_contents(Workspace::ROOT.'/state/owner-credentials.json'), true, 16, JSON_THROW_ON_ERROR);
        $pdo = new PDO('mysql:host=127.0.0.1;port=3307;dbname='.$p['database'].';charset=utf8mb4', 'root', $secret['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
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
        if (PHP_SAPI !== 'cli' || ! self::synthetic() || (int) $row['port'] !== 3307 || $row['db'] !== Workspace::profile()['database'] || Workspace::norm($row['datadir']) !== Workspace::norm(Workspace::ROOT.'/state/mariadb-data')) {
            throw new RuntimeException('Foreign restoration connection refused.');
        }
        if ($pdo->query('SELECT workspace_id FROM _workspace_owner')->fetchColumn() !== Workspace::profile()['workspace_id']) {
            throw new RuntimeException('Foreign restoration owner.');
        }
    }

    public static function capture(PDO $pdo): array
    {
        self::assertTarget($pdo);
        $out = ['version' => 1, 'profile' => Workspace::profile()['profile'], 'database' => Workspace::profile()['database'], 'workspace_id' => Workspace::profile()['workspace_id'], 'tables' => [], 'media' => self::media()];
        foreach (self::tables() as $table) {
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
        foreach (json_decode(file_get_contents(Workspace::ROOT.'/state/academic-processes.json'), true, 32, JSON_THROW_ON_ERROR) as $name => $record) {
            if (str_starts_with($name, 'web-')) {
                throw new RuntimeException('Stop all owned web processes before snapshot restoration.');
            }
        }
        self::assertTarget($pdo); // Validate the actual supplied connection, not a different handle.
        self::assertPhase6Quiescent($pdo);
        if (! preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new RuntimeException('Invalid snapshot ID.');
        }
        $file = Workspace::storage().'/snapshots/'.$id.'.json';
        Workspace::assertPath($file);
        $e = json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        if (hash('sha256', $e['payload']) !== $e['sha256']) {
            throw new RuntimeException('Snapshot integrity failed.');
        }$data = json_decode($e['payload'], true, 64, JSON_THROW_ON_ERROR);
        if ($data['profile'] !== Workspace::profile()['profile'] || $data['database'] !== Workspace::profile()['database'] || $data['workspace_id'] !== Workspace::profile()['workspace_id'] || array_keys($data['tables']) !== self::tables()) {
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
            foreach (array_reverse(self::tables()) as $table) {
                $pdo->exec('DELETE FROM `'.$table.'`');
            }
            foreach ($data['tables'] as $table => $snapshot) {
                foreach ($snapshot['rows'] as $row) {
                    $columns = array_keys($row);
                    foreach ($columns as $column) {
                        if (! preg_match('/^[a-z_][a-z0-9_]*$/D', $column)) { // e.g. _write_operations.manifest_sha256
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
