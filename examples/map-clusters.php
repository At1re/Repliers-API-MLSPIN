<?php
/**
 * Example: get map cluster bubbles ("47 listings in this area") for a region.
 *
 *   php examples/map-clusters.php
 *
 * Clusters are how an interactive map shows counts when zoomed out, instead of
 * thousands of individual pins.
 */
require __DIR__ . '/../src/RepliersClient.php';

$config   = file_exists(__DIR__ . '/../config.php')
    ? require __DIR__ . '/../config.php'
    : require __DIR__ . '/../config.example.php';
$repliers = new RepliersClient($config['api_key'], $config['base_url']);

try {
    // Limit to a bounding box around Essex County, MA (a viewport polygon).
    // Format: [[ [lng,lat], [lng,lat], ... ]] — first point repeated at the end.
    $polygon = json_encode([[
        [-71.40, 42.40],
        [-70.60, 42.40],
        [-70.60, 42.90],
        [-71.40, 42.90],
        [-71.40, 42.40],
    ]]);

    $response = $repliers->getClusters(['map' => $polygon], 9);
    $clusters = $response['aggregates']['map']['clusters'] ?? [];

    printf("%d clusters in view (total %s listings):\n\n",
        count($clusters),
        $response['count'] ?? '?'
    );

    foreach ($clusters as $c) {
        printf("  %5d listings near (%.4f, %.4f)\n",
            $c['count'] ?? 0,
            $c['location']['latitude']  ?? 0,
            $c['location']['longitude'] ?? 0
        );
    }
} catch (RuntimeException $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
}
