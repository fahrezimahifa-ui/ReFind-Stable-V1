<?php

namespace Tests\Unit;

use App\Visual\Budget;
use App\Visual\Calibration;
use App\Visual\Describer;
use App\Visual\FakeProvider;
use App\Visual\GeminiProvider;
use App\Visual\IndexStore;
use App\Visual\InferHubProvider;
use App\Visual\Preprocess;
use App\Visual\ProviderError;
use App\Visual\Scorer;
use App\Visual\Vector;
use App\Visual\VisualConfig;
use PHPUnit\Framework\TestCase;

/** Photo search core (pure: no profile, database or network; runs in CI). */
final class VisualSearchTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/refind-visual-unit-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function unit(array $v): array
    {
        return Vector::normalize($v);
    }

    public function test_vectors_normalize_pack_and_reject_bad_values(): void
    {
        $v = $this->unit([3, 4]);
        $this->assertEqualsWithDelta([0.6, 0.8], $v, 1e-12);
        $this->assertEqualsWithDelta($v, Vector::unpack(Vector::pack($v), 2), 1e-6);
        $this->assertNull(Vector::unpack('abc', 2));
        $this->assertNull(Vector::unpack(pack('g*', 0.0, 0.0), 2));
        foreach ([[], [0, 0], [1, NAN], [1, INF], ['1', 2], ['a' => 1]] as $bad) {
            try {
                Vector::normalize($bad);
                $this->fail('accepted an invalid vector');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(1.0, Vector::cosine($v, $v));
    }

    public function test_best_match_over_every_query_and_photo_with_native_tie_breaks(): void
    {
        $x = $this->unit([1, 0, 0]);
        $y = $this->unit([0, 1, 0]);
        $xy = $this->unit([1, 1, 0]);
        $candidates = [
            ['item_id' => 7, 'photo_id' => 70, 'vector' => $y],
            ['item_id' => 7, 'photo_id' => 71, 'vector' => $xy],
            ['item_id' => 5, 'photo_id' => 50, 'vector' => $x],
            ['item_id' => 9, 'photo_id' => 91, 'vector' => $x],
            ['item_id' => 9, 'photo_id' => 90, 'vector' => $x],
        ];
        // Query photo 2 (y) matches report 7's first photo exactly, so 7 ties 5 and 9 at 1.0; lower report ID first.
        $ranked = Scorer::rank($candidates, [$x, $y]);
        $this->assertSame([5, 7, 9], array_column($ranked, 'item_id'));
        $this->assertSame(90, $ranked[2]['photo_id'], 'equal scores keep the lower photo ID');
        $this->assertSame(70, $ranked[1]['photo_id']);
        // One query photo only: report 7 drops to its best (the diagonal photo).
        $single = Scorer::rank($candidates, [$x]);
        $this->assertSame([5, 9, 7], array_column($single, 'item_id'));
        $this->assertSame(71, $single[2]['photo_id']);
    }

    public function test_select_applies_cutoffs_labels_and_limit_without_scores(): void
    {
        $ranked = [['item_id' => 1, 'photo_id' => 10, 'score' => 0.97], ['item_id' => 2, 'photo_id' => 20, 'score' => 0.85], ['item_id' => 3, 'photo_id' => 30, 'score' => 0.79]];
        $selected = Scorer::select($ranked, 0.8, 0.95);
        $this->assertSame([['item_id' => 1, 'photo_id' => 10, 'label' => 'very_similar'], ['item_id' => 2, 'photo_id' => 20, 'label' => 'similar']], $selected);
        $this->assertSame([], Scorer::select([['item_id' => 3, 'photo_id' => 30, 'score' => 0.5]], 0.8, 0.95), 'honest no match');
        $this->assertCount(1, Scorer::select($ranked, 0.8, 0.95, 1));
        $this->assertSame('similar', Scorer::label(0.99, 0.8, 0.7), 'a strong cutoff below the main one never upgrades');
        $this->assertStringNotContainsString('score', json_encode($selected));
    }

    public function test_index_generations_paths_and_model_keys(): void
    {
        $store = new IndexStore($this->dir);
        $this->assertNull($store->load('m1'));
        $a = $this->unit([1, 0, 0, 0]);
        $b = $this->unit([0, 1, 0, 0]);
        $meta = $store->exclusive(fn () => $store->commit('m1', 4, [12 => ['path' => 'items/a.jpg', 'sha256' => 'h1', 'vector' => $a], 3 => ['path' => 'items/b.jpg', 'sha256' => 'h2', 'vector' => $b]], null));
        $this->assertSame(1, $meta['generation']);
        $loaded = $store->load('m1');
        $this->assertNull($store->load('m2'), 'another model key never reuses vectors');
        $got = iterator_to_array((function () use ($store, $loaded) {
            foreach ($store->vectors($loaded, [12 => 'items/a.jpg', 3 => 'items/other.jpg', 99 => 'items/z.jpg']) as [$id, $v]) {
                yield $id => $v;
            }
        })());
        $this->assertSame([12], array_keys($got), 'a reused photo ID with another file is ignored');
        $this->assertEqualsWithDelta($a, $got[12], 1e-6);
        // Next generation reuses slot bytes, adds one, drops photo 3.
        $c = $this->unit([0, 0, 1, 0]);
        $keep = [12 => ['path' => 'items/a.jpg', 'sha256' => 'h1', 'slot' => $loaded['entries']['12']['slot'], 'at' => $loaded['entries']['12']['at']], 40 => ['path' => 'items/c.jpg', 'sha256' => 'h3', 'vector' => $c]];
        $second = $store->exclusive(fn () => $store->commit('m1', 4, $keep, $loaded));
        $this->assertSame(2, $second['generation']);
        $this->assertSame([12, 40], array_keys($second['entries']));
        $now = $store->load('m1');
        $vectors = [];
        foreach ($store->vectors($now, [12 => 'items/a.jpg', 40 => 'items/c.jpg']) as [$id, $v]) {
            $vectors[$id] = $v;
        }
        $this->assertEqualsWithDelta($a, $vectors[12], 1e-6);
        $this->assertEqualsWithDelta($c, $vectors[40], 1e-6);
        $third = $store->exclusive(fn () => $store->commit('m1', 4, $keep + [41 => ['path' => 'items/d.jpg', 'sha256' => 'h4', 'vector' => $b]], $now));
        $this->assertSame(['index-2.bin', 'index-3.bin'], array_map('basename', glob($this->dir.'/index-*.bin')), 'keeps current and previous generation only');
        $this->assertSame(3, $third['generation']);
    }

    public function test_preprocess_reencodes_and_rejects(): void
    {
        $img = imagecreatetruecolor(40, 30);
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        $jpeg = Preprocess::prepare($png);
        $this->assertSame('image/jpeg', (new \finfo(FILEINFO_MIME_TYPE))->buffer($jpeg));
        foreach (['', 'GIF89a not an image', str_repeat('x', 250001)] as $bad) {
            try {
                Preprocess::prepare($bad);
                $this->fail('accepted an invalid photo');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $big = imagecreatetruecolor(1201, 10);
        ob_start();
        imagepng($big);
        $this->expectException(\InvalidArgumentException::class);
        Preprocess::prepare((string) ob_get_clean());
    }

    public function test_fake_engine_is_deterministic(): void
    {
        $img = imagecreatetruecolor(64, 48);
        imagefilledrectangle($img, 0, 0, 31, 47, imagecolorallocate($img, 200, 30, 30));
        ob_start();
        imagejpeg($img, null, 90);
        $jpeg = Preprocess::prepare((string) ob_get_clean());
        [$first, $second] = (new FakeProvider)->embed([$jpeg, $jpeg]);
        $this->assertCount(128, $first);
        $this->assertEqualsWithDelta(1.0, Vector::cosine($first, $second), 1e-9);
    }

    public function test_hosted_request_and_response_parsing(): void
    {
        $config = ['embedding_model' => 'm', 'request_format' => 'openai', 'dimensions' => 768];
        $body = InferHubProvider::imageEmbeddingRequest($config, ['abc']);
        $this->assertSame(['model' => 'm', 'input' => ['data:image/jpeg;base64,YWJj'], 'encoding_format' => 'float', 'dimensions' => 768], $body);
        $jina = InferHubProvider::imageEmbeddingRequest(['request_format' => 'jina', 'dimensions' => null] + $config, ['abc']);
        $this->assertSame([['image' => 'data:image/jpeg;base64,YWJj']], $jina['input']);
        $parsed = InferHubProvider::parseEmbeddings(['data' => [['index' => 1, 'embedding' => [0, 2]], ['index' => 0, 'embedding' => [3, 4]]]], 2, null);
        $this->assertEqualsWithDelta([[0.6, 0.8], [0.0, 1.0]], $parsed, 1e-12);
        foreach ([null, ['data' => []], ['data' => [['embedding' => [1, NAN]]]], ['data' => [['index' => 0, 'embedding' => [1, 2]], ['index' => 1, 'embedding' => [1, 2, 3]]]], ['data' => [['index' => 0, 'embedding' => [1]], ['index' => 0, 'embedding' => [1]]]]] as $bad) {
            try {
                InferHubProvider::parseEmbeddings($bad, $bad && isset($bad['data']) && count($bad['data']) > 1 ? 2 : 1, null);
                $this->fail('accepted a bad provider response');
            } catch (ProviderError $e) {
                $this->assertSame('bad_response', $e->reason);
            }
        }
        $this->assertSame(['quota', 30], [ProviderError::fromStatus(429, 30)->reason, ProviderError::fromStatus(429, 30)->retryAfter]);
        $this->assertSame('project', ProviderError::fromStatus(401)->reason);
        $this->assertSame('timeout', ProviderError::fromStatus(504)->reason);
        $this->assertSame('rejected', ProviderError::fromStatus(413)->reason);
        $this->assertSame('unavailable', ProviderError::fromStatus(500)->reason);
    }

    public function test_describe_and_match_parsing_hashing_and_shrinking(): void
    {
        $reply = ['choices' => [['message' => ['content' => "```json\n{\"category\":\"Game_Controller\",\"object\":\"Dreamcast controller\",\"colors\":[\"white\",\"teal\",\"grey\"],\"brand\":\"Sega Dreamcast\",\"visible_text\":[\"DREAMCAST\",\"start\"],\"material\":\"plastic\",\"features\":[\"VMU slot\",\"attached cable\"]}\n```"]]]];
        $d = Describer::parse($reply);
        $this->assertSame('game_controller', $d['category']);
        $this->assertSame(['white', 'grey'], $d['colors'], 'colours outside the palette are dropped');
        $this->assertSame('sega dreamcast', $d['brand']);
        $this->assertSame(['dreamcast', 'start'], $d['visible_text']);
        $same = Describer::vector($d);
        $this->assertCount(Describer::DIMENSIONS, $same);
        $this->assertEqualsWithDelta(1.0, Vector::cosine($same, Describer::vector($d)), 1e-9);
        $sibling = Describer::vector(['category' => 'game_controller', 'object' => 'xbox controller', 'colors' => ['black'], 'brand' => 'xbox', 'visible_text' => [], 'material' => 'plastic', 'features' => ['analog sticks']]);
        $other = Describer::vector(['category' => 'umbrella', 'object' => 'folding umbrella', 'colors' => ['blue'], 'brand' => null, 'visible_text' => [], 'material' => 'fabric', 'features' => ['white dots']]);
        $this->assertGreaterThan(Vector::cosine($same, $other), Vector::cosine($same, $sibling), 'same category ranks above an unrelated item');
        $this->assertSame('other', Describer::parse(['choices' => [['message' => ['content' => '{"category":"spaceship","object":"x"}']]]])['category']);
        foreach ([null, ['choices' => [['message' => ['content' => 'no json here']]]], ['choices' => []]] as $bad) {
            try {
                Describer::parse($bad);
                $this->fail('accepted an unusable reply');
            } catch (ProviderError $e) {
                $this->assertSame('bad_response', $e->reason);
            }
        }
        $big = imagecreatetruecolor(1000, 500);
        ob_start();
        imagejpeg($big);
        $small = getimagesizefromstring(Describer::shrink((string) ob_get_clean()));
        $this->assertSame([640, 320], [$small[0], $small[1]]);
        $body = Describer::request('m', Describer::shrink((string) (function () { $i = imagecreatetruecolor(20, 10); ob_start(); imagejpeg($i); return ob_get_clean(); })()));
        $this->assertSame(0, $body['temperature']);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $body['messages'][1]['content'][0]['image_url']['url']);
        $this->assertSame('inferhub:describe_match:v/m:'.Describer::VERSION.':'.Preprocess::VERSION, (new InferHubProvider(VisualConfig::parse(['provider' => 'inferhub', 'base_url' => 'https://api.example.test/v1', 'strategy' => 'describe_match', 'vision_model' => 'v/m'])))->modelKey());
    }

    public function test_gemini_request_parsing_and_fixed_host(): void
    {
        $body = GeminiProvider::request('gemini-embedding-2', 768, ['abc', 'de']);
        $this->assertCount(2, $body['requests']);
        $this->assertSame(['model' => 'models/gemini-embedding-2', 'content' => ['parts' => [['inline_data' => ['mime_type' => 'image/jpeg', 'data' => 'YWJj']]]], 'output_dimensionality' => 768], $body['requests'][0]);
        $this->assertEqualsWithDelta([[0.6, 0.8]], GeminiProvider::parse(['embeddings' => [['values' => [3, 4]]]], 1, 2), 1e-12);
        foreach ([null, ['embeddings' => []], ['embeddings' => [['values' => [1, 2, 3]]]], ['embeddings' => [['values' => [0, 0]]]]] as $bad) {
            try {
                GeminiProvider::parse($bad, 1, 2);
                $this->fail('accepted a bad Gemini response');
            } catch (ProviderError $e) {
                $this->assertSame('bad_response', $e->reason);
            }
        }
        $c = VisualConfig::parse(['provider' => 'gemini']);
        $this->assertSame(['gemini-embedding-2', 768, 'image_embedding'], [$c['embedding_model'], $c['dimensions'], $c['strategy']]);
        $this->assertStringEndsWith('/state/secrets/gemini.key', $c['key_file']);
        $this->assertSame('gemini:gemini-embedding-2:768:'.Preprocess::VERSION, (new GeminiProvider($c))->modelKey());
        foreach ([['provider' => 'gemini', 'base_url' => 'https://evil.example/v1'], ['provider' => 'gemini', 'embedding_model' => 'other-model'], ['provider' => 'gemini', 'strategy' => 'describe_match']] as $bad) {
            try {
                VisualConfig::parse($bad);
                $this->fail('accepted invalid Gemini config: '.json_encode($bad));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_private_config_is_strict(): void
    {
        $ok = VisualConfig::parse(['provider' => 'inferhub', 'base_url' => 'https://api.example.test/v1/', 'strategy' => 'image_embedding', 'embedding_model' => 'org/model-1']);
        $this->assertSame('https://api.example.test/v1', $ok['base_url']);
        $this->assertSame(500, $ok['daily_cap']);
        $this->assertFalse($ok['auto_index']);
        $this->assertSame('fake', VisualConfig::parse(['provider' => 'fake'])['provider']);
        foreach ([
            ['provider' => 'other'],
            ['provider' => 'fake', 'surprise' => 1],
            ['provider' => 'inferhub', 'base_url' => 'http://api.example.test', 'strategy' => 'image_embedding', 'embedding_model' => 'm'],
            ['provider' => 'inferhub', 'base_url' => 'https://u:p@api.example.test', 'strategy' => 'image_embedding', 'embedding_model' => 'm'],
            ['provider' => 'inferhub', 'base_url' => 'https://api.example.test', 'strategy' => 'image_embedding'],
            ['provider' => 'inferhub', 'base_url' => 'https://api.example.test', 'strategy' => 'describe_match'],
            ['provider' => 'fake', 'daily_cap' => 0],
            ['provider' => 'fake', 'key_file' => 'C:/ReFind-Laravel/state/demo/inferhub.key'],
            [1, 2],
        ] as $bad) {
            try {
                VisualConfig::parse($bad);
                $this->fail('accepted invalid config: '.json_encode($bad));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_budget_minute_daily_and_pause(): void
    {
        $budget = new Budget($this->dir.'/budget.json', 'secret', ['user_per_minute' => 3, 'install_per_minute' => 5, 'daily_cap' => 7]);
        $t = 1_764_000_000; // fixed instant
        $this->assertSame(0, $budget->reserve(1, 3, $t));
        $this->assertGreaterThan(0, $budget->reserve(1, 1, $t + 10), 'user minute budget');
        $this->assertSame(0, $budget->reserve(2, 2, $t + 10));
        $this->assertGreaterThan(0, $budget->reserve(null, 1, $t + 20), 'installation minute budget');
        $this->assertSame(0, $budget->reserve(null, 2, $t + 61));
        $wait = $budget->reserve(3, 1, $t + 62);
        $this->assertGreaterThan(60, $wait, 'daily cap waits for campus midnight');
        $this->assertSame(7, $budget->summary($t + 62)['calls_today']);
        $fresh = new Budget($this->dir.'/budget2.json', 'secret', ['user_per_minute' => 3, 'install_per_minute' => 5, 'daily_cap' => 7]);
        $fresh->pause(120, $t);
        $this->assertSame(120, $fresh->reserve(1, 1, $t));
        $this->assertSame(0, $fresh->reserve(1, 1, $t + 121));
        $this->assertStringNotContainsString('"1"', (string) file_get_contents($this->dir.'/budget2.json'), 'user keys are HMACs');
    }

    public function test_calibration_receipts_bind_to_one_model_key(): void
    {
        file_put_contents(Calibration::file($this->dir, 'k1'), json_encode(['model_key' => 'k1', 'cutoff' => 0.7, 'strong_cutoff' => 0.8]));
        $this->assertSame(['cutoff' => 0.7, 'strong_cutoff' => 0.8], Calibration::load($this->dir, 'k1'));
        $this->assertNull(Calibration::load($this->dir, 'k2'));
        file_put_contents(Calibration::file($this->dir, 'k3'), json_encode(['model_key' => 'k3', 'cutoff' => 0.9, 'strong_cutoff' => 0.8]));
        $this->assertNull(Calibration::load($this->dir, 'k3'), 'strong below cutoff is invalid');
        $this->assertNotNull(Calibration::load(Calibration::dir(), FakeProvider::KEY), 'committed fake receipt');
    }
}
