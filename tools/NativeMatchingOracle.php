<?php

use App\Support\Workspace;

/** Test-only oracle: selected functions from the pinned native Git blob, never its bootstrap/config. */
final class NativeMatchingOracle
{
    private static bool $loaded = false;

    private static ?PDO $pdo = null;

    public static string $repositoryHash;

    public static string $reportsHash;

    public static function boot(): void
    {
        if (self::$loaded) {
            return;
        }
        if (PHP_SAPI !== 'cli') {
            throw new RuntimeException('CLI oracle only.');
        }
        Workspace::profile();
        $native = Workspace::ROOT.'/native-reference';
        $git = static function (array $args) use ($native): string {
            $process = proc_open(['git', '-C', $native, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (! is_resource($process)) {
                throw new RuntimeException('Cannot read pinned native reference.');
            }
            $text = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            if (proc_close($process) !== 0) {
                throw new RuntimeException('Native Git reference read failed.');
            }

            return $text;
        };
        if (trim($git(['rev-parse', 'HEAD'])) !== '47eea8648d2d47faa895d855fe6da53e6b1a3087') {
            throw new RuntimeException('Native reference checkpoint changed.');
        }
        $source = $git(['show', 'HEAD:app/repository.php']);
        self::$repositoryHash = hash('sha256', $source);
        self::$reportsHash = hash('sha256', $git(['show', 'HEAD:app/reports.php']));
        $wanted = ['ui_status_name', 'notification', 'lock_items', 'confirmed_match', 'history_event', 'status_change', 'has_suggestion', 'matching_words', 'match_score', 'suggestion_score', 'refresh_suggestions'];
        $tokens = token_get_all($source);
        $found = [];
        for ($i = 0; $i < count($tokens); $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }
            $j = $i + 1;
            while (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }
            if (! is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING || ! in_array($tokens[$j][1], $wanted, true)) {
                continue;
            }
            $name = $tokens[$j][1];
            $text = '';
            $depth = 0;
            $body = false;
            for (; $i < count($tokens); $i++) {
                $token = $tokens[$i];
                $text .= is_array($token) ? $token[1] : $token;
                if ($token === '{') {
                    $depth++;
                    $body = true;
                } elseif ($token === '}') {
                    $depth--;
                    if ($body && $depth === 0) {
                        break;
                    }
                }
            }
            $found[$name] = $text;
        }
        if (array_diff($wanted, array_keys($found)) || count($found) !== count($wanted)) {
            throw new RuntimeException('Incomplete native oracle extraction.');
        }
        preg_match('/^const ACTIVE_ITEM_CODES = (.+);$/m', $source, $active);
        preg_match('/^const MATCH_THRESHOLD = (\d+);$/m', $source, $threshold);
        if (! $active || (int) ($threshold[1] ?? 0) !== 55) {
            throw new RuntimeException('Native constants changed.');
        }
        $adapters = 'function sql(string $q,array $v=[]): \PDOStatement { return \NativeMatchingOracle::sql($q,$v); } function rows(string $q,array $v=[]): array { return sql($q,$v)->fetchAll(\PDO::FETCH_ASSOC); } function row(string $q,array $v=[]): ?array { return sql($q,$v)->fetch(\PDO::FETCH_ASSOC) ?: null; } function fail(string $m,int $s=422,string $c="validation_error"): never { throw new \RuntimeException($m,$s); }';
        eval('declare(strict_types=1); namespace ReFindNativeOracle; use \DateTimeImmutable; use \RuntimeException; const ACTIVE_ITEM_CODES='.$active[1].'; const MATCH_THRESHOLD=55;'.implode("\n", $found).$adapters);
        self::$loaded = true;
    }

    public static function database(PDO $pdo): void
    {
        $server = $pdo->query('SELECT @@port AS port,@@datadir AS datadir,DATABASE() AS db')->fetch(PDO::FETCH_ASSOC);
        if (Workspace::profile()['profile'] !== 'academic-test' || (int) $server['port'] !== 3307 || $server['db'] !== 'refind_laravel_b_test' || Workspace::norm($server['datadir']) !== Workspace::norm(Workspace::ROOT.'/state/mariadb-data') || $pdo->query('SELECT workspace_id FROM _workspace_owner')->fetchColumn() !== Workspace::profile()['workspace_id']) {
            throw new RuntimeException('Oracle SQL must target synthetic B test only.');
        }
        self::$pdo = $pdo;
    }

    public static function sql(string $query, array $values): PDOStatement
    {
        if (! self::$pdo || ! self::$pdo->inTransaction()) {
            throw new RuntimeException('Native oracle SQL requires its guarded B-test transaction.');
        }
        $statement = self::$pdo->prepare($query);
        $statement->execute($values);

        return $statement;
    }

    public static function saved(array $item, array &$locked, int $actor, bool $created): void
    {
        \ReFindNativeOracle\history_event($item, $actor, $created ? 'Report submitted.' : 'Report details updated by the reporter.');
        if ($created) {
            \ReFindNativeOracle\notification((int) $item['reporter_user_id'], 'REPORT_SUBMITTED', 'Report submitted', 'Your '.strtolower($item['item_type']).' report has been saved.', (int) $item['item_id']);
        }
        \ReFindNativeOracle\refresh_suggestions($item, $locked, $actor);
    }

    public static function deleted(array $item, array &$locked, int $actor): void
    {
        \ReFindNativeOracle\notification($actor, 'REPORT_DELETED', 'Report deleted', 'Your report "'.mb_substr($item['title'], 0, 120).'" was deleted.');
        foreach ($locked as &$candidate) {
            if ($candidate['status_code'] === 'POTENTIAL_MATCH' && ! \ReFindNativeOracle\confirmed_match((int) $candidate['item_id']) && ! \ReFindNativeOracle\has_suggestion((int) $candidate['item_id'])) {
                \ReFindNativeOracle\status_change($candidate, 'OPEN', $actor, 'No current matching suggestions.', false);
            }
        }
        unset($candidate);
    }
}
