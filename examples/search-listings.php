<?php
/**
 * Example: search for listings and print the first page.
 *
 *   php examples/search-listings.php
 */
require __DIR__ . '/../src/RepliersClient.php';

$config   = file_exists(__DIR__ . '/../config.php')
    ? require __DIR__ . '/../config.php'
    : require __DIR__ . '/../config.example.php';
$repliers = new RepliersClient($config['api_key'], $config['base_url']);

try {
    // Single-family homes and condos in Andover MA, $500k+, 3+ beds.
    $results = $repliers->getListings([
        'city'         => 'Andover',
        'propertyType' => 'Single Family', // translated automatically — see RepliersClient
        'minPrice'     => 500000,
        'minBedrooms'  => 3,
    ], 1, 10);

    printf("Found %s listings (showing %d):\n\n",
        $results['count'] ?? '?',
        count($results['listings'] ?? [])
    );

    foreach ($results['listings'] ?? [] as $listing) {
        $addr = $listing['address'] ?? [];
        printf("  %-12s  %s %s, %s  [MLS# %s]\n",
            RepliersClient::formatPrice($listing),
            $addr['streetNumber'] ?? '',
            $addr['streetName']   ?? '',
            $addr['city']         ?? '',
            $listing['mlsNumber']  ?? ''
        );
    }
} catch (RuntimeException $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
}
