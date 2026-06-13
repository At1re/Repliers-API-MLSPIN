<?php
/**
 * RepliersClient — a small, dependency-free PHP wrapper for the Repliers.io
 * real-estate API (https://docs.repliers.io).
 *
 * Repliers serves MLS listing data. This client was extracted from a live
 * WordPress site that uses the MLSPIN board (Massachusetts), so it also
 * includes the property-type translation that MLSPIN data requires — see
 * normalizePropertyParams() below, which is the single most useful thing here.
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
     *   propertyType, class, type ('sale' | 'lease'), sortBy, status, ...
     *
     * @param array $params   Search filters
     * @param int   $page      Page number (1-based)
     * @param int   $perPage   Results per page
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
     * @throws RuntimeException on failure
     */
    public function getListing(string $mlsNumber): array
    {
        return $this->request('/listings/' . rawurlencode($mlsNumber), []);
    }

    /**
     * Fetch map cluster aggregates ("47 listings in this area" bubbles).
     *
     * Pass the same filters as getListings(), plus optionally a `map` polygon
     * (a JSON string of [[ [lng,lat], ... ]]) to limit clusters to a viewport.
     * Returns the raw response; the bubbles live in
     * $response['aggregates']['map']['clusters'].
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

    // ── Small display helpers ────────────────────────────────────────────────

    /** Format a listing's price, e.g. "$659,900". */
    public static function formatPrice(array $listing): string
    {
        $price = $listing['listPrice'] ?? $listing['price'] ?? 0;
        return $price ? '$' . number_format((float) $price) : 'Price N/A';
    }

    /** First photo URL for a listing (Repliers returns relative CDN paths). */
    public static function photoUrl(array $listing, int $index = 0): string
    {
        $cdn    = 'https://cdn.repliers.io/';
        $photos = $listing['images'] ?? $listing['photos'] ?? [];
        if (empty($photos[$index])) {
            return '';
        }
        $src = is_array($photos[$index]) ? ($photos[$index]['url'] ?? '') : $photos[$index];
        if ($src && !preg_match('#^https?://#', $src)) {
            $src = $cdn . $src;
        }
        return $src;
    }

    // ── HTTP plumbing ────────────────────────────────────────────────────────

    /**
     * Make a GET request to the Repliers API and return the decoded JSON.
     *
     * Repliers expects array params in the form key[]=a&key[]=b (for `class`
     * and `propertyType`), and the map viewport as a raw JSON string — so the
     * query string is built by hand rather than with http_build_query alone.
     *
     * @throws RuntimeException on network, HTTP, or JSON error
     */
    private function request(string $path, array $query): array
    {
        // Pull out the params that need special encoding
        $classValues = (array) ($query['class'] ?? []);
        $typeValues  = (array) ($query['propertyType'] ?? []);
        $mapPolygon  = $query['map'] ?? '';
        unset($query['class'], $query['propertyType'], $query['map']);

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
