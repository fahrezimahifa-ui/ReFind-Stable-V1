<?php

namespace App\Visual;

/** The swappable photo-search engine (InferHub-backed, or the deterministic fake used by tests). */
interface Provider
{
    /**
     * Identifies everything that changes the vectors (provider, strategy, models, dimensions, preprocessing).
     * The index and the committed calibration receipt are only valid for the same key.
     */
    public function modelKey(): string;

    /**
     * Embeds prepared JPEGs (Preprocess::prepare output). Returns one unit vector per photo, in order.
     *
     * @param  list<string>  $jpegs
     * @return list<array>
     *
     * @throws ProviderError
     */
    public function embed(array $jpegs): array;
}
