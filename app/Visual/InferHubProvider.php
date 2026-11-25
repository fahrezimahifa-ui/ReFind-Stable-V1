<?php

namespace App\Visual;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Hosted engine through the owner's InferHub key (OpenAI-style HTTP API). The exact request shape for image
 * embeddings differs between models, so it is chosen by `request_format` once the owner's model list is known;
 * request building and response parsing are pure static functions so they can be unit-tested without a network.
 * Errors are reduced to ProviderError reason codes before anything can log them; the key is never logged.
 */
final class InferHubProvider implements Provider
{
    public const FORMATS = ['openai', 'jina'];

    /** Token usage of the last call when the provider reports it (evaluation cost estimates only). */
    public ?array $usage = null;

    public function __construct(private array $config) {}

    public function modelKey(): string
    {
        $c = $this->config;

        return implode(':', ['inferhub', $c['strategy'], $c['embedding_model'], (string) ($c['dimensions'] ?? 'native'), $c['request_format'], Preprocess::VERSION]);
    }

    public function embed(array $jpegs): array
    {
        if ($jpegs === []) {
            return [];
        }
        if ($this->config['strategy'] !== 'image_embedding') {
            // describe_embed (vision description -> text embedding) is wired once Batch A picks its models.
            throw new ProviderError('unavailable', 60);
        }
        $body = self::imageEmbeddingRequest($this->config, $jpegs);
        $response = $this->post('/embeddings', $body);
        $this->usage = is_array($response['usage'] ?? null) ? $response['usage'] : null;

        return self::parseEmbeddings($response, count($jpegs), $this->config['dimensions']);
    }

    /** Pure: the JSON body for an image-embedding call in the configured format. */
    public static function imageEmbeddingRequest(array $config, array $jpegs): array
    {
        $uris = array_map(fn ($jpeg) => 'data:image/jpeg;base64,'.base64_encode($jpeg), $jpegs);
        $body = ['model' => $config['embedding_model']];
        $body['input'] = match ($config['request_format']) {
            'openai' => $uris,
            'jina' => array_map(fn ($uri) => ['image' => $uri], $uris),
            default => throw new ProviderError('project', 900),
        };
        if ($config['request_format'] === 'openai') {
            $body['encoding_format'] = 'float';
        }
        if ($config['dimensions'] !== null) {
            $body['dimensions'] = $config['dimensions'];
        }

        return $body;
    }

    /** Pure: OpenAI-style {"data":[{"index":i,"embedding":[…]}]} → unit vectors in input order. */
    public static function parseEmbeddings(mixed $json, int $expected, ?int $dimensions): array
    {
        $data = is_array($json) ? ($json['data'] ?? null) : null;
        if (! is_array($data) || count($data) !== $expected) {
            throw new ProviderError('bad_response');
        }
        $out = [];
        foreach ($data as $position => $row) {
            $index = is_array($row) && is_int($row['index'] ?? null) ? $row['index'] : $position;
            $values = is_array($row) ? ($row['embedding'] ?? null) : null;
            if (! is_array($values) || isset($out[$index]) || $index < 0 || $index >= $expected) {
                throw new ProviderError('bad_response');
            }
            try {
                $out[$index] = Vector::normalize($values, $dimensions);
            } catch (\InvalidArgumentException) {
                throw new ProviderError('bad_response');
            }
        }
        ksort($out);
        $first = count($out[0]);
        foreach ($out as $vector) {
            if (count($vector) !== $first) {
                throw new ProviderError('bad_response');
            }
        }

        return array_values($out);
    }

    /** One JSON POST under the configured timeout; maps every failure to a reason code. */
    private function post(string $path, array $body): array
    {
        try {
            $response = Http::withToken(VisualConfig::key($this->config))
                ->acceptJson()->asJson()
                ->connectTimeout(min(5, $this->config['timeout_seconds']))
                ->timeout($this->config['timeout_seconds'])
                ->withoutRedirecting()
                ->post($this->config['base_url'].$path, $body);
        } catch (ConnectionException) {
            throw new ProviderError('timeout');
        } catch (\Throwable) {
            throw new ProviderError('unavailable', 60);
        }
        if (! $response->successful()) {
            throw ProviderError::fromStatus($response->status(), (int) $response->header('Retry-After'));
        }
        if (strlen($response->body()) > 8_000_000) {
            throw new ProviderError('bad_response');
        }
        $json = $response->json();

        return is_array($json) ? $json : throw new ProviderError('bad_response');
    }
}
