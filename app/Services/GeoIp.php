<?php

namespace App\Services;

use GeoIp2\Database\Reader;
use Throwable;

class GeoIp
{
    private static ?Reader $reader = null;

    private static bool $unavailable = false;

    /** @return array{code: string, name: string}|null */
    public static function countryFor(?string $ip): ?array
    {
        if (! $ip || self::$unavailable || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        $reader = self::reader();
        if (! $reader) {
            return null;
        }

        try {
            $record = $reader->country($ip);
            $code = $record->country->isoCode;

            return [
                'code' => $code,
                'name' => self::NAME_OVERRIDES[$code] ?? $record->country->names['es'] ?? $record->country->name ?? $code,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /** Shorter/more familiar names than the ones in the mmdb's Spanish locale. */
    private const NAME_OVERRIDES = [
        'US' => 'USA',
    ];

    /** Converts an ISO 3166-1 alpha-2 code ("AR") into its flag emoji ("🇦🇷"). */
    public static function flagEmoji(string $isoCode): string
    {
        $isoCode = strtoupper($isoCode);
        if (strlen($isoCode) !== 2) {
            return '';
        }

        return mb_chr(127397 + ord($isoCode[0])).mb_chr(127397 + ord($isoCode[1]));
    }

    /**
     * Flag emoji (flagEmoji()) render fine on iOS/Android/macOS but Windows has no
     * flag glyphs for the regional-indicator-symbol pairs — Chrome/Edge on Windows
     * falls back to showing the raw two-letter code as text instead of a flag.
     * Renders an actual flag image (flagcdn.com, free/no-key SVG flags) so it looks
     * the same on every platform. Returns raw HTML — use with {!! !!}, not {{ }}.
     *
     * Fixed width AND height (not just height with width:auto) — real flags have
     * different official aspect ratios (US ~1.9:1, Mexico ~1.75:1, Colombia ~1.5:1),
     * so height-only sizing made wider flags visibly bigger than others. object-cover
     * crops each flag to fill the same box so every country looks the same size.
     */
    public static function flagIconHtml(string $isoCode, int $width = 20, int $height = 14): string
    {
        $isoCode = strtolower($isoCode);
        if (strlen($isoCode) !== 2) {
            return '';
        }

        return sprintf(
            '<img src="https://flagcdn.com/%s.svg" alt="%s" class="inline-block align-middle rounded-[1px] object-cover" style="width:%dpx;height:%dpx" loading="lazy">',
            e($isoCode), e(strtoupper($isoCode)), $width, $height
        );
    }

    private static function reader(): ?Reader
    {
        if (self::$reader) {
            return self::$reader;
        }

        $path = storage_path('app/geoip/country.mmdb');
        if (! is_file($path)) {
            self::$unavailable = true;

            return null;
        }

        return self::$reader = new Reader($path);
    }
}
