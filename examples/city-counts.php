<?php
/**
 * Example: active-listing counts per city — the data behind a town
 * autocomplete like "Andover (88 listings)". One API call returns them all.
 *
 *   php examples/city-counts.php          # all of MA
 *   php examples/city-counts.php Essex     # just Essex County
 */
require __DIR__ . '/../src/RepliersClient.php';

$config   = file_exists(__DIR__ . '/../config.php')
    ? require __DIR__ . '/../config.php'
    : require __DIR__ . '/../config.example.php';
$repliers = new RepliersClient($config['api_key'], $config['base_url']);

$area = $argv[1] ?? null; // optional county, e.g. "Essex"

try {
    $params = $area ? ['area' => $area] : [];
    $cities = $repliers->getCityCounts($params); // ['Andover' => 88, ...]

    arsort($cities); // busiest towns first
    printf("%d towns%s:\n\n", count($cities), $area ? " in {$area} County" : '');
    foreach (array_slice($cities, 0, 25, true) as $city => $count) {
        printf("  %-22s %d listings\n", $city, $count);
    }
} catch (RuntimeException $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
}
