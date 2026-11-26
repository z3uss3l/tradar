<?php
declare(strict_types=1);

require_once __DIR__ . '/../events.php';

$engine = new QuantTrendEventsEngine(__DIR__ . '/../event_catalog.json');
$health = $engine->health();
assert($health['ok'] === true);
assert($health['version'] === 'quanttrend-events/12.0.7');

$now = strtotime('2026-11-26T04:00:00Z');
$live = $engine->classifyHeadline([
    'title' => 'NVIDIA wins major data center contract awarded today',
    'published_at' => '2026-11-26T02:00:00Z',
], ['decision_ts' => $now, 'decision_mode' => 'live_decision']);
assert($live['decision'] === 'relevant');
assert($live['first_seen_server_observed'] === true);

$reclass = $engine->classifyHeadline([
    'title' => 'Apple announces share buyback program',
    'published_at' => '2026-10-10T10:00:00Z',
    'first_seen_at' => '2026-10-10T10:05:00Z',
    'first_seen_server_observed' => false,
], ['decision_ts' => $now, 'reclassify' => true]);
assert($reclass['first_seen_at'] === '2026-10-10T10:05:00Z');
assert($reclass['first_seen_server_observed'] === false);

assert(SafeHttpClient::isPublicIp('127.0.0.1') === false);
assert(SafeHttpClient::isPublicIp('169.254.169.254') === false);
assert(SafeHttpClient::isPublicIp('8.8.8.8') === true);

echo "ALL PHP TESTS PASSED\n";
