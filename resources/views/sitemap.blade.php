{{-- The XML declaration is emitted by the controller, not here, so that this
     file contains no construct a Blade compiler or an editor has opinions about. --}}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($static as $name)
    <url>
        <loc>{{ route($name) }}</loc>
    </url>
@endforeach
@foreach ($products as $entry)
    <url>
        <loc>{{ $entry['loc'] }}</loc>
        <lastmod>{{ $entry['lastmod'] }}</lastmod>
    </url>
@endforeach
@foreach ($auctions as $entry)
    <url>
        <loc>{{ $entry['loc'] }}</loc>
        <lastmod>{{ $entry['lastmod'] }}</lastmod>
    </url>
@endforeach
@foreach ($posts as $entry)
    <url>
        <loc>{{ $entry['loc'] }}</loc>
        <lastmod>{{ $entry['lastmod'] }}</lastmod>
    </url>
@endforeach
@foreach ($notes as $note)
    {{-- A comment, so a truncated map never reads as a complete one. Kept free
         of a double hyphen, which XML does not allow inside a comment. --}}
    <!-- {{ $note }} -->
@endforeach
</urlset>
