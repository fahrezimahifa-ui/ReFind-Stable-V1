<?php

namespace Tests\Feature;

use App\Database\PinnedMariaDbConnection;
use App\Support\ReportQueryCounter;
use RuntimeException;
use Tests\TestCase;

final class QueryMetricsSafetyTest extends TestCase
{
    public function test_metrics_toggle_preserves_the_guarded_connection_and_owned_transaction(): void
    {
        $manager = $this->app->make('db');
        $this->assertSame([], $manager->getConnections(), 'Boot must not resolve a connection.');
        $db = $manager->connection();
        $this->assertInstanceOf(PinnedMariaDbConnection::class, $db);
        $this->assertInstanceOf(\Closure::class, $db->getRawPdo(), 'Driver registration must stay lazy.');
        $id = $db->pin();
        $pdo = $db->getPdo();
        $counter = app(ReportQueryCounter::class);
        try {
            $db->beginOwnedTransaction();
            foreach ([false, true, false, true] as $enabled) {
                config(['refind.query_metrics' => $enabled]);
                $count = $counter->count;
                $actual = (int) $db->selectOne('SELECT CONNECTION_ID() AS id')->id;
                $this->assertSame($count + ($enabled ? 1 : 0), $counter->count);
                $this->assertSame($db, $manager->connection());
                $this->assertSame($pdo, $db->getPdo());
                $this->assertSame($id, $actual);
                $this->assertSame($id, $db->pinnedId());
                $db->assertOwnedTransaction();
                foreach ([fn () => $db->beginTransaction(), fn () => $db->commit(), fn () => $db->rollBack(), fn () => $db->reconnect(), fn () => $db->setPdo(null)] as $forbidden) {
                    try {
                        $forbidden();
                        $this->fail('Metrics must never weaken pinned transaction guards.');
                    } catch (RuntimeException) {
                        $this->assertSame($pdo, $db->getPdo());
                        $this->assertSame(1, $db->transactionLevel());
                        $this->assertTrue($pdo->inTransaction());
                    }
                }
            }
            $db->rollbackOwnedTransaction();
            $this->assertFalse($pdo->inTransaction());
            $this->assertSame(0, $db->transactionLevel());
        } finally {
            if ($pdo->inTransaction()) {
                $db->rollbackOwnedTransaction();
            }
            $db->releasePin();
        }
        $this->assertNull($db->pinnedId());
    }
}
