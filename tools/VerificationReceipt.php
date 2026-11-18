<?php

/** Standalone verifier protocol: no Laravel/container/database dependencies. */
final class VerificationReceipt
{
    private static ?array $run = null;

    public static function begin(): void
    {
        $names = ['RUN_ID', 'INVOCATION_ID', 'SUITE', 'PROFILE', 'CHECKPOINT', 'RECEIPT'];
        $values = [];
        foreach ($names as $name) {
            $value = getenv('REFIND_VERIFY_'.$name);
            if ($value !== false) {
                $values[$name] = $value;
            }
        }
        if ($values === []) {
            return; // Manual execution is permitted; the runner still requires a receipt.
        }
        if (count($values) !== count($names) || ! preg_match('/^[a-f0-9]{32}$/D', $values['RUN_ID']) || ! preg_match('/^[a-f0-9]{32}$/D', $values['INVOCATION_ID']) || ! preg_match('/^[a-z0-9-]+$/D', $values['SUITE']) || ! in_array($values['PROFILE'], ['academic-test', 'academic-dev'], true) || $values['PROFILE'] !== getenv('REFIND_PROFILE') || ! in_array($values['CHECKPOINT'], ['crud', 'matching'], true)) {
            throw new RuntimeException('Invalid verification receipt identity.');
        }
        $parent = realpath(dirname($values['RECEIPT']));
        $root = realpath('C:/ReFind-Laravel/evidence/phase5');
        $norm = static fn ($s) => strtolower(str_replace('\\', '/', $s));
        if (! $parent || ! $root || ! str_starts_with($norm($parent).'/', $norm($root).'/') || is_file($values['RECEIPT'])) {
            throw new RuntimeException('Receipt destination must be fresh and isolated.');
        }
        self::$run = ['schema_version' => 1, 'phase' => 5, 'checkpoint' => $values['CHECKPOINT'], 'run_id' => $values['RUN_ID'], 'invocation_id' => $values['INVOCATION_ID'], 'suite' => $values['SUITE'], 'profile' => $values['PROFILE'], 'receipt_path' => $norm($values['RECEIPT']), 'started_at' => microtime(true)];
    }

    public static function complete(int $checks, array $results = []): void
    {
        if (self::$run === null) {
            return;
        }
        if ($checks < 1) {
            throw new RuntimeException('A complete receipt requires positive verified checks.');
        }
        $record = self::$run + ['passed' => true, 'completed' => true, 'checks' => $checks, 'completed_at' => microtime(true), 'results' => $results];
        $path = getenv('REFIND_VERIFY_RECEIPT');
        $tmp = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        $handle = fopen($tmp, 'x');
        if (! $handle) {
            throw new RuntimeException('Cannot create verification receipt.');
        }
        try {
            $body = json_encode($record, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            if (fwrite($handle, $body) !== strlen($body) || ! fflush($handle) || ! fsync($handle)) {
                throw new RuntimeException('Cannot finish verification receipt.');
            }
        } finally {
            fclose($handle);
        }
        if (file_exists($path) || ! rename($tmp, $path)) {
            throw new RuntimeException('Receipt publication failed.');
        }
        self::$run = null;
    }
}
