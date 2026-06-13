# Repliers API — Minimal PHP Client

A small, dependency-free PHP wrapper for the [Repliers.io](https://docs.repliers.io)
real-estate (MLS) API. No framework — just plain PHP 7.4+ and curl.

This was extracted from a production WordPress site running on the **MLSPIN**
board (Massachusetts), so it includes the property-type translation that MLSPIN
data quietly requires. If you're integrating Repliers with an MLSPIN feed, that
one function will save you a day of debugging (see the gotcha below).

## What's here

```
repliers-api-example/
├── src/RepliersClient.php     ← the client (this is the whole library)
├── examples/
│   ├── search-listings.php    ← search and list results
│   ├── get-listing.php        ← fetch one listing by MLS number
│   └── map-clusters.php       ← map "bubble" counts for a region
├── config.example.php         ← copy to config.php and add your key
└── .gitignore                 ← keeps config.php (your key) out of git
```

## Setup

```bash
cp config.example.php config.php      # then paste your real key into config.php
php examples/search-listings.php
```

Get an API key from [repliers.com](https://repliers.com). Authentication is a
single request header: `REPLIERS-API-KEY: <your key>`.

## Quick start

```php
require 'src/RepliersClient.php';

$repliers = new RepliersClient('YOUR_REPLIERS_API_KEY');

$results = $repliers->getListings([
    'city'         => 'Andover',
    'propertyType' => 'Single Family',
    'minPrice'     => 500000,
    'minBedrooms'  => 3,
]);

foreach ($results['listings'] as $listing) {
    echo RepliersClient::formatPrice($listing) . ' — '
       . $listing['address']['city'] . "\n";
}
```

## API surface

| Method | What it does |
|---|---|
| `getListings($params, $page, $perPage)` | Search listings with filters |
| `getListing($mlsNumber)` | Fetch one listing by MLS number |
| `getClusters($params, $zoom)` | Map cluster counts for a viewport |
| `normalizePropertyParams($params)` | The MLSPIN translation (used internally) |
| `RepliersClient::formatPrice($listing)` | `"$659,900"` |
| `RepliersClient::photoUrl($listing)` | First photo URL (handles the CDN prefix) |

Common `getListings` filters: `city`, `minPrice`, `maxPrice`, `minBedrooms`,
`minBaths`, `minSqft`, `propertyType`, `type` (`sale` | `lease`), `sortBy`,
`status`, `state`.

## The MLSPIN gotcha (why `normalizePropertyParams` exists)

MLSPIN has **no "Single Family" property type**. Its only `propertyType` values
are `Residential`, `Residential Income`, `Land`, `Commercial Sale`, and
`Business Opportunity`. Houses vs. condos are told apart by a *separate* field
called `class` (`residential` / `condo` / `commercial`). Rentals use
`Residential Lease`.

So a natural-looking request like `propertyType=Single Family` matches **nothing**
and silently returns zero results. `normalizePropertyParams()` maps the
human-friendly names you'd show in a UI onto what the API actually expects:

| You ask for | Sent to the API |
|---|---|
| Single Family | `class=residential` + `propertyType=Residential` |
| Condominium | `class=condo` |
| Multi-Family | `class=residential` + `propertyType=Residential Income` |
| Land | `propertyType=Land` |
| Commercial | `class=commercial` |

(When `type=lease`, the residential types flip to `Residential Lease`.)

## Notes

- `class` and `propertyType` are sent as repeated params (`class[]=a&class[]=b`),
  and the map viewport as a raw JSON `map=` string — `RepliersClient` builds the
  query string accordingly.
- The defaults assume Massachusetts (`state=MA`); change that in
  `getListings()` / `getClusters()` for your market.
- This client has no caching. The original site cached responses for a few
  minutes to avoid hammering the API — add that in your own layer if you make
  high-traffic calls.

## License

MIT — do whatever you like.
