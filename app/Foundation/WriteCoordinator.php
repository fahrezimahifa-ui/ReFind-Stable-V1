<?php

namespace App\Foundation;

use App\Database\PinnedMariaDbConnection;
use App\Exceptions\ContractError;
use App\Support\Workspace;
use RuntimeException;
use Throwable;

final class WriteCoordinator
{
    public function __construct(private PinnedMariaDbConnection $db) {}

    public function referenced(string $path): bool
    {
        foreach ([['users', 'avatar_path'], ['item_photos', 'file_path'], ['claim_photos', 'file_path']] as [$table,$column]) {
            if ($this->db->selectOne('SELECT 1 FROM '.$table.' WHERE '.$column.'=? LIMIT 1', [$path])) {
                return true;
            }
        }

        return Workspace::profile()['profile'] === 'academic-test' && (bool) $this->db->selectOne('SELECT 1 FROM _foundation_probe WHERE media_path=? LIMIT 1', [$path]);
    }

    /** Fault callbacks/commit transport are injectable PHP test collaborators, never HTTP inputs. */
    public function run(MediaOperation $operation, callable $work, ?callable $fault = null, ?callable $commitTransport = null, int $lockWait = 5): mixed
    {
        if (! in_array(Workspace::profile()['profile'], config('refind.report_write_profiles', ['academic-test']), true)) {
            throw new RuntimeException('Phase 4 writes are enabled only for synthetic B tests.');
        }
        if ($lockWait < 0 || $lockWait > 5) {
            throw new RuntimeException('Invalid bounded advisory wait.');
        }
        $name = 'refind-matching-'.Workspace::profile()['database'];
        $acquired = false;
        $commitStarted = false;
        $committed = false;
        $rolledBack = false;
        $result = null;
        $failure = null;
        $hit = static function (string $point) use ($fault) {
            if ($fault) {
                $fault($point);
            }
        };
        try {
            $this->db->pin();
            $this->db->statement('SET SESSION innodb_lock_wait_timeout=5');
            $value = (int) $this->db->selectOne('SELECT GET_LOCK(?,?) AS acquired', [$name, $lockWait])->acquired;
            if ($value !== 1) {
                throw new LockUnavailable('Write advisory lock unavailable.');
            }$acquired = true;
            $this->db->assertPinned();
            $this->db->beginOwnedTransaction();
            $operation->state('in_transaction');
            $hit('after_begin');
            $result = $work(new WriteContext($this->db, $operation->id));
            $hit('after_work');
            $this->db->assertOwnedTransaction();
            $operation->promote();
            $hit('after_promote');
            foreach ($operation->snapshot()['new'] as $file) {
                if (! $this->referenced($file['final'])) {
                    throw new RuntimeException('Promoted media has no transactional reference.');
                }
            }
            $this->db->insert('INSERT INTO _write_operations(operation_id,manifest_sha256) VALUES(?,?)', [$operation->id, $operation->planHash()]);
            $this->db->assertPinned();
            $hit('before_commit');
            $operation->state('commit_started');
            $commitStarted = true;
            $this->db->commitOwnedTransaction($commitTransport);
            $committed = true;
            $operation->state('committed');
            $hit('after_commit');
        } catch (Throwable $error) {
            $failure = $error;
            if (! $committed && ! $commitStarted) {
                try {
                    if ($this->db->transactionLevel() > 0) {
                        $this->db->rollbackOwnedTransaction();
                    }$rolledBack = true;
                    $operation->state('rolled_back');
                } catch (Throwable $rollbackError) {
                    $operation->state('outcome_unknown');
                }
            } elseif (! $committed) {
                $operation->state('outcome_unknown');
            } else {
                $operation->state('cleanup_pending');
                $operation->event('post_commit_error');
            }
        } finally {
            try {
                if ($acquired) {
                    $released = $this->db->selectOne('SELECT RELEASE_LOCK(?) AS released', [$name])->released;
                    if ((int) $released !== 1) {
                        $operation->event('release_not_owned');
                    } else {
                        $operation->event('advisory_released');
                    }
                }
            } catch (Throwable $releaseError) {
                $operation->event('release_connection_lost');
            } finally {
                $this->db->releasePin();
            }
        }
        if ($committed) {
            $operation->cleanup(true, fn ($path) => $this->referenced($path));

            return $result;
        }
        if ($rolledBack) {
            $operation->cleanup(false, fn ($path) => $this->referenced($path));
        }
        if ($rolledBack && $failure instanceof ContractError) {
            throw $failure;
        }
        throw new WriteFailure($operation->id, ! $rolledBack, $failure ?? new RuntimeException('Write failed.'));
    }

    public function reconcile(MediaOperation $operation): string
    {
        if ($this->db->transactionLevel() !== 0) {
            throw new RuntimeException('Reconciliation requires a fresh transaction.');
        }
        // This locking read waits for an outstanding operation-marker insert to settle.
        $this->db->statement('SET SESSION innodb_lock_wait_timeout=5');
        $this->db->beginTransaction();
        try {
            $marker = $this->db->selectOne('SELECT manifest_sha256 FROM _write_operations WHERE operation_id=? FOR UPDATE', [$operation->id]);
            if ($marker && $marker->manifest_sha256 !== $operation->planHash()) {
                throw new RuntimeException('Operation ledger checksum mismatch.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->transactionLevel() > 0) {
                $this->db->rollBack();
            }throw $error;
        }
        $operation->state($marker ? 'committed' : 'rolled_back');
        $operation->cleanup((bool) $marker, fn ($path) => $this->referenced($path));

        return $marker ? 'committed' : 'rolled_back';
    }
}
