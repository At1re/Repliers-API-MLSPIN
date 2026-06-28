<?php
/**
 * Example: fetch one listing by its MLS number.
 *
 *   php examples/get-listing.php 73534976
 */
require __DIR__ . '/../src/RepliersClient.php';

$config   = file_exists(__DIR__ . '/../config.php')
    ? require __DIR__ . '/../config.php'
    : require __DIR__ . '/../config.example.php';
$repliers = new RepliersClient($config['api_key'], $config['base_url']);

$mls = $argv[1] ?? null;
if (!$mls) {
    fwrite(STDERR, "Usage: php examples/get-listing.php <mlsNumber>\n");
    exit(1);
}

try {
    // Pass `true` to also pull the raw MLS payload — needed for the
    // architectural style (Colonial/Cape/Ranch), which lives nowhere else.
    $data    = $repliers->getListing($mls, true);
    $listing = $data['listing'] ?? $data; // some responses wrap it, some don't
    $addr    = $listing['address'] ?? [];
    $details = $listing['details'] ?? [];

    echo "Price:   " . RepliersClient::formatPrice($listing) . "\n";
    echo "Status:  " . RepliersClient::statusBadge($listing) . "\n";
    echo "Address: " . trim(($addr['streetNumber'] ?? '') . ' ' . ($addr['streetName'] ?? '')
        . ', ' . ($addr['city'] ?? '') . ' ' . ($addr['state'] ?? '')) . "\n";
    echo "Beds:    " . ($details['numBedrooms'] ?? '?') . "\n";
    echo "Baths:   " . ($details['numBathrooms'] ?? '?') . "\n";
    echo "Sqft:    " . ($details['sqft'] ?? '?') . "\n";
    echo "Style:   " . (RepliersClient::architecturalStyle($listing) ?: '(not provided)') . "\n";
    echo "Tour:    " . (RepliersClient::virtualTourUrl($listing) ?: '(none)') . "\n";
    echo "Photo:   " . RepliersClient::photoUrl($listing) . "\n";
} catch (RuntimeException $e) {
    fwrite(STDERR, "Error: {$e->getMessage()}\n");
    exit(1);
}
