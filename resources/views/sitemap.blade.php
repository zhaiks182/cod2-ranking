<?php echo '<?xml version="1.0" encoding="UTF-8"?>'; ?>

<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($staticRoutes as $r)
    <url>
        <loc>{{ route($r['route']) }}</loc>
        <changefreq>{{ $r['changefreq'] }}</changefreq>
        <priority>{{ $r['priority'] }}</priority>
    </url>
@endforeach
@foreach ($matches as $match)
    <url>
        <loc>{{ route('matches.show', $match) }}</loc>
        <lastmod>{{ $match->updated_at->toAtomString() }}</lastmod>
        <changefreq>monthly</changefreq>
        <priority>0.6</priority>
    </url>
@endforeach
@foreach ($players as $player)
    <url>
        <loc>{{ route('players.show', $player) }}</loc>
        <lastmod>{{ $player->updated_at->toAtomString() }}</lastmod>
        <changefreq>daily</changefreq>
        <priority>0.7</priority>
    </url>
@endforeach
</urlset>
