<?php
/**
 * RepliersClient — a small, dependency-free PHP wrapper for the Repliers.io
 * real-estate API (https://docs.repliers.io).
 *
 * Repliers serves MLS listing data. This client was extracted from a live
 * WordPress site that uses the MLSPIN board (Massachusetts), so it also
 * encodes the MLSPIN field quirks that took real debugging to discover —
 * the property-type translation, where architectural style and virtual tours
 * actually live, how pending sales are flagged, and so on. See the README's
 * "MLSPIN field quirks" table and normalizePropertyParams() below.
 *
 * No framework required. Plain PHP 7.4+ and curl.
 *
 * Usage:
 *   $repliers = new RepliersClient('YOUR_REPLIERS_API_KEY');
 *   $results  = $repliers->getListings(['city' => 'Andover', 'minPrice' => 500000]);
 *   foreach ($results['listings'] as $listing) { ... }
 */
class RepliersClient
{
    private string $apiKey;
    private string $baseUrl;
    private int    $timeout;

    public function __construct(string $apiKey, string $baseUrl = 'https://api.repliers.io', int $timeout = 15)
    {
        $this->apiKey  = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
    }

    /**
     * Fetch a batch of listings.
     *
     * Sensible defaults are applied (active, for sale, with photos) unless the
     * caller overrides them. Pass any Repliers query params:
     *   city, minPrice, maxPrice, minBedrooms, minBaths, minSqft,
     *   propertyType, class, type ('sale' | 'lease'), area, sortBy, status, ...
     *
     * Note: up to ~100 listings come back per page; use $page to walk further.
     *
     * @param array $params   Search filters
     * @param int   $page      Page number (1-based)
     * @param int   $perPage   Results per page (max ~100)
     * @return array           Decoded API response (keys: listings, count, numPages, ...)
     * @throws RuntimeException on network / HTTP / JSON failure
     */
    public function getListings(array $params = [], int $page = 1, int $perPage = 12): array
    {
        $params = $this->normalizePropertyParams($params);

        // Figure out whether this is a commercial/land/multi-family request —
        // those listings are often leases and have their own class, so the
        // for-sale + residential defaults must NOT be forced on them.
        $commercialTypes = ['commercial', 'multi-family', 'land'];
        $requestedClass  = array_map('strtolower', (array) ($params['class'] ?? []));
        $requestedTypes  = array_map('strtolower', (array) ($params['propertyType'] ?? []));
        $isCommercial    = array_intersect($requestedClass, $commercialTypes)
                        || array_intersect($requestedTypes, $commercialTypes);

        $defaults = [
            'status'         => 'A',                      // A = active
            'resultsPerPage' => $perPage,
            'pageNum'        => $page,
            'sortBy'         => 'statusAscListDateDesc',
            'hasImages'      => 'true',
            'state'          => 'MA',                      // change for your market
        ];
        if (!$isCommercial) {
            $defaults['type'] = 'sale';
        }
        if (!isset($params['class']) && !$isCommercial) {
            $defaults['class'] = ['residential', 'condo'];
        }

        $query = array_merge($defaults, $params);

        return $this->request('/listings', $query);
    }

    /**
     * Fetch one listing by its MLS number.
     *
     * @param bool $includeRaw  Also fetch the raw MLS payload. Some fields —
     *   notably ArchitecturalStyle — live ONLY in `raw`, which is NOT returned
     *   by default. When true this makes a second lightweight call
     *   (?fields=raw) and merges it under ['raw']. Non-fatal if it fails.
     * @throws RuntimeException on failure of the primary call
     */
    public function getListing(string $mlsNumber, bool $includeRaw = false): array
    {
        $listing = $this->request('/listings/' . rawurlencode($mlsNumber), []);

        if ($includeRaw) {
            try {
                $rawResp = $this->request('/listings/' . rawurlencode($mlsNumber), ['fields' => 'raw']);
                if (!empty($rawResp['raw'])) {
                    $listing['raw'] = $rawResp['raw'];
                }
            } catch (RuntimeException $e) {
                // Style is a nice-to-have — return the listing without raw.
            }
        }

        return $listing;
    }

    /**
     * Fetch several listings at once by MLS number, in ONE call. Great for a
     * "saved/favorites" view. Returns the array of listing arrays (any status).
     */
    public function getListingsByMls(array $mlsNumbers): array
    {
        $mlsNumbers = array_values(array_filter(array_map('strval', $mlsNumbers)));
        if (!$mlsNumbers) {
            return [];
        }
        $resp = $this->request('/listings', [
            'resultsPerPage' => count($mlsNumbers),
            'mlsNumber'      => $mlsNumbers,
        ]);
        return $resp['listings'] ?? [];
    }

    /**
     * Map cluster aggregates ("47 listings in this area" bubbles).
     *
     * Pass the same filters as getListings(), plus optionally a `map` polygon
     * (a JSON string of [[ [lng,lat], ... ]]) to limit clusters to a viewport.
     * The bubbles come back in $response['aggregates']['map']['clusters'],
     * each with a count, a location (lat/lng), and its own bounds.
     *
     * @param int $zoom  Map zoom level — drives cluster granularity (5–20)
     * @throws RuntimeException on failure
     */
    public function getClusters(array $params = [], int $zoom = 10): array
    {
        $params = array_merge($this->normalizePropertyParams($params), [
            'cluster'          => 'true',
            'clusterPrecision' => max(5, min(20, $zoom + 2)),
            'clusterLimit'     => 200,
            'listings'         => 'false', // we only want the aggregates, not the rows
            'status'           => 'A',
            'state'            => 'MA',
        ]);

        return $this->request('/listings', $params);
    }

    /**
     * Active-listing counts per city — perfect for an autocomplete that shows
     * "Andover (88 listings)". One aggregates call returns every city + count.
     * Pass filters to scope it, e.g. ['area' => 'Essex'] for a single county.
     *
     * @return array  Map of city => count, e.g. ['Andover' => 88, 'Beverly' => 90, ...]
     * @throws RuntimeException on failure
     */
    public function getCityCounts(array $params = []): array
    {
        $resp = $this->request('/listings', array_merge([
            'status'     => 'A',
            'state'      => 'MA',
            'type'       => 'sale',
            'listings'   => 'false',
            'aggregates' => 'address.city',
        ], $params));

        return $resp['aggregates']['address']['city'] ?? [];
    }

    /**
     * Translate friendly property-type names into MLSPIN's actual schema.
     *
     * WHY THIS EXISTS — the gotcha that cost us a day of debugging:
     * MLSPIN has no "Single Family" property type. Its only propertyType values
     * are Residential / Residential Income / Land / Commercial Sale / Business
     * Opportunity. Houses vs condos are told apart by a SEPARATE field called
     * "class" (residential / condo / commercial). Rentals use "Residential
     * Lease". Asking the API for propertyType="Single Family" matches NOTHING
     * and silently returns zero results.
     *
     * So this maps the human-friendly options a UI would show:
     *   "Single Family" -> class: residential + propertyType: Residential
     *   "Condominium"   -> class: condo
     *   "Multi-Family"  -> class: residential + propertyType: Residential Income
     *   "Land"          -> propertyType: Land
     *   "Commercial"    -> class: commercial
     * When type=lease, the Residential propertyTypes flip to "Residential Lease".
     *
     * Anything not recognized is passed through untouched (so you can still send
     * raw MLSPIN values directly).
     */
    public function normalizePropertyParams(array $params): array
    {
        $types = (array) ($params['propertyType'] ?? []);
        if (!$types) {
            return $params;
        }

        $tokens      = [];
        $passthrough = [];
        foreach ($types as $t) {
            $k = strtolower(trim($t));
            if (in_array($k, ['single family', 'single family residence', 'house'], true)) {
                $tokens['sf'] = true;
            } elseif (in_array($k, ['condominium', 'condo'], true)) {
                $tokens['condo'] = true;
            } elseif (in_array($k, ['multi-family', 'multi family', 'multifamily'], true)) {
                $tokens['multi'] = true;
            } elseif (in_array($k, ['land', 'land / lot', 'lot'], true)) {
                $tokens['land'] = true;
            } elseif (in_array($k, ['commercial', 'commercial sale'], true)) {
                $tokens['commercial'] = true;
            } else {
                $passthrough[] = $t; // already a real MLSPIN value
            }
        }

        if (!$tokens) {
            return $params;
        }

        $isLease  = 'lease' === strtolower((string) ($params['type'] ?? ''));
        $resPtype = $isLease ? 'Residential Lease' : 'Residential';

        unset($params['propertyType']);
        $class = [];
        $ptype = $passthrough;

        if (!empty($tokens['sf']))         { $class[] = 'residential'; $ptype[] = $resPtype; }
        if (!empty($tokens['condo']))      { $class[] = 'condo';       $ptype[] = $resPtype; }
        if (!empty($tokens['multi']))      { $class[] = 'residential'; $ptype[] = $isLease ? $resPtype : 'Residential Income'; }
        if (!empty($tokens['land']))       { $ptype[] = 'Land'; }
        if (!empty($tokens['commercial'])) { $class[] = 'commercial'; }

        if ($class && !isset($params['class'])) {
            $params['class'] = array_values(array_unique($class));
        }
        if ($ptype) {
            $params['propertyType'] = array_values(array_unique($ptype));
        }

        return $params;
    }

    // ── Display helpers ───────────────────────────────────────────────────────
    // Each takes a single listing array and pulls a value out of the (sometimes
    // surprising) place MLSPIN stores it.

    /** Format a listing's price, e.g. "$659,900". */
    public static function formatPrice(array $listing): string
    {
        $price = $listing['listPrice'] ?? $listing['price'] ?? 0;
        return $price ? '$' . number_format((float) $price) : 'Price N/A';
    }

    /** First photo URL for a listing (Repliers returns relative CDN paths). */
    public static function photoUrl(array $listing, int $index = 0): string
    {
        return self::photoUrls($listing, $index + 1)[$index] ?? '';
    }

    /** Up to $limit photo URLs (CDN-prefixed), e.g. for a card carousel. */
    public static function photoUrls(array $listing, int $limit = 5): array
    {
        $out    = [];
        $photos = $listing['images'] ?? $listing['photos'] ?? [];
        foreach (array_slice($photos, 0, $limit) as $p) {
            $src = is_array($p) ? ($p['url'] ?? '') : $p;
            if ($src && !preg_match('#^https?://#', $src)) {
                $src = 'https://cdn.repliers.io/' . $src;
            }
            if ($src) {
                $out[] = $src;
            }
        }
        return $out;
    }

    /**
     * Architectural style (Colonial / Cape / Ranch …).
     * GOTCHA: MLSPIN keeps this ONLY in the raw payload, as an array — so fetch
     * the listing with getListing($mls, true) first or this is always empty.
     * details.style is the property SUBTYPE ("Single Family Residence"), not the
     * style. Vague values like "Other (See Remarks)" are skipped.
     */
    public static function architecturalStyle(array $listing): string
    {
        foreach ((array) ($listing['raw']['ArchitecturalStyle'] ?? []) as $s) {
            $s = trim((string) $s);
            if ($s !== '' && stripos($s, 'other') === false && stripos($s, 'see remark') === false) {
                return $s;
            }
        }
        return '';
    }

    /**
     * Virtual-tour URL, if the listing has one.
     * GOTCHA: it's in a ROOT-level `virtualTours` array, NOT
     * details.virtualTourUrl (which is almost always empty).
     */
    public static function virtualTourUrl(array $listing): string
    {
        return $listing['virtualTours'][0]['url']
            ?? $listing['details']['virtualTourUrl']
            ?? '';
    }

    /**
     * A display badge for a listing's status: "Under Contract", "New", or
     * "Active".
     * GOTCHA: MLSPIN keeps pending sales as status=A (active) with lastStatus
     * "Sc" (under agreement) or "Lc" (lease pending) — so they still show up in
     * active results and need labelling. "New" = on market 7 days or fewer.
     */
    public static function statusBadge(array $listing): string
    {
        if (in_array($listing['lastStatus'] ?? '', ['Sc', 'Lc'], true)) {
            return 'Under Contract';
        }
        $dom = $listing['daysOnMarket'] ?? '';
        if ($dom !== '' && (int) $dom <= 7) {
            return 'New';
        }
        return 'Active';
    }

    // ── HTTP plumbing ────────────────────────────────────────────────────────

    /**
     * Make a GET request to the Repliers API and return the decoded JSON.
     *
     * Repliers wants some params in special shapes: `class` and `propertyType`
     * as repeated key[]=a&key[]=b, `mlsNumber` as repeated key=a&key=b (no []),
     * and the `map` viewport as a raw JSON string — so the query string is built
     * by hand rather than with http_build_query alone.
     *
     * @throws RuntimeException on network, HTTP, or JSON error
     */
    private function request(string $path, array $query): array
    {
        // Pull out the params that need special encoding
        $classValues = (array) ($query['class'] ?? []);
        $typeValues  = (array) ($query['propertyType'] ?? []);
        $mlsValues   = (array) ($query['mlsNumber'] ?? []);
        $mapPolygon  = $query['map'] ?? '';
        unset($query['class'], $query['propertyType'], $query['mlsNumber'], $query['map']);

        $url = $this->baseUrl . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }
        $sep = $query ? '&' : '?';

        foreach ($classValues as $c) {
            $url .= $sep . 'class[]=' . rawurlencode($c);
            $sep  = '&';
        }
        foreach ($typeValues as $t) {
            $url .= $sep . 'propertyType[]=' . rawurlencode($t);
            $sep  = '&';
        }
        foreach ($mlsValues as $m) {
            $url .= $sep . 'mlsNumber=' . rawurlencode($m);
            $sep  = '&';
        }
        if ($mapPolygon) {
            $url .= $sep . 'map=' . rawurlencode($mapPolygon);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => [
                'REPLIERS-API-KEY: ' . $this->apiKey,
                'Accept: application/json',
            ],
        ]);

        $body  = curl_exec($ch);
        $errNo = curl_errno($ch);
        $err   = curl_error($ch);
        $code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errNo) {
            throw new RuntimeException("Network error contacting Repliers: {$err}");
        }
        if ($code !== 200) {
            throw new RuntimeException("Repliers API returned HTTP {$code}.");
        }

        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Could not parse Repliers API response as JSON.');
        }

        return $data;
    }
}
