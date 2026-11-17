<?php

namespace App\Support;

final class ReportQueryCounter
{
    public int $count = 0;

    public float $sqlMs = 0;

    public function record(float $ms): void
    {
        $this->count++;
        $this->sqlMs += $ms;
    }
}
