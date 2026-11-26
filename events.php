<?php
declare(strict_types=1);

require_once __DIR__ . '/relay.php';

final class QuantTrendEventsEngine
{
    public const API_VERSION = 'quanttrend-events/12.0.7';
    public const LEGACY_V11_3_HASH = 'ffe709cd0adc3f44a24ae3c052a1be634e2e06a6adba82c4e6fc3015cebf8379';

    private string $catalogPath;
    private array $catalog;
    private string $catalogHash;

    public function __construct(string $catalogPath)
    {
        if (!is_file($catalogPath)) {
            throw new RuntimeException('event_catalog.json nicht gefunden.');
        }
        $raw = (string)file_get_contents($catalogPath);
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || empty($decoded['events'])) {
            throw new RuntimeException('Ungültiger Ereigniskatalog.');
        }
        $this->catalogPath = $catalogPath;
        $this->catalog = $decoded;
        $this->catalogHash = hash('sha256', $raw);
    }

    public function getCatalogHash(): string
    {
        return $this->catalogHash;
    }

    public function getCatalog(): array
    {
        return $this->catalog;
    }

    public function isCatalogHashCompatible(?string $hash): bool
    {
        if ($hash === null || trim($hash) === '') {
            return false;
        }
        $h = strtolower(trim($hash));
        $allowed = array_map('strtolower', array_merge(
            [$this->catalogHash, self::LEGACY_V11_3_HASH],
            $this->catalog['compatible_catalog_sha256'] ?? []
        ));
        return in_array($h, $allowed, true);
    }

    public function health(): array
    {
        return [
            'ok' => true,
            'version' => self::API_VERSION,
            'catalog_version' => (string)($this->catalog['version'] ?? '12.0.7'),
            'catalog_sha256' => $this->catalogHash,
            'compatible_catalog_sha256' => $this->catalog['compatible_catalog_sha256'] ?? [],
            'rules' => count($this->catalog['events'] ?? []),
            'issuers' => count($this->catalog['issuers'] ?? []),
            'capabilities' => [
                'health', 'catalog', 'analyze', 'reclassify',
                'matrix', 'study', 'analogs', 'knn', 'graph',
                'paper_event_guard', 'block_bootstrap_holm'
            ],
            'storage' => 'keine zusätzliche Datenbank; Katalog in JSON, Ergebnisse in QuantTrend-Browserhistorie',
            'external_fetch' => false,
            'point_in_time' => true,
            'graph_paths' => true,
            'android_compatible' => true,
        ];
    }

    private static function parseStrictIso(?string $value, string $field, bool $allowEmpty = false): ?int
    {
        if ($value === null || trim($value) === '') {
            if ($allowEmpty) {
                return null;
            }
            throw new InvalidArgumentException("Fehlender Zeitstempel für {$field}.");
        }
        $ts = strtotime($value);
        if ($ts === false) {
            throw new InvalidArgumentException("Ungültiger Zeitstempel für {$field}: {$value}");
        }
        return $ts;
    }

    public function classifyHeadline(array $item, array $opts = []): array
    {
        $nowTs = isset($opts['decision_ts']) && is_int($opts['decision_ts']) ? $opts['decision_ts'] : time();
        $mode = ($opts['decision_mode'] ?? 'live_decision') === 'as_of_publication' ? 'as_of_publication' : 'live_decision';
        $isReclassify = ($opts['reclassify'] ?? false) === true;
        $enforceAgeGate = ($opts['enforce_age_gate'] ?? true) === true;

        $title = (string)($item['title'] ?? $item['headline'] ?? '');
        $maxBytes = (int)($this->catalog['settings']['max_analysis_title_bytes'] ?? 2400);
        if (strlen($title) > $maxBytes) {
            $title = substr($title, 0, $maxBytes);
        }

        $pubTs = self::parseStrictIso((string)($item['published_at'] ?? $item['date'] ?? ''), 'published_at', false);
        $revRaw = isset($item['revised_at']) ? (string)$item['revised_at'] : null;
        $revTs = self::parseStrictIso($revRaw, 'revised_at', true);

        $incomingFirstSeen = isset($item['first_seen_at']) ? (string)$item['first_seen_at'] : null;
        $incomingServerObs = ($item['first_seen_server_observed'] ?? false) === true;

        if ($isReclassify) {
            $firstSeenTs = self::parseStrictIso($incomingFirstSeen, 'first_seen_at', true) ?? $pubTs;
            $firstSeenAt = gmdate('Y-m-d\TH:i:s\Z', $firstSeenTs);
            $serverObserved = $incomingServerObs && ($incomingFirstSeen !== null);
        } elseif ($incomingFirstSeen !== null && trim($incomingFirstSeen) !== '') {
            $firstSeenTs = self::parseStrictIso($incomingFirstSeen, 'first_seen_at', false);
            $firstSeenAt = gmdate('Y-m-d\TH:i:s\Z', $firstSeenTs);
            $serverObserved = false;
        } else {
            $firstSeenTs = $nowTs;
            $firstSeenAt = gmdate('Y-m-d\TH:i:s\Z', $nowTs);
            $serverObserved = true;
        }

        if ($mode === 'as_of_publication') {
            $effectiveAvailableTs = $pubTs;
            $visibleRevTs = null;
        } else {
            $effectiveAvailableTs = max($pubTs, $firstSeenTs, $revTs ?? PHP_INT_MIN);
            $visibleRevTs = $revTs;
        }

        $maxAgeDays = (int)($this->catalog['settings']['max_age_days'] ?? 7);
        $freshRefTs = ($visibleRevTs !== null) ? max($pubTs, $visibleRevTs) : $pubTs;

        if ($mode === 'live_decision' && $enforceAgeGate && ($nowTs - $freshRefTs) > ($maxAgeDays * 86400)) {
            return [
                'id' => $item['id'] ?? hash('sha256', $title . '|' . $pubTs),
                'title' => $title,
                'decision' => 'abstain',
                'reason' => 'stale_live_headline',
                'score' => 0.0,
                'matched_events' => [],
                'issuers' => [],
                'published_at' => gmdate('Y-m-d\TH:i:s\Z', $pubTs),
                'revised_at' => $visibleRevTs !== null ? gmdate('Y-m-d\TH:i:s\Z', $visibleRevTs) : null,
                'first_seen_at' => $firstSeenAt,
                'first_seen_server_observed' => $serverObserved,
                'effective_available_at' => gmdate('Y-m-d\TH:i:s\Z', $effectiveAvailableTs),
                'catalog_version' => (string)($this->catalog['version'] ?? '12.0.7'),
                'catalog_sha256' => $this->catalogHash,
            ];
        }

        $lower = strtolower($title);

        foreach ($this->catalog['human_interest'] ?? [] as $hi) {
            foreach ($hi['patterns'] ?? [] as $pat) {
                if ($pat !== '' && str_contains($lower, strtolower($pat))) {
                    return [
                        'id' => $item['id'] ?? hash('sha256', $title . '|' . $pubTs),
                        'title' => $title,
                        'decision' => 'filtered',
                        'reason' => (string)($hi['reason'] ?? 'Human Interest gefiltert'),
                        'score' => 0.0,
                        'matched_events' => [],
                        'issuers' => [],
                        'published_at' => gmdate('Y-m-d\TH:i:s\Z', $pubTs),
                        'revised_at' => $visibleRevTs !== null ? gmdate('Y-m-d\TH:i:s\Z', $visibleRevTs) : null,
                        'first_seen_at' => $firstSeenAt,
                        'first_seen_server_observed' => $serverObserved,
                        'effective_available_at' => gmdate('Y-m-d\TH:i:s\Z', $effectiveAvailableTs),
                        'catalog_version' => (string)($this->catalog['version'] ?? '12.0.7'),
                        'catalog_sha256' => $this->catalogHash,
                    ];
                }
            }
        }

        $matchedIssuers = [];
        foreach ($this->catalog['issuers'] ?? [] as $ticker => $meta) {
            foreach ($meta['aliases'] ?? [] as $alias) {
                if ($alias !== '' && str_contains($lower, strtolower($alias))) {
                    $matchedIssuers[] = $ticker;
                    break;
                }
            }
        }

        $hasNegation = false;
        foreach ($this->catalog['modifiers']['negation'] ?? [] as $neg) {
            if ($neg !== '' && str_contains($lower, strtolower($neg))) {
                $hasNegation = true;
                break;
            }
        }
        $hasUncertainty = false;
        foreach ($this->catalog['modifiers']['uncertainty'] ?? [] as $unc) {
            if ($unc !== '' && str_contains($lower, strtolower($unc))) {
                $hasUncertainty = true;
                break;
            }
        }

        $matchedEvents = [];
        $scoreSum = 0.0;
        foreach ($this->catalog['events'] ?? [] as $rule) {
            foreach ($rule['patterns'] ?? [] as $pat) {
                if ($pat !== '' && str_contains($lower, strtolower($pat))) {
                    $prior = (float)($rule['prior'] ?? 0.0);
                    if ($hasNegation) {
                        $prior *= -0.5;
                    }
                    if ($hasUncertainty) {
                        $prior *= (float)($this->catalog['settings']['uncertainty_discount'] ?? 0.5);
                    }
                    $matchedEvents[] = [
                        'id' => $rule['id'],
                        'label' => $rule['label'],
                        'scope' => $rule['scope'],
                        'prior' => round($prior, 4),
                    ];
                    $scoreSum += $prior;
                    break;
                }
            }
        }

        $decision = $matchedEvents !== [] ? 'relevant' : 'abstain';
        return [
            'id' => $item['id'] ?? hash('sha256', $title . '|' . $pubTs),
            'title' => $title,
            'decision' => $decision,
            'reason' => $decision === 'relevant'
                ? 'Ereignisregel(n) erkannt' . ($hasUncertainty ? ' (Unsicherheitsabschlag)' : '')
                : 'Keine materielle Ereignisregel erkannt',
            'score' => round(max(-1.0, min(1.0, $scoreSum)), 4),
            'matched_events' => $matchedEvents,
            'issuers' => $matchedIssuers,
            'published_at' => gmdate('Y-m-d\TH:i:s\Z', $pubTs),
            'revised_at' => $visibleRevTs !== null ? gmdate('Y-m-d\TH:i:s\Z', $visibleRevTs) : null,
            'first_seen_at' => $firstSeenAt,
            'first_seen_server_observed' => $serverObserved,
            'effective_available_at' => gmdate('Y-m-d\TH:i:s\Z', $effectiveAvailableTs),
            'catalog_version' => (string)($this->catalog['version'] ?? '12.0.7'),
            'catalog_sha256' => $this->catalogHash,
        ];
    }

    public function runEventStudy(array $payload): array
    {
        $bars = $payload['bars'] ?? [];
        $events = $payload['events'] ?? [];
        $horizons = $payload['horizons'] ?? [1, 5, 20];
        $assetBasis = (string)($payload['asset_basis'] ?? 'unknown');
        $benchmarkBasis = (string)($payload['benchmark_basis'] ?? 'unknown');
        $benchmarkBars = $payload['benchmark_bars'] ?? [];
        $costBps = (float)($payload['cost_bps'] ?? 5.0);
        $roundtripCost = max(0.0, ($costBps * 2.0) / 10000.0);

        if (!is_array($bars) || count($bars) < 5) {
            throw new InvalidArgumentException('Zu wenige Kursbars für Event-Studie.');
        }

        $maxCases = (int)($this->catalog['settings']['max_study_cases'] ?? 2500);
        $events = array_slice($events, 0, $maxCases);

        $barDates = array_map(static fn(array $b): string => substr((string)$b['date'], 0, 10), $bars);
        $benchByDate = [];
        if (is_array($benchmarkBars)) {
            foreach ($benchmarkBars as $bb) {
                if (isset($bb['date'], $bb['open'], $bb['close'])) {
                    $benchByDate[substr((string)$bb['date'], 0, 10)] = $bb;
                }
            }
        }

        $rawResultsByHorizon = [];
        $hypothesesForHolm = [];

        foreach ($horizons as $hRaw) {
            $h = (int)$hRaw;
            if ($h < 1 || $h > (int)($this->catalog['settings']['max_study_horizon_bars'] ?? 20)) {
                continue;
            }

            $windows = [];
            $rejectedProvenance = 0;

            foreach ($events as $ev) {
                $catHash = (string)($ev['catalog_sha256'] ?? '');
                if (!$this->isCatalogHashCompatible($catHash)) {
                    $rejectedProvenance++;
                    continue;
                }
                if (($ev['first_seen_server_observed'] ?? false) !== true) {
                    $rejectedProvenance++;
                    continue;
                }
                $pubTs = self::parseStrictIso((string)($ev['published_at'] ?? ''), 'published_at', false);
                $seenTs = self::parseStrictIso((string)($ev['first_seen_at'] ?? ''), 'first_seen_at', false);
                $revTs = self::parseStrictIso(isset($ev['revised_at']) ? (string)$ev['revised_at'] : null, 'revised_at', true);
                $availTs = max($pubTs, $seenTs, $revTs ?? PHP_INT_MIN);

                $entryIdx = null;
                foreach ($barDates as $idx => $dStr) {
                    $sessionStartTs = strtotime($dStr . 'T00:00:00Z');
                    if ($sessionStartTs !== false && $sessionStartTs >= $availTs) {
                        $entryIdx = $idx;
                        break;
                    }
                }
                if ($entryIdx === null) {
                    continue;
                }

                $exitIdx = $entryIdx + $h - 1;
                if (!isset($bars[$exitIdx])) {
                    continue;
                }

                $entryOpen = (float)$bars[$entryIdx]['open'];
                $exitClose = (float)$bars[$exitIdx]['close'];
                if ($entryOpen <= 0.0 || $exitClose <= 0.0) {
                    continue;
                }

                $dir = ((float)($ev['score'] ?? 0.0)) < 0 ? -1.0 : 1.0;
                $gross = $dir * (($exitClose / $entryOpen) - 1.0);
                $net = $gross - $roundtripCost;

                $benchExcess = null;
                if ($assetBasis === 'adjusted' && $benchmarkBasis === 'adjusted') {
                    $dIn = $barDates[$entryIdx];
                    $dOut = $barDates[$exitIdx];
                    if (isset($benchByDate[$dIn], $benchByDate[$dOut]) && (float)$benchByDate[$dIn]['open'] > 0.0) {
                        $bRet = ((float)$benchByDate[$dOut]['close'] / (float)$benchByDate[$dIn]['open']) - 1.0;
                        $benchExcess = $net - ($dir * $bRet);
                    }
                }

                $windows[] = [
                    'entry_index' => $entryIdx,
                    'exit_index' => $exitIdx,
                    'entry_at' => $barDates[$entryIdx] . 'T00:00:00Z',
                    'exit_at' => $barDates[$exitIdx] . 'T23:59:59Z',
                    'net_return' => $net,
                    'bench_excess' => $benchExcess,
                ];
            }

            usort($windows, static fn(array $a, array $b): int => $a['entry_index'] <=> $b['entry_index'] ?: $a['exit_index'] <=> $b['exit_index']);
            $cleanWindows = [];
            $excludedOverlaps = 0;
            $lastExit = -1;
            foreach ($windows as $w) {
                if ($w['entry_index'] <= $lastExit) {
                    $excludedOverlaps++;
                    continue;
                }
                $cleanWindows[] = $w;
                $lastExit = $w['exit_index'];
            }

            $totalN = count($cleanWindows);
            $splitIdx = (int)floor($totalN * 0.7);
            $testRows = array_slice($cleanWindows, $splitIdx);
            $trainCandidates = array_slice($cleanWindows, 0, $splitIdx);

            $purgedCount = 0;
            $trainRows = [];
            if ($testRows !== []) {
                $firstTestEntryIdx = $testRows[0]['entry_index'];
                foreach ($trainCandidates as $tr) {
                    if ($tr['exit_index'] >= $firstTestEntryIdx) {
                        $purgedCount++;
                        continue;
                    }
                    $trainRows[] = $tr;
                }
            } else {
                $trainRows = $trainCandidates;
            }

            $testReturns = array_column($testRows, 'net_return');
            $boot = $this->movingBlockBootstrap($testReturns, max(2, (int)ceil(($h + 1) / 2)), 400, 120700 + $h);

            $testN = count($testReturns);
            $wins = count(array_filter($testReturns, static fn(float $r): bool => $r > 0.0));
            $wilsonLow = $this->wilsonLowerBound($wins, $testN);
            $meanNetTest = $testN > 0 ? array_sum($testReturns) / $testN : 0.0;

            $rawResultsByHorizon[$h] = [
                'horizon' => $h,
                'effective_sample_size' => $totalN,
                'train_n' => count($trainRows),
                'test_n' => $testN,
                'excluded_overlaps' => $excludedOverlaps,
                'purged_train_rows' => $purgedCount,
                'rejected_provenance' => $rejectedProvenance,
                'mean_net_test_return' => round($meanNetTest, 6),
                'wilson_lower_95' => $wilsonLow !== null ? round($wilsonLow, 4) : null,
                'bootstrap_ci_low' => $boot['ci_low'] !== null ? round($boot['ci_low'], 6) : null,
                'bootstrap_ci_high' => $boot['ci_high'] !== null ? round($boot['ci_high'], 6) : null,
                'p_value_raw' => $boot['p_value'] ?? 1.0,
            ];

            $hypothesesForHolm[] = [
                'horizon' => $h,
                'p_raw' => $boot['p_value'] ?? 1.0,
            ];
        }

        usort($hypothesesForHolm, static fn(array $a, array $b): int => $a['p_raw'] <=> $b['p_raw'] ?: $a['horizon'] <=> $b['horizon']);
        $m = count($hypothesesForHolm);
        $runningMax = 0.0;
        foreach ($hypothesesForHolm as $rank => $item) {
            $adj = min(1.0, $item['p_raw'] * ($m - $rank));
            $runningMax = max($runningMax, $adj);
            $h = $item['horizon'];
            $rawResultsByHorizon[$h]['p_value_holm'] = round($runningMax, 6);

            $row = $rawResultsByHorizon[$h];
            $isCandidate =
                ($assetBasis === 'adjusted') &&
                ($row['train_n'] >= 20) &&
                ($row['test_n'] >= 15) &&
                ($row['wilson_lower_95'] !== null && $row['wilson_lower_95'] > 0.5) &&
                ($row['mean_net_test_return'] > 0.0) &&
                ($row['bootstrap_ci_low'] !== null && $row['bootstrap_ci_low'] > 0.0) &&
                ($runningMax < 0.05);

            $rawResultsByHorizon[$h]['candidate'] = $isCandidate;
            $rawResultsByHorizon[$h]['usable_for_claim'] = false;
        }

        return [
            'ok' => true,
            'version' => self::API_VERSION,
            'asset_basis' => $assetBasis,
            'benchmark_basis' => $benchmarkBasis,
            'benchmark_excess_eligible' => ($assetBasis === 'adjusted' && $benchmarkBasis === 'adjusted'),
            'usable_for_claim' => false,
            'results' => array_values($rawResultsByHorizon),
        ];
    }

    private function wilsonLowerBound(int $hits, int $n): ?float
    {
        if ($n <= 0) {
            return null;
        }
        $p = $hits / $n;
        $z = 1.96;
        $z2 = $z * $z;
        $denom = 1.0 + ($z2 / $n);
        $center = ($p + ($z2 / (2.0 * $n))) / $denom;
        $radius = ($z * sqrt(($p * (1.0 - $p) / $n) + ($z2 / (4.0 * $n * $n)))) / $denom;
        return $center - $radius;
    }

    private function movingBlockBootstrap(array $values, int $blockLen, int $reps, int $seed): array
    {
        $n = count($values);
        if ($n < 5) {
            return ['ci_low' => null, 'ci_high' => null, 'p_value' => 1.0];
        }
        $b = max(1, min($n, $blockLen));
        $obsMean = array_sum($values) / $n;
        $centered = array_map(static fn(float $v): float => $v - $obsMean, $values);

        $state = $seed & 0x7fffffff;
        if ($state === 0) {
            $state = 1;
        }
        $rand = static function () use (&$state): float {
            $state = ($state * 1103515245 + 12345) & 0x7fffffff;
            return $state / 0x7fffffff;
        };

        $uncenteredMeans = [];
        $nullMeans = [];
        $maxStart = max(1, $n - $b + 1);

        for ($r = 0; $r < $reps; $r++) {
            $sumU = 0.0;
            $sumN = 0.0;
            $cnt = 0;
            while ($cnt < $n) {
                $start = (int)floor($rand() * $maxStart);
                for ($j = 0; $j < $b && $cnt < $n; $j++, $cnt++) {
                    $idx = ($start + $j) % $n;
                    $sumU += $values[$idx];
                    $sumN += $centered[$idx];
                }
            }
            $uncenteredMeans[] = $sumU / $n;
            $nullMeans[] = $sumN / $n;
        }

        sort($uncenteredMeans);
        $ciLow = $uncenteredMeans[(int)floor(0.025 * ($reps - 1))];
        $ciHigh = $uncenteredMeans[(int)floor(0.975 * ($reps - 1))];

        $extreme = 0;
        $absObs = abs($obsMean);
        foreach ($nullMeans as $nm) {
            if (abs($nm) >= $absObs - 1e-15) {
                $extreme++;
            }
        }
        $pVal = min(1.0, ($extreme + 1) / ($reps + 1));

        return ['ci_low' => $ciLow, 'ci_high' => $ciHigh, 'p_value' => $pVal];
    }

    public function buildGraph(array $payload): array
    {
        $maxDepth = min(3, max(1, (int)($payload['max_depth'] ?? ($this->catalog['settings']['graph_max_depth'] ?? 3))));
        $maxEdges = min(250, max(1, (int)($payload['max_edges'] ?? ($this->catalog['settings']['graph_max_edges'] ?? 250))));
        $asOfTs = self::parseStrictIso(isset($payload['as_of']) ? (string)$payload['as_of'] : null, 'as_of', true) ?? time();

        $edges = [];
        foreach ($this->catalog['relationships'] ?? [] as $rel) {
            $availTs = self::parseStrictIso((string)($rel['available_at'] ?? ''), 'available_at', false);
            if ($availTs > $asOfTs) {
                continue;
            }
            $edges[] = $rel;
            if (count($edges) >= $maxEdges) {
                break;
            }
        }

        return [
            'ok' => true,
            'version' => self::API_VERSION,
            'max_depth' => $maxDepth,
            'max_edges' => $maxEdges,
            'edges' => $edges,
        ];
    }
}

if (PHP_SAPI !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');

    $action = (string)($_GET['action'] ?? 'health');
    $rawBody = (string)file_get_contents('php://input');
    $payload = $rawBody !== '' ? (json_decode($rawBody, true) ?? []) : [];

    try {
        $engine = new QuantTrendEventsEngine(__DIR__ . '/event_catalog.json');

        switch ($action) {
            case 'health':
                echo json_encode($engine->health(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                break;

            case 'catalog':
                echo json_encode([
                    'ok' => true,
                    'version' => QuantTrendEventsEngine::API_VERSION,
                    'catalog_version' => $engine->getCatalog()['version'] ?? '12.0.7',
                    'catalog_sha256' => $engine->getCatalogHash(),
                    'catalog' => $engine->getCatalog(),
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                break;

            case 'analyze':
                $items = $payload['items'] ?? (isset($payload['title']) ? [$payload] : []);
                $results = array_map(
                    fn(array $item): array => $engine->classifyHeadline($item, [
                        'decision_mode' => $payload['decision_mode'] ?? 'live_decision',
                        'enforce_age_gate' => true,
                        'reclassify' => false,
                    ]),
                    $items
                );
                echo json_encode([
                    'ok' => true,
                    'version' => QuantTrendEventsEngine::API_VERSION,
                    'catalog_version' => $engine->getCatalog()['version'] ?? '12.0.7',
                    'catalog_sha256' => $engine->getCatalogHash(),
                    'records' => $results,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                break;

            case 'reclassify':
                $records = $payload['records'] ?? [];
                $results = array_map(
                    fn(array $rec): array => $engine->classifyHeadline($rec, [
                        'decision_mode' => 'live_decision',
                        'enforce_age_gate' => false,
                        'reclassify' => true,
                    ]),
                    $records
                );
                echo json_encode([
                    'ok' => true,
                    'version' => QuantTrendEventsEngine::API_VERSION,
                    'catalog_version' => $engine->getCatalog()['version'] ?? '12.0.7',
                    'catalog_sha256' => $engine->getCatalogHash(),
                    'records' => $results,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                break;

            case 'study':
                echo json_encode($engine->runEventStudy($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                break;

            case 'analogs':
            case 'knn':
                $query = $payload['query'] ?? [];
                $archive = $payload['archive'] ?? [];
                $matches = [];
                foreach ($archive as $cand) {
                    if (($cand['first_seen_server_observed'] ?? false) !== true) {
                        continue;
                    }
                    if (!$engine->isCatalogHashCompatible((string)($cand['catalog_sha256'] ?? ''))) {
                        continue;
                    }
                    if (($cand['decision'] ?? '') !== 'relevant') {
                        continue;
                    }
                    $matches[] = $cand;
                }
                echo json_encode([
                    'ok' => true,
                    'version' => QuantTrendEventsEngine::API_VERSION,
                    'query_id' => $query['id'] ?? null,
                    'matches' => array_slice($matches, 0, 25),
                    'usable_for_claim' => false,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                break;

            case 'matrix':
                $assets = array_slice($payload['assets'] ?? ['NVDA', 'QQQ', 'SPY', 'BTC'], 0, 8);
                $items = $payload['items'] ?? [];
                echo json_encode([
                    'ok' => true,
                    'version' => QuantTrendEventsEngine::API_VERSION,
                    'assets' => $assets,
                    'evaluated_items' => count($items),
                    'usable_for_claim' => false,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                break;

            case 'graph':
                echo json_encode($engine->buildGraph($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                break;

            default:
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Unbekannte Action: ' . $action]);
        }
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
}
