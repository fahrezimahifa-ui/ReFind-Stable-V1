<?php

namespace App\Foundation;

use App\Support\Workspace;
use RuntimeException;

final class MediaOperation
{
    private array $data;

    private mixed $lock;

    public function __construct(public readonly string $id, private FileOperations $files, bool $create)
    {
        if (! preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new RuntimeException('Invalid operation ID.');
        }
        $this->lock = fopen(MediaJournal::scoped('operations/manifests/'.$id.'.lock'), 'c+b');
        if (! $this->lock || ! flock($this->lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Operation is active; reconciliation refused.');
        }
        if ($create) {
            if (file_exists($this->manifest())) {
                throw new RuntimeException('Operation already exists.');
            }
            $this->data = ['version' => 1, 'operation_id' => $id, 'workspace_id' => Workspace::profile()['workspace_id'], 'profile' => Workspace::profile()['profile'], 'state' => 'prepared', 'new' => [], 'removed' => [], 'events' => []];
            $this->persist();
        } else {
            $this->data = json_decode(file_get_contents($this->manifest()), true, 32, JSON_THROW_ON_ERROR);
            $this->validate();
        }
    }

    public function __destruct()
    {
        if (is_resource($this->lock)) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
        }
    }

    private function manifest(): string
    {
        return MediaJournal::scoped('operations/manifests/'.$this->id.'.json');
    }

    private function validate(): void
    {
        if (($this->data['version'] ?? null) !== 1 || ($this->data['operation_id'] ?? null) !== $this->id || ($this->data['workspace_id'] ?? null) !== Workspace::profile()['workspace_id'] || ($this->data['profile'] ?? null) !== Workspace::profile()['profile']) {
            throw new RuntimeException('Foreign operation manifest.');
        }
        foreach ($this->data['new'] as $entry) {
            if (! preg_match('/^[a-f0-9]{64}$/D', $entry['sha256'] ?? '')) {
                throw new RuntimeException('Invalid media checksum.');
            }
            MediaJournal::scoped($entry['staged']);
            MediaJournal::scoped('app/private/refind/'.$entry['final']);
            if (! str_starts_with($entry['staged'], 'operations/staging/'.$this->id.'-')) {
                throw new RuntimeException('Foreign staging entry.');
            }
        }
        foreach ($this->data['removed'] as $path) {
            MediaJournal::scoped('app/private/refind/'.$path);
        }
    }

    private function persist(): void
    {
        $this->validate();
        $tmp = $this->manifest().'.tmp-'.bin2hex(random_bytes(8));
        Workspace::assertPath($tmp);
        $this->files->writeExclusive($tmp, json_encode($this->data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        if (! rename($tmp, $this->manifest())) {
            @unlink($tmp);
            throw new RuntimeException('Operation manifest replacement failed.');
        }
    }

    public function state(string $state): void
    {
        if (! in_array($state, ['prepared', 'in_transaction', 'promoting', 'commit_started', 'committed', 'rolled_back', 'outcome_unknown', 'cleanup_pending', 'complete'], true)) {
            throw new RuntimeException('Invalid operation state.');
        }
        $this->data['state'] = $state;
        $this->persist();
    }

    public function event(string $event): void
    {
        if (! preg_match('/^[a-z_]{1,60}$/D', $event)) {
            throw new RuntimeException('Invalid operation event.');
        }
        $this->data['events'][] = $event;
        $this->persist();
    }

    public function stage(string $bytes, string $collection, string $extension): string
    {
        if ($this->data['state'] !== 'prepared' || ! in_array($collection, ['items', 'evidence', 'claims'], true) || ! in_array($extension, ['jpg', 'png', 'webp'], true)) {
            throw new RuntimeException('Invalid media staging operation.');
        }
        $limit = $collection === 'items' ? 4 : 2;
        if (count(array_filter($this->data['new'], fn ($x) => str_starts_with($x['final'], $collection.'/'))) >= $limit) {
            throw new RuntimeException('Photo count exceeded.');
        }
        $token = bin2hex(random_bytes(16));
        $final = $collection.'/'.$token.'.'.$extension;
        $stage = 'operations/staging/'.$this->id.'-'.$token.'.'.$extension;
        $this->data['new'][] = ['final' => $final, 'staged' => $stage, 'sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)];
        $this->persist();
        $this->files->writeExclusive(MediaJournal::scoped($stage), $bytes);

        return $final;
    }

    public function removeAfterCommit(string $relative): void
    {
        if ($this->data['state'] !== 'prepared') {
            throw new RuntimeException('Media plan is already sealed.');
        }
        MediaJournal::scoped('app/private/refind/'.$relative);
        $this->data['removed'] = array_values(array_unique([...$this->data['removed'], $relative]));
        $this->persist();
    }

    public function planHash(): string
    {
        return hash('sha256', json_encode([$this->data['new'], $this->data['removed']], JSON_THROW_ON_ERROR));
    }

    public function promote(): void
    {
        $this->state('promoting');
        foreach ($this->data['new'] as $x) {
            $source = MediaJournal::scoped($x['staged']);
            if (! is_file($source) || hash_file('sha256', $source) !== $x['sha256']) {
                throw new RuntimeException('Staged file integrity failed.');
            }
            $this->files->move($source, MediaJournal::scoped('app/private/refind/'.$x['final']));
        }
    }

    public function cleanup(bool $committed, callable $referenced): bool
    {
        try {
            foreach ($this->data['new'] as $x) {
                $this->files->remove(MediaJournal::scoped($x['staged']));
                if (! $committed) {
                    if ($referenced($x['final'])) {
                        throw new RuntimeException('Rollback media is referenced; manual review required.');
                    }
                    $this->files->remove(MediaJournal::scoped('app/private/refind/'.$x['final']));
                }
            }
            if ($committed) {
                foreach ($this->data['removed'] as $path) {
                    if (! $referenced($path)) {
                        $this->files->remove(MediaJournal::scoped('app/private/refind/'.$path));
                    }
                }
            }
            $this->state('complete');

            return true;
        } catch (\Throwable $error) {
            $this->state('cleanup_pending');

            return false;
        }
    }

    public function snapshot(): array
    {
        return $this->data;
    }
}
