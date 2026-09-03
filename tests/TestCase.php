<?php

namespace Tests;

use App\Support\PlayerRankCalculator;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // PlayerRankCalculator::seasonSeedScore() memoiza por server_id
        // dentro del proceso PHP (2026-09-02, fix de performance) -- sin
        // esto, un test que corre después de otro en el mismo proceso
        // (`php artisan test` no reinicia el proceso por test) podía leer
        // resultados cacheados de una temporada/server de un test anterior,
        // ya que RefreshDatabase no limpia propiedades estáticas de PHP.
        PlayerRankCalculator::clearSeasonSeedCache();
    }
}
