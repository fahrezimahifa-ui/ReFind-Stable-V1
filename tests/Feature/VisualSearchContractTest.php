<?php

namespace Tests\Feature;

use App\Support\Workspace;
use App\Visual\FakeProvider;
use App\Visual\IndexStore;
use App\Visual\Preprocess;
use App\Visual\Provider;
use App\Visual\ProviderError;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Photo search endpoints on real synthetic phase8-dev data (owner decisions 25 Nov 2026). The offline fake engine
 * and a scratch index are bound in code, so no network call is possible (Http::preventStrayRequests) and the real
 * state/phase8-dev/visual index and config are never touched. Rows, media and limiter files are restored exactly.
 */
final class VisualSearchContractTest extends TestCase
{
    use Phase6Harness;

    private string $index;

    private array $authFiles = [];

    private int $calls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->index = Workspace::storage().'/tmp/visual-test-'.bin2hex(random_bytes(6));
        $this->app->instance('refind.visual.config', ['provider' => 'fake']);
        $this->app->instance('refind.visual.index_dir', $this->index);
        $this->calls = 0;
        $test = $this;
        $this->app->instance(Provider::class, new class($test) implements Provider
        {
            public function __construct(private VisualSearchContractTest $test) {}

            public function modelKey(): string
            {
                return FakeProvider::KEY;
            }

            public function embed(array $jpegs): array
            {
                $this->test->countCall();

                return (new FakeProvider)->embed($jpegs);
            }
        });
    }

    protected function tearDown(): void
    {
        foreach (glob($this->index.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->index);
        parent::tearDown();
    }

    public function countCall(): void
    {
        $this->calls++;
    }

    private function withAuthState(callable $body): void
    {
        $dir = Workspace::storage().'/auth';
        foreach (glob($dir.'/*.json') ?: [] as $file) {
            $this->authFiles[$file] = file_get_contents($file);
        }
        try {
            $body();
        } finally {
            foreach (glob($dir.'/*.json') ?: [] as $file) {
                if (! array_key_exists($file, $this->authFiles)) {
                    unlink($file);
                }
            }
            foreach ($this->authFiles as $file => $content) {
                file_put_contents($file, $content);
            }
        }
    }

    private function stages(string $value, callable $body): void
    {
        $previous = getenv('REFIND_PHASE6_STAGES');
        putenv('REFIND_PHASE6_STAGES='.$value);
        try {
            $body();
        } finally {
            putenv($previous === false ? 'REFIND_PHASE6_STAGES' : 'REFIND_PHASE6_STAGES='.$previous);
        }
    }

    /** A JPEG with a coloured block, written as a PHP upload under the owned uploads folder. */
    private function photo(array $rgb, int $w = 120, int $h = 90, string $type = 'jpg'): UploadedFile
    {
        $path = Workspace::storage().'/uploads/vs-'.bin2hex(random_bytes(8)).'.'.$type;
        Workspace::assertPath($path);
        file_put_contents($path, $this->imageBytes($rgb, $w, $h, $type));

        return new UploadedFile($path, 'query.'.$type, $type === 'png' ? 'image/png' : 'image/jpeg', null, true);
    }

    /** A centred coloured block on light grey, or ($block = false) a solid fill that matches nothing else. */
    private function imageBytes(array $rgb, int $w = 120, int $h = 90, string $type = 'jpg', bool $block = true): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, $block ? imagecolorallocate($im, 245, 245, 245) : imagecolorallocate($im, ...$rgb));
        if ($block) {
            imagefilledrectangle($im, (int) ($w * 0.2), (int) ($h * 0.2), (int) ($w * 0.8), (int) ($h * 0.8), imagecolorallocate($im, ...$rgb));
        }
        ob_start();
        $type === 'png' ? imagepng($im) : imagejpeg($im, null, 90);

        return (string) ob_get_clean();
    }

    /** Adds a public (or ownership) photo row plus its private file; returns photo_id. */
    private function addPhoto(int $item, array $rgb, string $kind = 'ITEM', bool $block = true): int
    {
        $name = ($kind === 'ITEM' ? 'items/' : 'evidence/').bin2hex(random_bytes(16)).'.jpg';
        file_put_contents(Workspace::media().'/'.$name, $this->imageBytes($rgb, 120, 90, 'jpg', $block));
        $s = $this->admin->prepare('INSERT INTO item_photos(item_id,photo_kind,file_path,is_primary) VALUES (?,?,?,0)');
        $s->execute([$item, $kind, $name]);

        return (int) $this->admin->lastInsertId();
    }

    /** Indexes the given public photos with the fake engine into the scratch index. */
    private function index(array $photoIds): void
    {
        $marks = implode(',', array_fill(0, count($photoIds), '?'));
        $entries = [];
        foreach ($this->rows("SELECT photo_id,file_path FROM item_photos WHERE photo_kind='ITEM' AND photo_id IN ($marks)", $photoIds) as $row) {
            $bytes = file_get_contents(Workspace::media().'/'.$row['file_path']);
            $entries[(int) $row['photo_id']] = ['path' => $row['file_path'], 'sha256' => hash('sha256', $bytes), 'vector' => FakeProvider::vector(Preprocess::prepare($bytes))];
        }
        $store = new IndexStore($this->index);
        $store->exclusive(fn () => $store->commit(FakeProvider::KEY, 128, $entries, null));
    }

    private function search(array $photos, array $data = [], string $uri = '/api/visual-search.php')
    {
        return $this->call('POST', $uri, $data, [], $photos === [] ? [] : ['photo' => $photos], ['HTTP_X_CSRF_TOKEN' => self::TOKEN, 'HTTP_ACCEPT' => 'application/json']);
    }

    public function test_gate_roles_and_session_flags(): void
    {
        $this->withSnapshot(function () {
            $this->withAuthState(function () {
                $this->assertError($this->search([$this->photo([200, 30, 30])]), 401, 'authentication_required');
                $this->asUser($this->bm);
                $this->assertError($this->search([$this->photo([200, 30, 30])]), 403, 'forbidden');
                $session = $this->getJson('/api/session.php');
                $this->assertFalse($session->json('visual_search_enabled'));
                $this->assertTrue($session->json('visual_staff_enabled'));
                $this->asUser($this->studentA);
                $this->assertError($this->search([$this->photo([200, 30, 30])], [], '/api/staff-visual-search.php'), 403, 'forbidden');
                $this->assertError($this->call('POST', '/api/visual-search.php', [], [], ['photo' => [$this->photo([1, 2, 3])]], ['HTTP_ACCEPT' => 'application/json']), 403, 'csrf_invalid');
                $this->assertTrue($this->getJson('/api/session.php')->json('visual_search_enabled'));
                $this->assertFalse($this->getJson('/api/session.php')->json('visual_staff_enabled'));
                $this->stages('student,review,approval,pickup,status,notifications,account,registration,history', function () {
                    $this->assertError($this->search([$this->photo([200, 30, 30])]), 403, 'visual_disabled');
                    $this->assertFalse($this->getJson('/api/session.php')->json('visual_search_enabled'));
                });
                // Hosted engine configured but the owner's key file absent: unavailable, and no provider call.
                $this->app->instance('refind.visual.config', ['provider' => 'inferhub', 'base_url' => 'https://api.example.test/v1', 'strategy' => 'image_embedding', 'embedding_model' => 'm', 'key_file' => 'C:/ReFind-Laravel/state/secrets/absent-for-tests.key']);
                $this->assertError($this->search([$this->photo([200, 30, 30])]), 503, 'visual_unavailable');
                $this->assertFalse($this->getJson('/api/session.php')->json('visual_search_enabled'));
                $this->assertSame(0, $this->calls);
            });
        });
    }

    public function test_photo_validation(): void
    {
        $this->withSnapshot(function () {
            $this->withAuthState(function () {
                $this->asUser($this->studentA);
                $this->assertError($this->search([]), 422, 'validation_error', null, 'photo');
                $four = array_map(fn () => $this->photo([10, 20, 30]), range(1, 4));
                $this->assertError($this->search($four), 422, 'validation_error', null, 'photo');
                $text = Workspace::storage().'/uploads/vs-'.bin2hex(random_bytes(8)).'.jpg';
                file_put_contents($text, 'not an image');
                $this->assertError($this->search([new UploadedFile($text, 'x.jpg', 'image/jpeg', null, true)]), 422, 'validation_error', null, 'photo');
                $this->assertError($this->search([$this->photo([10, 20, 30], 1300, 40, 'png')]), 422, 'validation_error', null, 'photo');
                $this->assertError($this->search([$this->photo([10, 20, 30])], ['q' => 'words']), 400, 'invalid_request');
                $this->assertError($this->search([$this->photo([10, 20, 30])], ['type' => 'OTHER']), 400, 'validation_error');
                $this->assertSame(0, $this->calls);
            });
        });
    }

    public function test_uncalibrated_and_empty_index_never_call_the_engine(): void
    {
        $this->withSnapshot(function () {
            $this->withAuthState(function () {
                $this->asUser($this->studentA);
                $this->search([$this->photo([200, 30, 30])])->assertOk()->assertJsonPath('state', 'empty_index')->assertJsonPath('items', []);
                $this->app->instance(Provider::class, new class implements Provider
                {
                    public function modelKey(): string
                    {
                        return 'uncalibrated:test';
                    }

                    public function embed(array $jpegs): array
                    {
                        throw new \LogicException('must not be called');
                    }
                });
                $this->assertError($this->search([$this->photo([200, 30, 30])]), 503, 'visual_uncalibrated');
                $this->assertSame(0, $this->calls);
            });
        });
    }

    public function test_matches_labels_filters_and_privacy(): void
    {
        $this->withSnapshot(function () {
            $this->withAuthState(function () {
                $red = [200, 30, 30];
                $found = $this->item($this->studentB, 'FOUND', 'OPEN', 1, 38, 'Red pouch found near the library');
                $lost = $this->item($this->studentB, 'LOST', 'OPEN', 1, 38, 'Blue pouch lost');
                $closed = $this->item($this->studentB, 'FOUND', 'RESOLVED', 1, 38, 'Red pouch already returned');
                $foundPhoto = $this->addPhoto($found, $red);
                $other = $this->addPhoto($found, [20, 160, 40], 'ITEM', false);
                $lostPhoto = $this->addPhoto($lost, [30, 60, 200], 'ITEM', false);
                $closedPhoto = $this->addPhoto($closed, $red);
                $ownership = $this->addPhoto($lost, $red, 'OWNERSHIP');
                $this->index([$foundPhoto, $other, $lostPhoto, $closedPhoto, $ownership]);
                $this->asUser($this->studentA);
                $r = $this->search([$this->photo([10, 10, 10]), $this->photo($red)])->assertOk()->assertJsonPath('state', 'matches');
                $items = $r->json('items');
                $this->assertSame([(string) $found], array_column($items, 'item_id'), 'closed reports stay out without a status filter; ownership photos are never indexed');
                $this->assertSame('very_similar', $items[0]['match_label']);
                $this->assertSame('/api/photos.php?kind=item&id='.$foundPhoto, $items[0]['photos'][0], 'matched photo first');
                $this->assertSame($foundPhoto, $items[0]['photo_ids'][0]);
                $this->assertStringNotContainsString('score', $r->getContent());
                $this->assertStringNotContainsString('verification_clue', $r->getContent());
                $this->search([$this->photo($red)], ['type' => 'LOST'])->assertOk()->assertJsonPath('state', 'no_match')->assertJsonPath('items', []);
                $withClosed = $this->search([$this->photo($red)], ['status' => 'RESOLVED'])->assertOk();
                $this->assertSame([(string) $closed], array_column($withClosed->json('items'), 'item_id'));
                $this->asUser($this->bm);
                $staff = $this->search([$this->photo($red)], ['type' => 'FOUND', 'status' => 'all'], '/api/staff-visual-search.php')->assertOk()->assertJsonPath('state', 'matches');
                $this->assertEqualsCanonicalizing([(string) $found, (string) $closed], array_column($staff->json('reports'), 'item_id'));
                $this->assertArrayHasKey('allowed_statuses', $staff->json('reports.0'));
                $this->assertArrayHasKey('match_label', $staff->json('reports.0'));
                $this->assertSame(1, $staff->json('pages'));
                $this->search([$this->photo($red)], ['type' => 'LOST', 'status' => 'all'], '/api/staff-visual-search.php')->assertOk()->assertJsonPath('state', 'no_match');
                $this->assertSame(5, $this->calls, 'one engine call per search, whatever the photo count');
            });
        });
    }

    public function test_budget_and_engine_failures(): void
    {
        $this->withSnapshot(function () {
            $this->withAuthState(function () {
                $found = $this->item($this->studentB, 'FOUND', 'OPEN', 1, 38, 'Budget test report');
                $this->index([$this->addPhoto($found, [200, 30, 30])]);
                $this->app->instance('refind.visual.config', ['provider' => 'fake', 'user_per_minute' => 2]);
                $this->asUser($this->studentA);
                $this->search([$this->photo([200, 30, 30]), $this->photo([1, 2, 3])])->assertOk();
                $limited = $this->search([$this->photo([200, 30, 30])]);
                $this->assertError($limited, 429, 'visual_rate_limited');
                $this->assertMatchesRegularExpression('/^[1-9][0-9]*$/', (string) $limited->headers->get('Retry-After'));
                $this->app->instance('refind.visual.config', ['provider' => 'fake']);
                foreach (['timeout' => [503, 'visual_timeout'], 'unavailable' => [503, 'visual_unavailable'], 'quota' => [429, 'visual_rate_limited']] as $reason => [$status, $code]) {
                    $this->app->instance(Provider::class, new class($reason) implements Provider
                    {
                        public function __construct(private string $reason) {}

                        public function modelKey(): string
                        {
                            return FakeProvider::KEY;
                        }

                        public function embed(array $jpegs): array
                        {
                            throw new ProviderError($this->reason, $this->reason === 'quota' ? 30 : 0);
                        }
                    });
                    $this->asUser($this->studentB);
                    $this->assertError($this->search([$this->photo([200, 30, 30])]), $status, $code);
                }
                // After a quota error the installation pauses: even a fresh user waits.
                $this->asUser($this->studentA);
                $this->assertError($this->search([$this->photo([200, 30, 30])]), 429, 'visual_rate_limited');
            });
        });
    }
}
