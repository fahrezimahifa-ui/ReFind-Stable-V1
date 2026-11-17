<?php

namespace App\Database;

use Illuminate\Database\MariaDbConnection;
use PDO;
use RuntimeException;

final class PinnedMariaDbConnection extends MariaDbConnection
{
    private ?PDO $pinned = null;

    private ?int $pinnedId = null;

    private bool $transactionOwner = false;

    public function pin(): int
    {
        if ($this->pinned || $this->transactionLevel() !== 0) {
            throw new RuntimeException('Nested write units are forbidden.');
        }
        $this->reconnectIfMissingConnection(); // Resolve and validate only before the critical section.
        $pdo = $this->getPdo();
        $this->pinnedId = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
        $this->pinned = $pdo;

        return $this->pinnedId;
    }

    public function assertPinned(): void
    {
        if (! $this->pinned || $this->getPdo() !== $this->pinned) {
            throw new RuntimeException('Write connection ownership changed.');
        }
        if ((int) $this->pinned->query('SELECT CONNECTION_ID()')->fetchColumn() !== $this->pinnedId) {
            throw new RuntimeException('Write connection identity changed.');
        }
    }

    public function beginTransaction()
    {
        if ($this->pinned && ! $this->transactionOwner) {
            throw new RuntimeException('Transaction control belongs to the write coordinator.');
        }

        return parent::beginTransaction();
    }

    public function commit()
    {
        if ($this->pinned && ! $this->transactionOwner) {
            throw new RuntimeException('Transaction control belongs to the write coordinator.');
        }

        return parent::commit();
    }

    public function rollBack($toLevel = null)
    {
        if ($this->pinned && ! $this->transactionOwner) {
            throw new RuntimeException('Transaction control belongs to the write coordinator.');
        }

        return parent::rollBack($toLevel);
    }

    public function beginOwnedTransaction(): void
    {
        if (! $this->pinned || $this->transactionLevel() !== 0) {
            throw new RuntimeException('Invalid owned transaction start.');
        }
        $this->transactionOwner = true;
        try {
            $this->beginTransaction();
        } finally {
            $this->transactionOwner = false;
        }
    }

    public function commitOwnedTransaction(?callable $transport = null): void
    {
        $this->assertOwnedTransaction();
        $this->transactionOwner = true;
        try {
            if ($transport) {
                $transport($this);
            } else {
                $this->commit();
            }
            if ($this->transactionLevel() !== 0 || $this->getPdo()->inTransaction()) {
                throw new RuntimeException('Commit transport did not finish the owned transaction.');
            }
        } finally {
            $this->transactionOwner = false;
        }
    }

    public function rollbackOwnedTransaction(): void
    {
        $this->transactionOwner = true;
        try {
            $this->rollBack(0);
        } finally {
            $this->transactionOwner = false;
        }
    }

    public function assertOwnedTransaction(): void
    {
        $this->assertPinned();
        if ($this->transactionLevel() !== 1 || ! $this->getPdo()->inTransaction()) {
            throw new RuntimeException('Write transaction boundary changed.');
        }
    }

    public function reconnect()
    {
        if ($this->pinned) {
            throw new RuntimeException('Reconnection is forbidden inside a write unit.');
        }

        return parent::reconnect();
    }

    public function setPdo($pdo)
    {
        if ($this->pinned && $pdo !== $this->pinned) {
            throw new RuntimeException('Replacing a pinned connection is forbidden.');
        }

        return parent::setPdo($pdo);
    }

    public function releasePin(): void
    {
        $this->pinned = null;
        $this->pinnedId = null;
        $this->transactionOwner = false;
        // Reset Laravel's transaction counter too; no transaction may survive the unit.
        parent::setPdo(null);
        parent::setReadPdo(null);
    }

    public function pinnedId(): ?int
    {
        return $this->pinnedId;
    }
}
