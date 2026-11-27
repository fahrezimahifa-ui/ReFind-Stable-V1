<?php

namespace App\Visual;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Google Gemini image embeddings (gemini-embedding-2, as native QA uses; owner decision 27 Nov 2026 after InferHub
 * offered no embedding models). One batchEmbedContents call embeds 1–4 photos. The key goes only to Google's API
 * host (fixed below, never configurable) in the x-goog-api-key header; errors are reduced to reason codes.
 */
final class GeminiProvider implements Provider
{
    public const HOST = 'https://generativelanguage.googleapis.com/v1beta';

    public ?array $usage = null;

    public function __construct(private array $config) {}

    public function modelKey(): string
    {
        return implode(':', ['gemini', $this->config['embedding_model'], (string) ($this->config['dimensions'] ?? 'native'), Preprocess::VERSION]);
    }

    /** Pure: batchEmbedContents body for prepared JPEGs. */
    public static function request(string $model, ?int $dimensions, array $jpegs): array
    {
        return ['requests' => array_map(fn ($jpeg) => array_filter([
            'model' => 'models/'.$model,
            'content' => ['parts' => [['inline_data' => ['mime_type' => 'image/jpeg', 'data' => base64_encode($jpeg)]]]],
            'output_dimensionality' => $dimensions,
        ], fn ($v) => $v !== null), $jpegs)];
    }

    /** Pure: {"embeddings":[{"values":[…]}]} → unit vectors in order. */
    public static function parse(mixed $json, int $expected, ?int $dimensions): array
    {
        $rows = is_array($json) ? ($json['embeddings'] ?? null) : null;
        if (! is_array($rows) || count($rows) !== $expected) {
            throw new ProviderError('bad_response');
        }
        $out = [];
        foreach ($rows as $row) {
            try {
                $out[] = Vector::normalize(is_array($row) && is_array($row['values'] ?? null) ? $row['values'] : [], $dimensions);
            } catch (\InvalidArgumentException) {
                throw new ProviderError('bad_response');
            }
        }

        return $out;
    }

    public function embed(array $jpegs): array
    {
        if ($jpegs === []) {
            return [];
        }
        $model = $this->config['embedding_model'];
        $deadline = microtime(true) + $this->config['timeout_seconds'];
        $response = null;
        // One quick retry when the connection itself stalls (Batch A: connect stalls, not slow answers), within the
        // same overall deadline.
        for ($attempt = 0; $attempt < 2 && $response === null; $attempt++) {
            $left = $deadline - microtime(true);
            if ($left < 1) {
                break;
            }
            try {
                $response = Http::withHeaders(['x-goog-api-key' => VisualConfig::key($this->config)])
                    ->acceptJson()->asJson()
                    ->connectTimeout(min(3, $left))
                    ->timeout($left)
                    ->withoutRedirecting()
                    ->post(self::HOST.'/models/'.rawurlencode($model).':batchEmbedContents', self::request($model, $this->config['dimensions'], $jpegs));
            } catch (ConnectionException) {
                $response = null;
            } catch (\Throwable) {
                throw new ProviderError('unavailable', 60);
            }
        }
        if ($response === null) {
            throw new ProviderError('timeout');
        }
        if (! $response->successful()) {
            throw ProviderError::fromStatus($response->status(), (int) $response->header('Retry-After'));
        }
        if (strlen($response->body()) > 8_000_000) {
            throw new ProviderError('bad_response');
        }

        return self::parse($response->json(), count($jpegs), $this->config['dimensions']);
    }
}
