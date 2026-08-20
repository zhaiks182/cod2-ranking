<?php

namespace App\Services;

use App\Models\Server;

class Cod2RconClient
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $password,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config('cod2.rcon.host'),
            config('cod2.rcon.port'),
            config('cod2.rcon.password'),
        );
    }

    public static function forServer(Server $server): self
    {
        return new self($server->rcon_host, $server->rcon_port, $server->rcon_password);
    }

    /**
     * Sends an arbitrary rcon command (kick, say, map change, cvar set, ...). Set
     * $wantResult when the server actually prints something back (e.g. status, or a
     * cvar query) — most admin actions (kick, map, say) don't and returning early keeps
     * those fast.
     */
    public function command(string $cmd, bool $wantResult = false): string
    {
        $socket = @fsockopen('udp://'.$this->host, $this->port, $errno, $errstr, 2);

        if (! $socket) {
            return '';
        }

        stream_set_timeout($socket, 2);
        fwrite($socket, "\xff\xff\xff\xff".'rcon "'.$this->password.'" '.$cmd."\n");

        $response = $wantResult ? $this->readAll($socket) : '';

        fclose($socket);

        return preg_replace('/\xff\xff\xff\xffprint\n/', '', $response);
    }

    /**
     * @return array{map: ?string, players: array<int, array{slot:int, score:int, ping:?string, guid:int, name:string}>}|null
     */
    public function status(): ?array
    {
        // UDP has no delivery guarantee — the request or the response can just get
        // dropped once in a while even though the gameserver is perfectly healthy.
        // One quick retry before declaring it unreachable avoids surfacing that as a
        // user-facing "servidor no respondió" for what was really a single lost packet.
        $response = $this->requestStatus();
        if ($response === '') {
            $response = $this->requestStatus();
        }

        if ($response === '') {
            return null;
        }

        return $this->parseStatus($response);
    }

    private function requestStatus(): string
    {
        $socket = @fsockopen('udp://'.$this->host, $this->port, $errno, $errstr, 2);

        if (! $socket) {
            return '';
        }

        stream_set_timeout($socket, 2);
        fwrite($socket, "\xff\xff\xff\xff".'rcon "'.$this->password.'" status'."\n");

        $response = $this->readAll($socket);

        fclose($socket);

        return $response;
    }

    /**
     * A "status" reply with many players doesn't fit in one UDP datagram — CoD2 splits
     * it across several packets, each with its own "\xff\xff\xff\xffprint\n" header.
     * Comparing stream_get_meta_data()'s unread_bytes between reads (the previous
     * approach) isn't reliable here: it only reflects what the OS has already buffered,
     * not whether another packet is still in flight, so it was returning after the
     * first packet and silently truncating the player list on busy servers. Polling
     * with stream_select() gives each subsequent fragment a real window to arrive.
     */
    private function readAll($socket): string
    {
        $response = '';

        while (true) {
            $read = [$socket];
            $write = null;
            $except = null;

            if (! stream_select($read, $write, $except, 0, 300000)) {
                break;
            }

            $chunk = fread($socket, 8192);
            if ($chunk === '' || $chunk === false) {
                break;
            }

            $response .= $chunk;
        }

        return $response;
    }

    private function parseStatus(string $raw): array
    {
        $body = preg_replace('/\xff\xff\xff\xffprint\n/', '', $raw);
        $lines = preg_split('/\r?\n/', trim($body));

        $map = null;
        $players = [];

        foreach ($lines as $line) {
            $line = rtrim($line);

            if (str_starts_with($line, 'map:')) {
                $map = trim(substr($line, 4));

                continue;
            }

            if ($player = $this->parsePlayerRow($line)) {
                $players[] = $player;
            }
        }

        return ['map' => $map, 'players' => $players];
    }

    /**
     * Player rows are column-aligned by padding, but colored names contain their own
     * single spaces, which breaks a naive column split. Anchoring 4 fields from the left
     * (slot, score, ping, guid) and 4 from the right (lastmsg, address, qport, rate) and
     * treating whatever remains in the middle as the name handles that reliably.
     */
    private function parsePlayerRow(string $line): ?array
    {
        $tokens = preg_split('/\s+/', trim($line));

        if (count($tokens) < 8 || ! is_numeric($tokens[0])) {
            return null;
        }

        $count = count($tokens);

        // The engine occasionally omits "ping" entirely for a connected player
        // (seen live 2026-08-19, reproducible across several status polls for the
        // same client) — when that happens, the token right after "score" is
        // really the guid, one field earlier than usual. A real ping is always a
        // small decimal (0-9999); a guid is a full signed 32-bit value, always
        // wider than that, so an oversized number in the ping position is the
        // tell that the field is missing rather than that ping is genuinely huge.
        // Without this, the whole row shifts: guid gets misread as ping, the name
        // gets misread as guid (casting to 0), and the name is lost entirely.
        $pingMissing = strlen(ltrim($tokens[2], '-')) > 4;
        $ping = $pingMissing ? null : $tokens[2];
        $guid = (int) $tokens[$pingMissing ? 2 : 3];
        $nameOffset = $pingMissing ? 3 : 4;

        $name = $this->toUtf8(implode(' ', array_slice($tokens, $nameOffset, $count - $nameOffset - 4)));

        // address is "ip:port" (or a "00000000.0...:0" placeholder for bots) — split off
        // the port from the right so IPv4 dots don't confuse anything.
        $address = $tokens[$count - 3];
        $ip = str_contains($address, ':') ? substr($address, 0, strrpos($address, ':')) : $address;

        return [
            'slot' => (int) $tokens[0],
            'score' => (int) $tokens[1],
            'ping' => $ping,
            'guid' => $guid,
            'name' => $name,
            'ip' => $ip,
        ];
    }

    /**
     * RCON status names carry the same encoding quirk as chat lines (see
     * ParseCod2Log::toUtf8) — accented characters arrive in Windows-1252, not UTF-8.
     * Without this, a currently-connected player with an accented name breaks
     * Cache::put() for the whole status payload (utf8mb4 strict mode rejects the
     * invalid bytes), which took the dashboard down for everyone.
     */
    private function toUtf8(string $s): string
    {
        return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }
}
