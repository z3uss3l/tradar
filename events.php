<?php
declare(strict_types=1);
/**
 * QuantTrend event intelligence v11 — PHP 8.1+, one PHP file plus event_catalog.json.
 * POST ?action=analyze: classify a batch of externally acquired headlines, no network I/O.
 * POST ?action=study: chronological, purged event-return diagnostics for supplied REAL bars.
 * POST ?action=matrix: compare exactly the same headlines for 1–8 independent assets.
 * POST ?action=analogs: causal historical kNN on verified user-supplied event/price pairs.
 * GET  ?action=catalog: public, read-only catalogue summary and method version.
 * GET  ?action=health: schema, catalogue validity, capabilities.
 * Never converts text priors to return predictions or places orders.
 * All patterns and asset relations are declared in event_catalog.json.
 */

final class QTEventError extends RuntimeException {
    public function __construct(string $message, public readonly int $status = 400) { parent::__construct($message); }
}

final class QTEventCatalog {
    public readonly array $data;
    public readonly string $sha256;

    public function __construct(string $path) {
        if (!is_file($path) || !is_readable($path)) throw new QTEventError('event_catalog.json fehlt oder ist nicht lesbar.', 503);
        $raw = file_get_contents($path);
        if ($raw === false || strlen($raw) > 262144) throw new QTEventError('Ereigniskatalog nicht lesbar oder zu groß.', 503);
        try { $data = json_decode($raw, true, 128, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new QTEventError('Ungültiges event_catalog.json: '.$e->getMessage(), 503); }
        if (!is_array($data) || ($data['schema'] ?? null) !== 'quanttrend-event-catalog/1') throw new QTEventError('Nicht unterstütztes Katalogschema.', 503);
        foreach (['settings', 'issuers', 'asset_macro_groups', 'language', 'events', 'human_interest', 'sources', 'relationships'] as $k) {
            if (!is_array($data[$k] ?? null)) throw new QTEventError('Katalogfeld '.$k.' fehlt.', 503);
        }
        if (count($data['events']) > 200 || count($data['issuers']) > 250 || count($data['human_interest']) > 100) throw new QTEventError('Katalog überschreitet Sicherheitslimits.', 503);
        $ids = [];
        foreach ($data['events'] as $event) {
            $id = $event['id'] ?? null;
            if (!is_string($id) || !preg_match('/^[a-z][a-z0-9_]{1,60}$/D', $id) || isset($ids[$id]) || !in_array($event['scope'] ?? '', ['issuer', 'macro', 'crypto'], true)) throw new QTEventError('Ungültige/doppelte Ereignisregel.', 503);
            $ids[$id] = true;
            $prior = $event['prior'] ?? null;
            if (!is_int($prior) && !is_float($prior) || !is_finite((float)$prior) || abs((float)$prior) > 1) throw new QTEventError('Ungültiger Ausgangswert bei '.$id.'.', 503);
            if (!is_array($event['patterns'] ?? null) || !$event['patterns']) throw new QTEventError('Muster fehlen bei '.$id.'.', 503);
            foreach ($event['patterns'] as $p) self::patternValidate($p);
            foreach (($event['regex'] ?? []) as $p) self::regexValidate($p);
        }
        foreach ($data['human_interest'] as $rule) {
            foreach (($rule['patterns'] ?? []) as $p) self::patternValidate($p);
            foreach (($rule['regex'] ?? []) as $p) self::regexValidate($p);
        }
        foreach ($data['issuers'] as $ticker => $issuer) {
            if (!preg_match('/^[A-Z0-9.=-]{1,16}$/D', (string)$ticker)) throw new QTEventError('Ungültiges Emittentensymbol.', 503);
            foreach (($issuer['aliases'] ?? []) as $alias) self::patternValidate($alias);
        }
        foreach ($data['events'] as $event) {
            foreach (($event['asset_signs'] ?? []) as $group => $weight) {
                if (!isset($data['asset_macro_groups'][$group]) || !is_numeric($weight) || !is_finite((float)$weight) || abs((float)$weight)>1)
                    throw new QTEventError('Ungültige Asset-Wirkungsrelation bei '.($event['id'] ?? '?').'.', 503);
            }
        }
        foreach(($data['compatible_catalog_sha256']??[]) as $oldHash){if(!is_string($oldHash)||!preg_match('/^[a-f0-9]{64}$/D',$oldHash))throw new QTEventError('Ungültige kompatible Katalogprüfsumme.',503);}
        if(isset($data['event_guard'])){
            $g=$data['event_guard'];
            if(!is_array($g)||!in_array($g['mode']??null,['paper','hold'],true)||
                !is_array($g['adverse_rule_ids']??null)||count($g['adverse_rule_ids'])>50||
                !is_numeric($g['hold_threshold']??null)||($g['hold_threshold']??0)<=0||($g['hold_threshold']??0)>1)
                throw new QTEventError('Ungültige Event-Guard-Konfiguration.',503);
            foreach($g['adverse_rule_ids'] as $id){if(!is_string($id)||!isset($ids[$id]))throw new QTEventError('Event-Guard referenziert unbekannte Regel.',503);}
        }
        if (count($data['relationships']) > 250) throw new QTEventError('Zu viele Beziehungsgraph-Kanten.', 503);
        foreach ($data['relationships'] as $edge) {
            if (!is_array($edge)) throw new QTEventError('Ungültige Graphkante.',503);
            foreach (['from','to'] as $k) if (!is_string($edge[$k]??null) || !preg_match('/^[A-Z0-9.=-]{1,20}$/D',$edge[$k])) throw new QTEventError('Ungültiger Graphknoten.',503);
            if (!in_array($edge['type']??null,['supplier_for','fund_constituent','customer_of','competitor_of','owned_by'],true)) throw new QTEventError('Ungültige Beziehungsart.',503);
            if (!is_string($edge['source_url']??null) || !preg_match('~^https://[a-z0-9.-]+/~i',$edge['source_url'])) throw new QTEventError('Graphkante ohne HTTPS-Quellenbeleg.',503);
            if (!is_string($edge['available_at']??null) || strtotime($edge['available_at'])===false) throw new QTEventError('Graphkante ohne Verfügbarkeitszeit.',503);
            if (!is_string($edge['valid_from']??null) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$edge['valid_from'])) throw new QTEventError('Graphkante ohne Gültigkeitsbeginn.',503);
            if (isset($edge['valid_to']) && (!is_string($edge['valid_to']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$edge['valid_to']) || $edge['valid_to']<$edge['valid_from'])) throw new QTEventError('Ungültiges Graph-Gültigkeitsende.',503);
            if (isset($edge['fraction']) && (!is_numeric($edge['fraction']) || !is_finite((float)$edge['fraction']) || $edge['fraction']<0 || $edge['fraction']>1)) throw new QTEventError('Graph-Exposition ungültig.',503);
        }
        $this->data = $data;
        $this->sha256 = hash('sha256', $raw);
    }

    public static function patternValidate(mixed $p): void {
        if (!is_string($p) || strlen($p) < 2 || strlen($p) > 180 || preg_match('/[\x00-\x1f]/', $p)) throw new QTEventError('Ungültiges Katalog-Schlüsselwort.', 503);
    }
    public static function regexValidate(mixed $p): void {
        if (!is_string($p) || strlen($p) > 200 || strlen($p) < 2 || @preg_match('~'.$p.'~iu', '') === false) throw new QTEventError('Ungültiger Katalog-RegEx.', 503);
    }
}

final class QTEventEngine {
    private array $config;
    private array $groups;
    private array $rules;
    private array $issuers;
    private array $human;
    private array $language;
    private array $sources;

    public function __construct(private readonly QTEventCatalog $catalog) {
        $d = $catalog->data;
        $this->config = $d['settings'];
        $this->groups = $d['asset_macro_groups'];
        $this->issuers = $d['issuers'];
        $this->language = $d['language'];
        $this->sources = $d['sources'];
        $this->rules = $d['events'];
        $this->human = $d['human_interest'];
    }

    public static function norm(string $raw): string {
        $raw = strtr($raw, ['Ä'=>'Ae','ä'=>'ae','Ö'=>'Oe','ö'=>'oe','Ü'=>'Ue','ü'=>'ue','ß'=>'ss']);
        $out = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $raw);
        if ($out !== false) $raw = $out;
        $raw = strtolower($raw);
        $raw = preg_replace('/[^a-z0-9]+/', ' ', $raw) ?? '';
        return trim(preg_replace('/\s+/', ' ', $raw) ?? '');
    }
    private static function hits(string $text, array $patterns, array $regex = []): array {
        $out = [];
        foreach ($patterns as $phrase) {
            $part = self::norm((string)$phrase);
            if ($part !== '' && str_contains(' '.$text.' ', ' '.$part.' ')) $out[] = $phrase;
        }
        foreach ($regex as $pattern) {
            if (@preg_match('~'.$pattern.'~iu', $text) === 1) $out[] = 'regex:'.$pattern;
        }
        return array_values(array_unique($out));
    }
    private static function tokens(string $s): array { return $s === '' ? [] : explode(' ', $s); }
    private static function yes(string $text, array $patterns): bool { return (bool)self::hits($text, $patterns); }
    private function markerNearby(string $text, string $needle, array $markers, int $before = 5, int $after = 2): bool {
        $seq = self::tokens($text);
        $terms = self::tokens(self::norm($needle));
        if (!$seq || !$terms) return false;
        $length = count($terms);
        for ($i = 0, $n = count($seq); $i <= $n - $length; $i++) {
            if (array_slice($seq, $i, $length) !== $terms) continue;
            $begin=max(0,$i-$before);$window = implode(' ',array_slice($seq,$begin,$i+$length+$after-$begin));
            if (self::yes($window, $markers)) return true;
        }
        return false;
    }
    private function relation(string $text, string $asset): array {
        $companies = [];
        foreach ($this->issuers as $ticker => $company) {
            $matched = self::hits($text, $company['aliases'] ?? []);
            if ($matched) $companies[$ticker] = $matched;
        }
        // Avoid one headline being attributed as the same event to two rival issuers.
        $unique = count($companies) === 1 ? array_key_first($companies) : null;
        $direct = $unique !== null && $asset === $unique;
        $indirect = $unique !== null && in_array($asset, $this->issuers[$unique]['etf_candidates'] ?? [], true);
        $factor = $direct ? 1.0 : ($indirect ? max(0, min(0.35, (float)($this->config['indirect_etf_relevance'] ?? 0.18))) : 0.0);
        return [
            'company' => $unique, 'companies' => array_keys($companies), 'matched_aliases' => $companies,
            'relation' => count($companies) > 1 ? 'ambiguous_multiple_issuers' : ($direct ? 'direct' : ($indirect ? 'unverified_etf_exposure' : 'none')),
            'factor' => $factor, 'direct' => $direct, 'indirect' => $indirect
        ];
    }
    private function graphEdgesAt(int $asOf): array {
        $date=gmdate('Y-m-d',$asOf);$valid=[];
        foreach ($this->catalog->data['relationships'] as $edge) {
            $available=strtotime($edge['available_at']);
            if ($available===false || $available>$asOf || $edge['valid_from']>$date || (isset($edge['valid_to']) && $date>$edge['valid_to'])) continue;
            $valid[]=$edge;
        }
        return $valid;
    }
    private function graphPath(string $from,string $to,int $asOf): ?array {
        if($from===$to)return ['path'=>[$from],'edges'=>[],'exposure_fraction'=>1.0,'quantified'=>true];
        $edges=$this->graphEdgesAt($asOf);$queue=[['node'=>$from,'path'=>[$from],'edges'=>[],'value'=>1.0,'quantified'=>true]];
        while($queue){$r=array_shift($queue);if(count($r['edges'])>=3)continue;
            foreach($edges as $e){if($e['from']!==$r['node']||in_array($e['to'],$r['path'],true))continue;
                $quant=$r['quantified'] && isset($e['fraction']);$value=$quant?$r['value']*(float)$e['fraction']:null;
                $next=['node'=>$e['to'],'path'=>[...$r['path'],$e['to']],'edges'=>[...$r['edges'],$e],'value'=>$value,'quantified'=>$quant];
                if($next['node']===$to)return ['path'=>$next['path'],'edges'=>$next['edges'],'exposure_fraction'=>$value,'quantified'=>$quant];
                $queue[]=$next;
            }
        }return null;
    }
    public function graph(array $input): array {
        $assets=$input['assets']??[];if(!is_array($assets)||!array_is_list($assets)||count($assets)>8)throw new QTEventError('Graph: maximal acht Asset-Symbole.');
        $asOf=$this->parseTime($input['as_of']??gmdate('c'));if($asOf===null||$asOf>time())throw new QTEventError('Graph: ungültiger oder zukünftiger Zeitpunkt.');
        $edges=$this->graphEdgesAt($asOf);$nodes=[];$paths=[];
        foreach($assets as $asset){if(!is_string($asset)||!preg_match('/^[A-Z0-9.=-]{1,20}$/D',$asset))throw new QTEventError('Ungültiges Graph-Asset.');
            foreach($this->issuers as $issuer=>$_){$route=$this->graphPath($issuer,$asset,$asOf);if($route && $issuer!==$asset){$paths[]=['issuer'=>$issuer,'asset'=>$asset]+$route;}}
        }
        foreach($edges as $e){$nodes[$e['from']]=true;$nodes[$e['to']]=true;}
        return ['ok'=>true,'version'=>'quanttrend-graph/1','as_of'=>gmdate('c',$asOf),'catalog_sha256'=>$this->catalog->sha256,
         'nodes'=>array_keys($nodes),'edges'=>$edges,'paths'=>$paths,'note'=>'Nur belegte, am Stichtag verfügbare gerichtete Beziehungen. Unquantifizierte Lieferkettenpfade haben exposure_fraction=null und keinen Kursfaktor. Holdings können sich nach dem Stichtag ändern.'];
    }
    private function temporal(array $item,int $decision): array {
        $pub=$this->parseTime($item['date']??$item['published_at']??null);
        $first=$this->parseTime($item['first_seen_at']??null);
        $revision=$this->parseTime($item['revised_at']??null);
        $availability=$pub!==null&&$first!==null?max($pub,$first,$revision??0):null;
        $valid=$pub!==null && $pub<=$decision && $first!==null && $first<=$decision && ($revision===null||$revision<=$decision);
        return ['published_at'=>$pub===null?null:gmdate('c',$pub),'first_seen_at'=>$first===null?null:gmdate('c',$first),
         'available_at'=>$availability===null?null:gmdate('c',$availability),'revised_at'=>$revision===null?null:gmdate('c',$revision),
         'eligible_as_of'=>$valid,'first_seen_verified'=>$first!==null,'unknown_first_seen'=>$first===null];
    }
    private function groupOf(string $asset): array {
        $groups = [];
        foreach ($this->groups as $group => $tickers) if (in_array($asset, $tickers, true)) $groups[] = $group;
        if (isset($this->issuers[$asset]) && !in_array('equities', $groups, true)) $groups[] = 'equities';
        return $groups;
    }
    private function authority(mixed $url): array {
        $host = '';
        if (is_string($url) && preg_match('~^https://~i', $url)) $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
        foreach ($this->sources as $tier => $data) {
            foreach (($data['domains'] ?? []) as $domain) {
                if ($host === $domain || str_ends_with($host, '.'.$domain)) return ['tier'=>$tier, 'host'=>$host, 'weight'=>(float)$data['weight']];
            }
        }
        return ['tier'=>'unknown', 'host'=>$host, 'weight'=>(float)($this->sources['unknown']['weight'] ?? 0.25)];
    }
    private function parseTime(mixed $date): ?int {
        if (!is_string($date) || strlen($date)>72 || $date==='') return null;
        // Date-only publication is available at the END of the UTC day, never midnight.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)) {
            try {$dt=new DateTimeImmutable($date.'T23:59:59Z');
                return $dt->format('Y-m-d')===$date?$dt->getTimestamp():null;
            }catch(Throwable){return null;}
        }
        // Fully qualified, offset-aware ISO-8601 or RFC-2822. Ambiguous local times rejected.
        $iso=(bool)preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:?\d{2})$/iD',$date);
        $rfc=(bool)preg_match('/^(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun),\s+\d{1,2}\s+[A-Za-z]{3}\s+\d{4}\s+\d{2}:\d{2}(?::\d{2})?\s+(?:GMT|UT|UTC|[+-]\d{4})$/iD',$date);
        if(!$iso&&!$rfc)return null;
        try {return (new DateTimeImmutable($date))->getTimestamp();}catch(Throwable){return null;}
    }

    public function classify(array $item, string $asset, int $now): array {
        $title = $item['title'] ?? '';
        if (!is_string($title) || trim($title) === '' || strlen($title) > 2400) throw new QTEventError('Titel fehlt oder überschreitet 2400 Bytes.');
        $title = trim($title);
        $text = self::norm($title);
        $dateTs = $this->parseTime($item['date'] ?? null);
        $maxAge = max(1, min(30, (int)($this->config['max_age_days'] ?? 7)));
        $temporal = $this->temporal($item,$now);
        $inWindow = $dateTs !== null && $dateTs <= $now && $now - $dateTs <= $maxAge * 86400;
        if (array_key_exists('first_seen_at',$item)) $inWindow = $inWindow && $temporal['eligible_as_of'];
        $entity = $this->relation($text, $asset);
        if($entity['company']!==null && !$entity['direct']) {
            $path=$this->graphPath($entity['company'],$asset,$now);
            if($path && count($path['edges'])>0){$entity['graph_path']=$path['path'];$entity['graph_edges']=$path['edges'];
                $entity['relation']=$path['quantified']?'verified_graph_quantified':'verified_graph_unquantified';
                $entity['factor']=$path['quantified']?min(.35,max(0,(float)$path['exposure_fraction'])):0.0;
                $entity['indirect']=true;}
        }
        $groups = $this->groupOf($asset);
        $companyNames = $entity['companies'];
        $human = [];
        foreach ($this->human as $entry) {
            $hits = self::hits($text, $entry['patterns'] ?? [], $entry['regex'] ?? []);
            if ($hits) $human[] = ['id'=>$entry['id'], 'reason'=>$entry['reason'], 'matches'=>$hits];
        }
        $uncertain = str_contains($title, '?') || self::yes($text, $this->language['uncertainty'] ?? []);
        $conditional = self::yes($text, $this->language['conditional'] ?? []);
        $retracted = self::yes($text, $this->language['retraction'] ?? []);
        $claimStatus = $retracted ? 'retracted_or_denied' : ($conditional ? 'conditional' : ($uncertain ? 'unconfirmed' : 'not_qualified'));
        $economicAnchor = self::yes($text, $this->language['economy_anchors'] ?? []);
        $evidence = [];
        foreach ($this->rules as $rule) {
            $found = self::hits($text, $rule['patterns'] ?? [], $rule['regex'] ?? []);
            if (!$found) continue;
            $scope = $rule['scope'];
            $relation = 0.0;
            if ($scope === 'issuer') $relation = count($companyNames) === 1 ? $entity['factor'] : 0.0;
            else {
                foreach ($groups as $group) {
                    if (array_key_exists($group, $rule['asset_signs'] ?? [])) {
                        $weight = (float)$rule['asset_signs'][$group];
                        if (abs($weight) > abs($relation)) $relation = $weight;
                    }
                }
            }
            if ($relation == 0.0) continue;
            // Negation must be local to the event phrase. Global 'no' elsewhere does not negate a title.
            $negated = false;
            foreach ($found as $phrase) {
                if (str_starts_with($phrase, 'regex:')) continue;
                if ($this->markerNearby($text, (string)$phrase, $this->language['negations'] ?? [], 5, 2)) { $negated = true; break; }
            }
            $discount = ($uncertain || $conditional) ? max(0, min(1, (float)($this->config['uncertainty_discount'] ?? 0.5))) : 1.0;
            // A denial only invalidates the LOCAL event phrase, not another confirmed event
            // in the same headline (e.g. guidance raised, while resignation rumor denied).
            if ($retracted && !$negated) {
                foreach ($found as $phrase) {
                    if (!str_starts_with((string)$phrase, 'regex:') &&
                        $this->markerNearby($text, (string)$phrase, $this->language['retraction'] ?? [], 5, 2)) {
                        $negated=true;break;
                    }
                }
            }
            $raw = (float)$rule['prior'] * $relation * $discount;
            $evidence[] = [
                'id'=>$rule['id'], 'kind'=>$rule['label'], 'matches'=>$found, 'tags'=>$rule['tags'] ?? [],
                'score'=>$negated ? 0.0 : round($raw, 6), 'prior'=>(float)$rule['prior'],
                'relation_factor'=>$relation, 'negated'=>$negated, 'uncertain'=>$uncertain || $conditional,
                'detail'=>$rule['label'].' · '.($entity['company'] ?: implode(', ', $groups)).($negated?' · negiert':($uncertain?' · unbestätigt':''))
            ];
        }
        $positive = array_values(array_filter($evidence, static fn($e) => $e['score'] != 0.0));
        // Human-interest filter: default ignore; material market events can override it explicitly.
        // Reject non-economic personal stories even if one generic macro term also matched.
        // Never veto a substantiated issuer event or an explicit economic materiality anchor.
        $filtered = (bool)$human && (!$positive || (!$economicAnchor && $entity['relation']==='none'));
        $raw = $positive ? array_sum(array_column($positive, 'score')) / count($positive) : 0.0;
        $score = round(max(-1, min(1, $raw)), 6);
        $status = !$inWindow ? 'abstain' : ($filtered ? 'filtered' : ($positive ? 'relevant' : 'abstain'));
        $reason = !$inWindow ? ($dateTs === null ? 'Kein verifizierbares Veröffentlichungsdatum.' : ($dateTs > $now ? 'Meldung liegt in der Zukunft.' : 'Meldung außerhalb des Aktualitätsfensters.')) :
            ($filtered ? $human[0]['reason'].'; kein eigenständig erkanntes materielles Wirtschaftsereignis.' :
            (!$positive ? (count($companyNames)>1?'Mehrdeutiger Unternehmensbezug.':($companyNames?'Kein passender materieller Ereignistyp.':'Kein belegter assetbezogener Übertragungskanal.')) :
            'Assetbezogener Ereignistyp erkannt; Text-Hypothese, keine prognostizierte Rendite.'));
        return [
            'title'=>$title, 'date'=>is_string($item['date'] ?? null)?$item['date']:null,
            'url'=>is_string($item['url'] ?? null)?$item['url']:null, 'category'=>is_string($item['key'] ?? null)?$item['key']:null,
            'asset'=>$asset, 'decision'=>$status, 'reason'=>$reason,
            'score'=>$status === 'relevant' ? $score : 0.0, 'recognized'=>$status === 'relevant',
            'uncertain'=>$uncertain || $conditional, 'claim_status'=>$claimStatus,
            'negated'=>(bool)array_filter($evidence, static fn($e)=>$e['negated']),
            'entity'=>$entity, 'temporal'=>$temporal, 'human_interest'=>$human, 'economic_anchor'=>$economicAnchor,
            'evidence'=>$evidence, 'authority'=>$this->authority($item['url'] ?? null),
            'observed_at'=>$dateTs === null?null:gmdate('c',$dateTs), 'age_hours'=>$dateTs === null?null:round(($now-$dateTs)/3600, 3),
            'model_source'=>'catalog_rule_prior', 'return_estimate'=>null, 'confidence_probability'=>null,
            'tokens'=>self::tokens($text)
        ];
    }

    private static function duplicateEvent(array $a, array $b, int $windowSeconds): bool {
        $at = isset($a['observed_at']) ? strtotime($a['observed_at']) : false;
        $bt = isset($b['observed_at']) ? strtotime($b['observed_at']) : false;
        if ($at===false || $bt===false || abs($at-$bt)>$windowSeconds) return false;
        if ($a['claim_status']!==$b['claim_status'] || $a['decision']!==$b['decision']) return false;
        $ea=array_values(array_unique(array_column($a['evidence'],'id')));
        $eb=array_values(array_unique(array_column($b['evidence'],'id')));
        sort($ea); sort($eb);
        if ($ea!==$eb || !$ea) return false;
        if (($a['entity']['company'] ?? null)!==($b['entity']['company'] ?? null))return false;
        $aa=array_unique(array_filter($a['tokens'],static fn($t)=>strlen($t)>=3));
        $bb=array_unique(array_filter($b['tokens'],static fn($t)=>strlen($t)>=3));
        $union=count(array_unique(array_merge($aa,$bb)));
        return $union>0 && count(array_intersect($aa,$bb))/$union>=0.78;
    }

    public function matrix(array $body): array {
        $assets=$body['assets']??null;
        if(!is_array($assets)||!array_is_list($assets)||!$assets||count($assets)>min(8,(int)($this->config['max_matrix_assets']??8)))
            throw new QTEventError('assets: Liste mit 1 bis 8 Symbolen erwartet.');
        $items=$body['items']??null;
        if(!is_array($items)||!array_is_list($items)||count($items)>min(120,(int)($this->config['max_items']??120)))
            throw new QTEventError('items: höchstens 120 reale Meldungen.');
        $result=[];
        foreach(array_values(array_unique($assets)) as $a){
            if(!is_string($a)||!preg_match('/^[A-Z0-9.=-]{1,20}$/D',$a))throw new QTEventError('Ungültiges Asset in Matrix.');
            $report=$this->analyze(['asset'=>$a,'items'=>$items,'as_of_publication'=>($body['as_of_publication']??false)===true]);
            $result[$a]=['summary'=>$report['summary'],'decisions'=>array_map(static fn($x)=>[
                'title'=>$x['title'],'decision'=>$x['decision'],'score'=>$x['score'],'reason'=>$x['reason'],
                'company'=>$x['entity']['company'],'relation'=>$x['entity']['relation'],
                'events'=>array_map(static fn($e)=>$e['id'],$x['evidence']),'claim_status'=>$x['claim_status']
            ],$report['analyses'])];
        }
        return ['ok'=>true,'version'=>'quanttrend-events/11.3','catalog_sha256'=>$this->catalog->sha256,
            'at'=>gmdate('c'),'assets'=>$result,'interpretation'=>'Textrelevanz, nicht gemessene Rendite. Historische Meldungen werden bei as_of_publication mit HEUTIGEM Katalog klassifiziert (keine prospektive Methodik). ETF-Expositionen sind unverifiziert.'];
    }

    /** Research-only, deterministic negative-event guard; unknown feed coverage is not "safe". */
    private function eventGuard(array $items, array $analyses, int $now): array {
        $cfg=$this->catalog->data['event_guard']??[];
        $mode=in_array($cfg['mode']??'paper',['paper','hold'],true)?$cfg['mode']:'paper';
        $window=max(1,min(168,(int)($cfg['window_hours']??24)))*3600;
        $threshold=max(0.01,min(1.0,(float)($cfg['hold_threshold']??0.45)));
        $sourceMin=max(0.0,min(1.0,(float)($cfg['min_source_weight']??0.65)));
        $types=$cfg['adverse_rule_ids']??[];if(!is_array($types))$types=[];
        $cases=[];$eligibleHeadlines=0;$validDates=0;$missingDates=0;$selfReportedFirstSeen=0;
        foreach($analyses as $i=>$row){
            $published=$this->parseTime($items[$i]['date']??null);
            if($published===null||$published>$now){$missingDates++;continue;}
            $validDates++;
            $firstSeen=$this->parseTime($items[$i]['first_seen_at']??null);
            // Client-supplied first_seen cannot be independently authenticated; enforce causal order.
            if($firstSeen!==null && $firstSeen>=$published && $firstSeen<=$now){$selfReportedFirstSeen++;}
            else $firstSeen=$now;
            if($now-$firstSeen>$window||$now-$published>$window*2)continue;
            $eligibleHeadlines++;
            if(($row['decision']??'')!=='relevant'||($row['authority']['weight']??0)<$sourceMin)continue;
            foreach($row['evidence']??[] as $ev){
                if(!in_array($ev['id']??null,$types,true)||(float)($ev['score']??0)>=0||($ev['negated']??false))continue;
                $weighted=abs((float)$ev['score'])*(float)$row['authority']['weight'];
                if($weighted<$threshold)continue;
                $cases[]=['rule_id'=>$ev['id'],'event'=>$row['title'],'weight'=>round($weighted,6),
                    'host'=>$row['authority']['host'],'date'=>$row['date'],'first_seen_at'=>gmdate('c',$firstSeen)];
            }
        }
        usort($cases,static fn($a,$b)=>($b['weight']<=>$a['weight'])?:strcmp($a['event'],$b['event']));
        // A feed snapshot alone cannot prove that all relevant information was available.
        $status=!$items||!$validDates?'unavailable':($cases?'would_hold':'no_trigger_in_observed_feed');
        return ['mode'=>$mode,'status'=>$status,'would_hold'=>$status==='unavailable'?null:(bool)$cases,
            'threshold'=>$threshold,'source_min'=>$sourceMin,'window_hours'=>(int)($window/3600),
            'eligible_headlines'=>$eligibleHeadlines,'dated_headlines'=>$validDates,'missing_dates'=>$missingDates,
            'self_reported_first_seen'=>$selfReportedFirstSeen,'triggers'=>array_slice($cases,0,20),
            'execution_effect'=>'none',
            'interpretation'=>'Paper-Veto aus gelieferten Headlines. Kein Abruf-/Vollständigkeitsnachweis; Timestamp vom Client nicht amtlich attestiert. Keine Orderausführung.' ];
    }

    public function analyze(array $body): array {
        $asset = strtoupper(trim((string)($body['asset'] ?? '')));
        if (!preg_match('/^[A-Z0-9.=-]{1,20}$/D', $asset)) throw new QTEventError('Asset ungültig.');
        $items = $body['items'] ?? null;
        if (!is_array($items) || !array_is_list($items) || count($items) > min(120,(int)($this->config['max_items'] ?? 120))) throw new QTEventError('items muss ein Array mit maximal 120 Meldungen sein.');
        $now = time();$asOfPublication=($body['as_of_publication']??false)===true;
        $results=[];$dedup=[];$weight=0.0;$weighted=0.0;$abs=0.0;$recognized=0;$filtered=0;$duplicates=0;$missing=0;$domains=[];$uncertainties=0;$quality=0.0;
        foreach ($items as $i=>$item) {
            if (!is_array($item)) throw new QTEventError('Ungültiges Nachrichtenobjekt #'.$i.'.');
            $published=$asOfPublication?$this->parseTime($item['date']??null):null;
            // Live retrieval: record actual first observation, never backdate it to publication.
            if (!isset($item['first_seen_at']) && !$asOfPublication) $item['first_seen_at']=gmdate('c',$now);
            $classificationItem=$item;
            if($asOfPublication)unset($classificationItem['first_seen_at']); // Retrospektiv, nicht damals beobachtet.
            $a = $this->classify($classificationItem, $asset, $published!==null&&$published<=$now?$published:$now);
            $fingerprint = hash('sha256', self::norm($a['title']).'|'.substr((string)$a['date'],0,10));
            $duplicate = isset($dedup[$fingerprint]);
            if (!$duplicate && $a['decision']==='relevant') {
                foreach ($results as $previous) {
                    if ($previous['decision']==='relevant' && self::duplicateEvent($a,$previous,max(1,min(96,(int)($this->config['event_cluster_hours']??36)))*3600)) {
                        $duplicate=true;break;
                    }
                }
            }
            if ($duplicate) { $duplicates++; $a['decision']='duplicate';$a['reason']='Gleicher Ereigniscluster im Auswertungsfenster; nicht mehrfach gewichtet.';$a['score']=0.0;$a['recognized']=false; }
            $dedup[$fingerprint]=true;
            if ($a['decision']==='filtered')$filtered++;
            if ($a['date']===null) $missing++;
            if ($a['decision']==='relevant') {
                $recognized++;
                if ($a['uncertain']) $uncertainties++;
                $w=$a['authority']['weight']*exp(-log(2)*max(0,(float)$a['age_hours'])/max(1,(float)($this->config['news_half_life_hours'] ?? 48)));
                $weighted+=$w*$a['score'];$abs+=$w*abs($a['score']);$weight+=$w;
                $quality+=$a['authority']['weight'];
                if ($a['authority']['host']!=='' && $a['authority']['host']!=='news.google.com') $domains[$a['authority']['host']]=true;
            }
            $results[]=$a;
        }
        $full = max(1.0,(float)($this->config['min_weight_for_full_signal'] ?? 3));
        $pressure=$weight>0?$weighted/max($full,$weight):null;
        return [
            'ok'=>true, 'version'=>'quanttrend-events/11.3', 'catalog_version'=>$this->catalog->data['version'],
            'catalog_sha256'=>$this->catalog->sha256, 'asset'=>$asset, 'at'=>gmdate('c',$now),
            'analyses'=>$results, 'summary'=>[
                'received'=>count($items),'recognized'=>$recognized,'filtered'=>$filtered,'duplicates'=>$duplicates,
                'missing_dates'=>$missing,'independent_domains'=>count($domains),
                'pressure'=>$pressure, 'conflict'=>$abs>0?1-abs($weighted)/$abs:null,
                'coverage'=>count($items)>0?$recognized/count($items):null,
                'uncertainty'=>$recognized>0?$uncertainties/$recognized:null,
                'quality'=>$recognized>0?$quality/$recognized:null,
                'aggregation'=>'Quellenklasse × Zeitzerfall × Regelhypothese; Cluster werden einmal gezählt; keine Renditeprognose.'
            ], 'event_guard'=>$this->eventGuard($items,$results,$now), 'guard_format'=>'quanttrend-event-guard/1'

        ];
    }

    /**
     * Causal Event-kNN: shared economic rule and identical issuer are hard constraints.
     * Similarity is soft TF-IDF cosine + event agreement; STOP WORDS do not change an event.
     * All comparable cases MUST have finished before target publication. No external fetching,
     * no database, no inferred benchmark, no use of held-out outcomes to fit parameters.
     * Numeric statistics are descriptive and intentionally never feed the trading model.
     */
    public function analogs(array $input): array {
        $asset = strtoupper(trim((string)($input['asset']??'')));
        if (!preg_match('/^[A-Z0-9.=-]{1,20}$/D',$asset)) throw new QTEventError('Ungültiges Event-kNN-Asset.');
        $horizon = $input['horizon_bars']??5;
        if (!is_int($horizon)||!in_array($horizon,[1,5,20],true))throw new QTEventError('Nur echte 1/5/20-Bar-Horizonte erlaubt.');
        $k=$input['k']??12;
        if(!is_int($k)||$k<3||$k>30)throw new QTEventError('k muss zwischen 3 und 30 liegen.');
        $cases=$input['cases']??null;$query=$input['query']??null;
        if(!is_array($cases)||!array_is_list($cases)||count($cases)>2500||!is_array($query))
            throw new QTEventError('Event-kNN benötigt eine Abfrage und höchstens 2500 echte historische Kursfälle.');
        $queryAt=$this->parseTime($query['date']??null);
        if($queryAt===null||$queryAt>time())throw new QTEventError('Abfrage benötigt einen gültigen, nicht zukünftigen Veröffentlichungszeitpunkt.');
        $queryEvent=$this->classify($query,$asset,$queryAt);
        $queryIds=array_values(array_unique(array_column(array_filter($queryEvent['evidence'],static fn($r)=>$r['score']!=0),'id')));
        if($queryEvent['decision']!=='relevant'||!$queryIds)return [
            'ok'=>true,'status'=>'no_economic_event','asset'=>$asset,'horizon_bars'=>$horizon,
            'query_decision'=>$queryEvent['decision'],'matches'=>[],'eligible'=>0,
            'measured_effect'=>null,'reason'=>'Kein konkreter, assetbezogener Ereignistyp; kein kNN-Handelssignal.'];
        $history=[];$rejected=['invalid'=>0,'unmatured'=>0,'catalog_mismatch'=>0,'unrelated'=>0,'duplicate'=>0];$fingerprints=[];
        $tokenize=static function(string $title): array {
            $stop=['the'=>1,'and'=>1,'for'=>1,'with'=>1,'from'=>1,'that'=>1,'this'=>1,'after'=>1,'into'=>1,
                'und'=>1,'die'=>1,'der'=>1,'das'=>1,'von'=>1,'mit'=>1,'auf'=>1,'ein'=>1,'eine'=>1,'einer'=>1,
                'bei'=>1,'nach'=>1,'des'=>1,'im'=>1,'in'=>1,'of'=>1,'to'=>1,'is'=>1,'has'=>1,'as'=>1,'at'=>1];
            return array_values(array_unique(array_filter(explode(' ',self::norm($title)),static fn($word)=>strlen($word)>=3&&!isset($stop[$word]))));
        };
        $queryWords=$tokenize((string)$query['title']);
        foreach($cases as $row){
            if(!is_array($row)||strtoupper((string)($row['asset']??''))!==$asset||
                ($row['horizon_bars']??null)!==$horizon){$rejected['unrelated']++;continue;}
            $eventAt=$this->parseTime($row['published_at']??null);
            $firstSeen=$this->parseTime($row['first_seen_at']??null);
            $entryAt=$this->parseTime($row['entry_at']??null);$exitAt=$this->parseTime($row['exit_at']??null);
            $entry=$row['entry_close']??null;$exit=$row['exit_close']??null;
            if($eventAt===null||$entryAt===null||$exitAt===null||!is_numeric($entry)||!is_numeric($exit)||
                !is_finite((float)$entry)||!is_finite((float)$exit)||(float)$entry<=0||(float)$exit<=0||
                $eventAt>$entryAt||$entryAt>=$exitAt||$firstSeen===null||$firstSeen>$entryAt){$rejected['invalid']++;continue;}
            if($exitAt>=$queryAt||$exitAt>time()){$rejected['unmatured']++;continue;}
            if(!is_string($row['title']??null)||trim($row['title'])===''||strlen($row['title'])>2400){$rejected['invalid']++;continue;}
            if(($row['catalog_sha256']??null)!==$this->catalog->sha256 && !in_array(($row['catalog_sha256']??null),$this->catalog->data['compatible_catalog_sha256']??[],true)){$rejected['catalog_mismatch']++;continue;}
            $fingerprint=hash('sha256',$row['title']??'').'|'.$eventAt.'|'.$exitAt;
            if(isset($fingerprints[$fingerprint])){$rejected['duplicate']++;continue;}
            $fingerprints[$fingerprint]=true;
            $historical=$this->classify(['title'=>$row['title']??'','date'=>$row['published_at'],'first_seen_at'=>$row['first_seen_at']??null,
                'url'=>$row['url']??null],$asset,max($eventAt,$firstSeen??$eventAt));
            $candidateIds=array_values(array_unique(array_column(array_filter($historical['evidence'],static fn($r)=>$r['score']!=0),'id')));
            $shared=array_values(array_intersect($queryIds,$candidateIds));
            if($historical['decision']!=='relevant'||!$shared||($queryEvent['entity']['company']??null)!==($historical['entity']['company']??null)){
                $rejected['unrelated']++;continue;
            }
            $gross=(float)$exit/(float)$entry-1;
            if(!is_finite($gross)||abs($gross)>.8){$rejected['invalid']++;continue;}
            $history[]=['title'=>(string)$row['title'],'published_at'=>$row['published_at'],
                'entry_at'=>$row['entry_at'],'exit_at'=>$row['exit_at'],
                'entry_ts'=>$entryAt,'exit_ts'=>$exitAt,'event_at'=>$eventAt,
                'gross'=>$gross,'words'=>$tokenize((string)$row['title']),
                'event_ids'=>$shared,'company'=>$historical['entity']['company']];
        }
        // Document frequencies are fitted strictly on AVAILABLE earlier headlines.
        $documents=count($history)+1;$df=[];
        foreach(array_merge([$queryWords],array_column($history,'words')) as $doc){foreach($doc as $token)$df[$token]=($df[$token]??0)+1;}
        $idf=static fn(string $token): float => log(1+($documents+1)/(1+($df[$token]??0)));
        $qvec=[];foreach($queryWords as $word)$qvec[$word]=$idf($word);
        $qNorm=sqrt(array_sum(array_map(static fn($v)=>$v*$v,$qvec)));
        foreach($history as &$row){
            $dot=0.;$norm=0.;foreach($row['words'] as $word){$v=$idf($word);$norm+=$v*$v;$dot+=($qvec[$word]??0.)*$v;}
            $cosine=$qNorm>0&&$norm>0?$dot/($qNorm*sqrt($norm)):0.;
            $overlap=count($row['event_ids'])/max(count($queryIds),1);
            $row['similarity']=round(min(1.,.65*$overlap+.35*$cosine),6);
        }unset($row);
        usort($history,static fn($a,$b)=>($b['similarity']<=>$a['similarity'])?:($b['event_at']<=>$a['event_at']));
        // Nonoverlapping execution windows for each historical case, even when same-day sources differ.
        $chosen=[];$overlaps=0;
        foreach($history as $row){
            if($row['similarity']<.65)continue;
            $conflict=false;
            foreach($chosen as $other){if($row['entry_ts']<=$other['exit_ts']&&$row['exit_ts']>=$other['entry_ts']){$conflict=true;break;}}
            if($conflict){$overlaps++;continue;}
            $chosen[]=$row;if(count($chosen)===$k)break;
        }
        $minEvidence=8;
        $costs=$input['cost_bps']??5.0;
        if(!is_numeric($costs)||!is_finite((float)$costs)||$costs<0||$costs>100)throw new QTEventError('Kosten je Seite: 0–100 bps.');
        $basisVerified=($input['basis_verified']??false)===true;
        $cost=(float)$costs*2/10000;
        $n=count($chosen);$mean=null;$median=null;$wins=null;$wilson=null;
        if($n>0){
            $net=array_map(static fn($r)=>$r['gross']-$cost,$chosen);sort($net);
            $mean=array_sum($net)/$n;
            $median=$n%2===1?$net[intdiv($n,2)]:($net[$n/2-1]+$net[$n/2])/2;
            $positive=array_sum(array_map(static fn($x)=>(int)($x>0),$net));
            $wins=$positive/$n;
            $z=1.95996398454;$z2=$z*$z;$center=($wins+$z2/(2*$n))/(1+$z2/$n);
            $half=$z*sqrt(($wins*(1-$wins)+$z2/(4*$n))/$n)/(1+$z2/$n);
            $wilson=[max(0,$center-$half),min(1,$center+$half)];
        }
        $safe=array_map(static fn($r)=>[
            'title'=>$r['title'],'published_at'=>$r['published_at'],'exit_at'=>$r['exit_at'],
            'similarity'=>$r['similarity'],'event_ids'=>$r['event_ids'],
            'gross_return'=>$basisVerified?$r['gross']:null,'net_return'=>$basisVerified?$r['gross']-$cost:null
        ],$chosen);
        return ['ok'=>true,'version'=>'quanttrend-events/11.3','asset'=>$asset,'horizon_bars'=>$horizon,
            'catalog_sha256'=>$this->catalog->sha256,'query_decision'=>$queryEvent['decision'],
            'query_company'=>$queryEvent['entity']['company'],'query_event_ids'=>$queryIds,
            'eligible'=>count($history),'selected'=>$n,'overlapping_excluded'=>$overlaps,'rejected'=>$rejected,
            'status'=>$n<$minEvidence?'insufficient_history':($basisVerified?'descriptive_only':'unverified_price_basis'),
            'price_basis_verified'=>$basisVerified,'min_usable_cases'=>$minEvidence,'measured_effect'=>($n<$minEvidence||!$basisVerified)?null:[
                'mean_net_return'=>$mean,'median_net_return'=>$median,'positive_share'=>$wins,'wilson_95'=>$wilson,
                'note'=>'Retrospektiver Befund; kNN wurde ohne zufälligen OOS-Nachweis ausgewählt. Kein geschätztes Alpha, kein orderfähiges Handelssignal.'],
            'neighbors'=>$safe,'interpretation'=>'Nur zuvor abgeschlossene, nicht überlappende Ereignis/Kurs-Fälle mit identischem Emittenten und gemeinsamen Wirtschaftsereignis. Ähnlichkeit 65 % Ereignistyp, 35 % TF-IDF-Cosinus. Ohne vom Client verifizierte Bereinigungsbasis werden keine Renditekennzahlen ausgegeben. Keine Kursprognose.'];
    }

    /** Block bootstrap of signed, round-trip-cost-adjusted event returns, ordered by event day. */
    private static function blockNetInference(array $rows, int $block, int $draws=500): array {
        $n=count($rows);
        if($n<max(20,5*$block))return ['n'=>$n,'block'=>$block,'p_two_sided'=>null,'ci_95'=>null,'reason'=>'Zu wenige getrennte Ereignissitzungen für diese Blocklänge.'];
        $values=array_map(static fn($r)=>($r['hypothesis']<0?-1.0:1.0)*(float)$r['return']-
            ((float)$r['return']-(float)$r['cost_adjusted_return']),$rows);
        $estimate=array_sum($values)/$n;
        // Reproducible local PRNG (independent from PHP global mt_rand state).
        $state=(0x9E3779B9 ^ ($n*2654435761) ^ ($block*7919)) & 0xffffffff;
        $random=static function() use (&$state): int {
            $state=($state*1664525+1013904223) & 0xffffffff;
            return $state;
        };
        $rep=[];$starts=$n-$block+1;
        for($j=0;$j<$draws;$j++){
            $sum=0.0;$used=0;
            while($used<$n){$start=$random()%$starts;$limit=min($block,$n-$used);
                for($k=0;$k<$limit;$k++)$sum+=$values[$start+$k];
                $used+=$limit;
            }
            $rep[]=$sum/$n;
        }
        sort($rep,SORT_NUMERIC);
        $left=$rep[(int)floor(.025*($draws-1))];$right=$rep[(int)ceil(.975*($draws-1))];
        $extreme=0;foreach($rep as $r)if(abs($r-$estimate)>=abs($estimate))$extreme++;
        return ['n'=>$n,'block'=>$block,'draws'=>$draws,'mean_signed_net'=>$estimate,
            'ci_95'=>[$left,$right],'p_two_sided'=>($extreme+1)/($draws+1),
            'reason'=>'Moving-Block-Bootstrap, zentrierte Referenz-Nullhypothese; abhängig von Blocklänge und Stichprobe.'];
    }

    private static function informationCoefficient(array $rows): ?float {
        if(count($rows)<20)return null;
        $x=array_map(static fn($r)=>(float)$r['hypothesis'],$rows);
        $y=array_map(static fn($r)=>(float)$r['cost_adjusted_return'],$rows);
        $rank=static function(array $values): array {
            $sorted=$values;asort($sorted,SORT_NUMERIC);$n=count($values);$out=[];$keys=array_keys($sorted);
            for($i=0;$i<$n;){$j=$i+1;while($j<$n&&$sorted[$keys[$j]]===$sorted[$keys[$i]])$j++;
                $avg=($i+$j+1)/2.0;for($k=$i;$k<$j;$k++)$out[$keys[$k]]=$avg;$i=$j;}
            ksort($out,SORT_NUMERIC);return array_values($out);
        };
        $xr=$rank($x);$yr=$rank($y);$mx=array_sum($xr)/count($xr);$my=array_sum($yr)/count($yr);
        $num=0.;$dx=0.;$dy=0.;foreach($xr as $i=>$v){$a=$v-$mx;$b=$yr[$i]-$my;$num+=$a*$b;$dx+=$a*$a;$dy+=$b*$b;}
        return $dx>1e-12&&$dy>1e-12?$num/sqrt($dx*$dy):null;
    }
    private static function binomialUpperTail(int $hits,int $n): ?float {
        if($n<1||$hits<0||$hits>$n)return null;
        // Log-space exact binomial survival under a one-sided iid p=0.5 reference null.
        $logP=-$n*log(2.0);$logs=[];$max=-INF;
        for($k=0;$k<=$n;$k++){
            if($k>0)$logP+=log($n-$k+1)-log($k);
            if($k>=$hits){$logs[]=$logP;if($logP>$max)$max=$logP;}
        }
        $sum=0.0;foreach($logs as $val)$sum+=exp($val-$max);
        return max(0.0,min(1.0,exp($max)*$sum));
    }

    /**
     * Historical validation of a catalogue rule. Only MATURED outcomes. Must include event
     * publication time and observed entry/exit prices; never fabricates historical bars.
     * Chronological 70/30 selection/test; purges training cases whose exit overlaps test start.
     */
    public function study(array $input): array {
        $cases=$input['cases'] ?? null;
        if (!is_array($cases)||!array_is_list($cases)||count($cases)>2500) throw new QTEventError('cases muss eine Liste mit maximal 2500 historischen Fällen sein.');
        $costBps=(float)($input['cost_bps']??0.0);
        if(!is_finite($costBps)||$costBps<0||$costBps>100)throw new QTEventError('cost_bps zwischen 0 und 100 erwartet.');
        $rows=[];$rejected=['invalid'=>0,'future_or_unmatured'=>0,'no_event'=>0,'duplicate'=>0];$seen=[];
        foreach ($cases as $case) {
            if (!is_array($case)){$rejected['invalid']++;continue;}
            $eventAt=$this->parseTime($case['published_at']??null);
            $firstSeen=$this->parseTime($case['first_seen_at']??null);
            $entryAt=$this->parseTime($case['entry_at']??null);
            $exitAt=$this->parseTime($case['exit_at']??null);
            $entry=$case['entry_close']??null;$exit=$case['exit_close']??null;
            $asset=strtoupper((string)($case['asset']??''));
            if (!preg_match('/^[A-Z0-9.=-]{1,20}$/D',$asset)||!is_numeric($entry)||!is_numeric($exit)||!is_finite((float)$entry)||!is_finite((float)$exit)||$entry<=0||$exit<=0||$eventAt===null||$entryAt===null||$exitAt===null||$eventAt>$entryAt||$entryAt >= $exitAt||$firstSeen===null||$firstSeen>$entryAt){$rejected['invalid']++;continue;}
            if ($exitAt>time()||$entryAt>time()||$eventAt>time()){$rejected['future_or_unmatured']++;continue;}
            $event=$this->classify(['title'=>$case['title']??'','date'=>$case['published_at'],'first_seen_at'=>$case['first_seen_at']??null,'url'=>$case['url']??null], $asset, max($eventAt,$firstSeen??$eventAt));
            // classify at publication time to avoid rejecting mature old historical headlines.
            if ($event['decision']!=='relevant' || !$event['evidence']){$rejected['no_event']++;continue;}
            $ids=array_values(array_unique(array_column(array_filter($event['evidence'],static fn($e)=>$e['score']!=0),'id')));
            if (!$ids){$rejected['no_event']++;continue;}
            $id=hash('sha256',$asset.'|'.$eventAt.'|'.self::norm($event['title']).'|'.$exitAt);
            if(isset($seen[$id])){$rejected['duplicate']++;continue;}$seen[$id]=true;
            $ret=(float)$exit/(float)$entry-1;
            // Data integrity; extreme split/revision signals are not silently winsorized.
            if(!is_finite($ret)||abs($ret)>0.80){$rejected['invalid']++;continue;}
            $benchmark = $case['benchmark_return']??null;
            if($benchmark!==null && (!is_numeric($benchmark)||!is_finite((float)$benchmark)||abs((float)$benchmark)>0.8)){
                $rejected['invalid']++;continue;
            }
            $horizon=$case['horizon_bars']??null;
            if ($horizon!==null && (!is_int($horizon)||$horizon<1||$horizon>max(1,(int)($this->config['max_study_horizon_bars']??20)))){
                $rejected['invalid']++;continue;
            }
            foreach($ids as $kind)$rows[]=['kind'=>$kind,'asset'=>$asset,'horizon'=>$horizon,'event'=>$eventAt,
                'entry'=>$entryAt,'exit'=>$exitAt,'return'=>$ret,'cost_adjusted_return'=>$ret-2*$costBps/10000,
                'excess_return'=>$benchmark===null?null:$ret-(float)$benchmark-2*$costBps/10000,
                'hypothesis'=>$event['score'],
                'signed_net_return'=>(($event['score']<0?-1:1)*$ret)-2*$costBps/10000,
                'pre_entry_return'=>(isset($case['pre_entry_return'])&&is_numeric($case['pre_entry_return'])&&is_finite((float)$case['pre_entry_return']))?(float)$case['pre_entry_return']:null];
        }
        $groups=[];
        foreach($rows as $row){
            $key=$row['kind'].'@'.$row['asset'].($row['horizon']===null?'':'@'.$row['horizon'].'bar');
            $groups[$key][]=$row;
        }
        $output=[];
        foreach($groups as $key=>$events){
            usort($events,static fn($a,$b)=>$a['event']<=>$b['event']);
            // Stories arriving on the same entry session are one dependent event-day observation
            // for the same rule/asset/horizon. Keep first chronologically, never multiply wins.
            $dateSeen=[];$unique=[];
            foreach ($events as $e) {
                $session=gmdate('Y-m-d',$e['entry']);
                if(isset($dateSeen[$session]))continue;
                $dateSeen[$session]=true;$unique[]=$e;
            }
            $clustered=count($events)-count($unique);
            $events=$unique;
            if(count($events)<2){
                $output[$key]=['training'=>['n'=>0],'untouched_test'=>['n'=>0],
                    'collapsed_same_session'=>$clustered,'usable_for_claim'=>false,
                    'caveat'=>'Weniger als zwei unabhängige Ereignissitzungen; keine chronologische Auswertung möglich.'];
                continue;
            }
            $index=max(1,min(count($events)-1,(int)floor(count($events)*.7)));
            $border=$events[$index]['event']??PHP_INT_MAX;
            $train=array_values(array_filter(array_slice($events,0,$index),static fn($r)=>$r['exit']<$border));
            $test=array_slice($events,$index);
            $stats=static function(array $r): array {
                $n=count($r);if(!$n)return ['n'=>0,'mean_raw_return'=>null,'signed_hit_rate'=>null,'wilson_95'=>null];
                $mean=array_sum(array_column($r,'return'))/$n;
                $netMean=array_sum(array_column($r,'cost_adjusted_return'))/$n;
                $directionalNetMean=array_sum(array_column($r,'signed_net_return'))/$n;
                $excess=array_values(array_filter(array_column($r,'excess_return'),static fn($v)=>$v!==null));
                $excessMean=$excess ? array_sum($excess)/count($excess) : null;
                $success=count(array_filter($r,static fn($e)=>$e['return']*$e['hypothesis']>0));
                $p=$success/$n;$z=1.95996398454;$z2=$z*$z;
                $centre=($p+$z2/(2*$n))/(1+$z2/$n);
                $half=$z*sqrt(($p*(1-$p)+$z2/(4*$n))/$n)/(1+$z2/$n);
                return ['n'=>$n,'signed_hits'=>$success,'mean_raw_return'=>$mean,'mean_after_cost_return'=>$netMean,'mean_signed_net_return'=>$directionalNetMean,
                    'benchmark_cases'=>count($excess),'mean_benchmark_excess_return'=>$excessMean,
                    'pre_entry_gap_cases'=>count(array_filter($r,static fn($e)=>$e['pre_entry_return']!==null)),
                    'mean_pre_entry_gap'=>($pre=array_values(array_filter(array_column($r,'pre_entry_return'),static fn($v)=>$v!==null)))?array_sum($pre)/count($pre):null,
                    'signed_hit_rate'=>$p,'wilson_95'=>[max(0,$centre-$half),min(1,$centre+$half)]];
            };
            $trainS=$stats($train);$testS=$stats($test);
            $blockLength=max(2,min(12,(int)ceil(pow(max(1,count($test)),1/3))));
            $testS['post_execution_spearman_ic']=self::informationCoefficient($test);
            $testS['block_bootstrap']=self::blockNetInference($test,$blockLength);
            $testS['block_sensitivity']=[self::blockNetInference($test,2,300),self::blockNetInference($test,8,300)];
            $testS['holm_p_block_reference']=null;
            $testS['one_sided_binomial_p_iid_reference']=self::binomialUpperTail((int)($testS['signed_hits']??0),(int)$testS['n']);
            $testS['bonferroni_p_iid_reference']=min(1.0,($testS['one_sided_binomial_p_iid_reference']??1.0)*max(1,count($groups)));
            $output[$key]=['training'=>$trainS,'untouched_test'=>$testS,'purged_training_cases'=>$index-count($train),'collapsed_same_session'=>$clustered,
                'usable_for_claim'=>false, 'retrospective_candidate'=>$trainS['n']>=30 && $testS['n']>=20 && $testS['wilson_95']!==null &&
                    $testS['wilson_95'][0]>.5 && $testS['bonferroni_p_iid_reference']<.05,
                'caveat'=>'OOS geclustert, aber das einseitige Binomial-p setzt unabhängige Trades voraus und ist deshalb nur eine Referenzgröße. Bonferroni korrigiert über alle ausgewerteten Regel/Asset/Horizont-Kombinationen. Keine belastbare Alpha-Signifikanz ohne robuste Block-/Regimeprüfung.'];
        }
        // Holm-Bonferroni over every inspected rule/asset/horizon, not only the best result.
        $ordered=[];
        foreach($output as $key=>$row){
            $pv=$row['untouched_test']['block_bootstrap']['p_two_sided']??null;
            if(is_float($pv)||is_int($pv))$ordered[$key]=(float)$pv;
        }
        asort($ordered,SORT_NUMERIC);
        $m=count($ordered);$rank=0;$running=0.0;
        foreach($ordered as $key=>$pv){
            $running=max($running,min(1.0,($m-$rank)*$pv));$rank++;
            $output[$key]['untouched_test']['holm_p_block_reference']=$running;
            $output[$key]['retrospective_block_pass']=($running<.05 &&
                ($output[$key]['untouched_test']['block_bootstrap']['ci_95'][0]??0)>0);
        }
        return ['ok'=>true,'catalog_version'=>$this->catalog->data['version'],'cases_received'=>count($cases),'cases_used'=>count($rows),
            'cost_bps_per_side'=>$costBps,'basis_verified'=>($input['basis_verified']??false)===true,'tested_rule_asset_combinations'=>count($output),
            'rejected'=>$rejected,'results'=>$output,
            'interpretation'=>'Point-in-Time: first_seen_at muss vor dem Folgesitzungs-Einstieg liegen, unbekannte Erstbeobachtungen werden nicht als historisch verfügbar ausgegeben. Eintritt nach Verfügbarkeit am nächsten Sitzungs-Open; Spearman-IC nur bei mindestens 20 Fällen mit variierenden Textprioren. Retrospektive purged 70/30-Diagnostik. Signierte Nettorenditen im Moving-Block-Bootstrap (Sensitivität 2/8 Sitzungen) und Holm-Korrektur über die ausgewertete Familie ergänzen die IID-Referenz. Ohne prospektiven Bestätigungssatz NIE usable_for_claim; keine Live-Renditegarantie.'];
    }
}

function qtEventsResponse(array $payload, int $status=200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);exit;
}

// Enables unit tests to load the classes without serving an HTTP request.
if (!defined('QT_EVENTS_LIBRARY_ONLY')) {
    try {
        $cat=new QTEventCatalog(__DIR__.'/event_catalog.json');
        $engine=new QTEventEngine($cat);
        $action=$_GET['action']??'health';
        if (!is_string($action)) throw new QTEventError('Aktion ungültig.');
        $method=$_SERVER['REQUEST_METHOD']??'GET';
        if($action==='catalog' && $method==='GET') {
            qtEventsResponse(['ok'=>true,'version'=>'quanttrend-events/11.3','catalog_version'=>$cat->data['version'],
                'sha256'=>$cat->sha256,'rules'=>array_map(static fn($e)=>[
                    'id'=>$e['id'],'label'=>$e['label'],'scope'=>$e['scope'],'prior'=>$e['prior'],
                    'pattern_count'=>count($e['patterns'])+count($e['regex']??[]),'tags'=>$e['tags']??[]
                ],$cat->data['events']),'issuers'=>array_map(static fn($issuer)=>$issuer['name'],$cat->data['issuers']),
                'interpretation'=>'Regelprioren sind ausschließlich Text-Hypothesen, keine Renditekoeffizienten.']);
        }
        if ($action==='health'&&$method==='GET') {
            qtEventsResponse(['ok'=>true,'version'=>'quanttrend-events/11.3','catalog_version'=>$cat->data['version'],
                'catalog_sha256'=>$cat->sha256,'rules'=>count($cat->data['events']),'issuers'=>count($cat->data['issuers']),
                'capabilities'=>['analyze','matrix','study','analogs','graph','catalog','paper_event_guard','block_bootstrap_holm'],'storage'=>'keine zusätzliche Datenbank; Katalog in JSON, Ergebnisse in QuantTrend-Browserhistorie',
                'external_fetch'=>false,'point_in_time'=>true,'graph_paths'=>true]);
        }
        if (!in_array($action,['analyze','matrix','study','analogs','graph'],true)) throw new QTEventError('Unbekannte Aktion.',404);
        if ($method!=='POST') throw new QTEventError('Aktion erfordert POST.',405);
        $type=strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]));
        if ($type!=='application/json') throw new QTEventError('Nur application/json erlaubt.',415);
        // Browser cross-origin JSON POST triggers preflight; the server does not allow CORS.
        $origin=$_SERVER['HTTP_ORIGIN']??'';
        if($origin!==''){
            $requested=parse_url($origin);
            $host=strtolower((string)($_SERVER['HTTP_HOST']??''));
            $proto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO']??(($_SERVER['HTTPS']??'')==='on'?'https':'http')));
            $targetPort = (int)($_SERVER['SERVER_PORT']??($proto==='https'?443:80));
            $originPort = (int)($requested['port']??(($requested['scheme']??'')==='https'?443:80));
            if (!$requested || strtolower((string)($requested['host']??''))!==strtolower((string)(parse_url('https://'.$host,PHP_URL_HOST)??''))
                || ($requested['scheme']??'')!==$proto || ($targetPort!==$originPort && !in_array($targetPort,[80,443],true)))
                    throw new QTEventError('Nur Same-Origin-Aufrufe erlaubt.',403);
        }
        $size=(int)($_SERVER['CONTENT_LENGTH']??0);
        if($size>1024*1024) throw new QTEventError('Maximal 1 MiB JSON.',413);
        $fh=fopen('php://input','rb');$raw=stream_get_contents($fh,1024*1024+1);fclose($fh);
        if($raw===false||strlen($raw)>1024*1024) throw new QTEventError('JSON überschreitet 1 MiB.',413);
        try{$input=json_decode($raw,true,128,JSON_THROW_ON_ERROR);}catch(JsonException){throw new QTEventError('JSON ungültig.');}
        if(!is_array($input))throw new QTEventError('JSON-Objekt erwartet.');
        qtEventsResponse(match($action){
            'analyze'=>$engine->analyze($input),'matrix'=>$engine->matrix($input),
            'study'=>$engine->study($input),'analogs'=>$engine->analogs($input),'graph'=>$engine->graph($input)
        });
    } catch(QTEventError $e){qtEventsResponse(['ok'=>false,'error'=>$e->getMessage()],$e->status);}
      catch(Throwable $e){error_log('[QuantTrend events] '.get_class($e).': '.$e->getMessage());qtEventsResponse(['ok'=>false,'error'=>'Interner Ereignisfehler. Details im Server-Log.'],500);}
}
