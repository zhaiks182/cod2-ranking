<?php

namespace Tests\Feature\Support;

use App\Support\ServiceUptime;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Solo el formateador. `startedAt()` corre `systemctl` de verdad contra el
 * sistema, asi que no se puede ejercitar en el entorno de tests -- mismo
 * criterio que el resto del modulo de consola (kick/ban/RCON tampoco tienen
 * tests de feature, ver "Botón Notificar Discord" en CLAUDE.md).
 */
class ServiceUptimeTest extends TestCase
{
    public function test_it_formats_days_hours_and_minutes(): void
    {
        $now = Carbon::parse('2026-09-05 18:30:00');
        $since = Carbon::parse('2026-09-01 15:09:00');

        $this->assertSame('4d 3h 21m', ServiceUptime::format($since, $now));
    }

    /** Sin dias no muestra "0d" adelante. */
    public function test_it_omits_days_when_there_are_none(): void
    {
        $now = Carbon::parse('2026-09-05 18:30:00');

        $this->assertSame('3h 21m', ServiceUptime::format(Carbon::parse('2026-09-05 15:09:00'), $now));
    }

    /** Un servicio recien reiniciado muestra solo minutos, no "0d 0h 12m". */
    public function test_it_shows_only_minutes_for_a_freshly_started_service(): void
    {
        $now = Carbon::parse('2026-09-05 18:30:00');

        $this->assertSame('12m', ServiceUptime::format(Carbon::parse('2026-09-05 18:18:00'), $now));
        $this->assertSame('0m', ServiceUptime::format(Carbon::parse('2026-09-05 18:29:30'), $now));
    }
}
