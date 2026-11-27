<?php
declare(strict_types=1);
// QuantTrend: allowlisted, read-only relay. PHP 8.1+; cURL preferred, verified HTTPS streams as fallback.
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
function reply(int $status, string $message, int $retry=0): void {
    http_response_code($status); header('Content-Type: application/json; charset=utf-8');
    if ($retry>0) header('Retry-After: '.max(1,$retry));
    echo json_encode(['error'=>$message],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit;
}
// Read-only production diagnostics. Fixed upstream URLs only; no user-controlled destination.
if (($_SERVER['REQUEST_METHOD']??'GET')==='GET' && ($_GET['diagnostics']??'')==='1') {
    header('Content-Type: application/json; charset=utf-8');
    $assets=[
        'EUNL.DE'=>['yahoo'=>'EUNL.DE','stooq'=>'eunl.de'],
        'XMAW.DE'=>['yahoo'=>'XMAW.DE','stooq'=>'xmaw.de'],
        'IQQE.DE'=>['yahoo'=>'IQQE.DE','stooq'=>'iqqe.de'],
        'SPY'=>['yahoo'=>'SPY','stooq'=>'spy.us']
    ];
    $requested=$_GET['asset']??'';
    if ($requested!=='' && !isset($assets[$requested])) reply(400,'Unbekanntes Diagnose-Asset.');
    $selected=$requested!==''?[$requested=>$assets[$requested]]:$assets;
    $probe=function(string $url): array {
        $body='';$status=0;$error=null;$tooLarge=false;
        if (extension_loaded('curl')) {
            $ch=curl_init($url);
            curl_setopt_array($ch,[
                CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>18,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_USERAGENT=>'QuantTrend/12.0.5 production-diagnostics',
                CURLOPT_WRITEFUNCTION=>function($c,$chunk)use(&$body,&$tooLarge){
                    if(strlen($body)+strlen($chunk)>2*1024*1024){$tooLarge=true;return 0;}
                    $body.=$chunk;return strlen($chunk);
                }
            ]);
            $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
            if($ok===false)$error=curl_error($ch);
            curl_close($ch);
        } elseif(filter_var(ini_get('allow_url_fopen'),FILTER_VALIDATE_BOOLEAN)) {
            $ctx=stream_context_create([
                'http'=>['method'=>'GET','timeout'=>18,'ignore_errors'=>true,'follow_location'=>0,'max_redirects'=>0,
                    'header'=>"User-Agent: QuantTrend/12.0.5 production-diagnostics\r\nAccept: application/json,text/csv\r\n"],
                'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false]
            ]);
            $stream=@fopen($url,'rb',false,$ctx);
            if($stream!==false){
                foreach(($http_response_header??[]) as $i=>$line){
                    if($i===0&&preg_match('~^HTTP/\S+\s+(\d{3})~',$line,$m))$status=(int)$m[1];
                }
                $body=(string)stream_get_contents($stream,2*1024*1024+1);fclose($stream);
                if(strlen($body)>2*1024*1024){$tooLarge=true;$body='';}
            } else $error='HTTPS-Verbindung fehlgeschlagen (PHP-Streams).';
        } else $error='Weder PHP-cURL noch allow_url_fopen ist verfügbar.';
        return ['http'=>$status,'bytes'=>strlen($body),'error'=>$error,'too_large'=>$tooLarge,'body'=>$body];
    };
    $results=[];
    foreach($selected as $asset=>$symbols){
        $yahooUrl='https://query1.finance.yahoo.com/v8/finance/chart/'.rawurlencode($symbols['yahoo']).'?range=5d&interval=1d';
        $y=$probe($yahooUrl);$yj=json_decode($y['body'],true);$yr=$yj['chart']['result'][0]??null;
        $yt=$yr['timestamp']??[];$yq=$yr['indicators']['quote'][0]['close']??[];
        $ybars=0;$ydates=[];
        foreach($yt as $i=>$stamp)if(isset($yq[$i])&&is_numeric($yq[$i])){$ybars++;$ydates[]=gmdate('Y-m-d',(int)$stamp);}
        $results[$asset]['yahoo']=['http'=>$y['http'],'bars'=>$ybars,'first'=>$ydates[0]??null,'last'=>$ydates?end($ydates):null,
            'provider_error'=>$yj['chart']['error']['description']??null,'transport_error'=>$y['error'],'too_large'=>$y['too_large']];
        $stooqUrl='https://stooq.com/q/d/l/?s='.rawurlencode($symbols['stooq']).'&i=d';
        $s=$probe($stooqUrl);$lines=preg_split('/\r\n|\r|\n/',trim($s['body']));$sbars=0;$sdates=[];
        if(count($lines)>1&&str_starts_with($lines[0],'Date,'))
            foreach(array_slice($lines,1) as $line){$cols=str_getcsv($line);if(count($cols)>=5&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$cols[0])){$sbars++;$sdates[]=$cols[0];}}
        $results[$asset]['stooq']=['http'=>$s['http'],'bars'=>$sbars,'first'=>$sdates[0]??null,'last'=>$sdates?end($sdates):null,
            'transport_error'=>$s['error'],'too_large'=>$s['too_large']];
    }
    echo json_encode(['ok'=>true,'diagnostics'=>'quanttrend-market-relay/1','server'=>[
        'php'=>PHP_VERSION,'curl'=>extension_loaded('curl'),'allow_url_fopen'=>filter_var(ini_get('allow_url_fopen'),FILTER_VALIDATE_BOOLEAN)
    ],'checked_at'=>gmdate('c'),'assets'=>$results],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') reply(405,'Nur GET erlaubt.');
if (!extension_loaded('curl') && !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) reply(503,'Weder PHP-cURL noch allow_url_fopen ist aktiviert.');
$url=$_GET['url']??'';
if (!is_string($url)||strlen($url)>4096) reply(400,'Ungültige Zieladresse.');
$u=parse_url($url);
if (!$u||($u['scheme']??'')!=='https'||isset($u['user'])||isset($u['pass'])||isset($u['fragment'])||isset($u['port'])) reply(400,'Nur freigegebene HTTPS-Ziele ohne Zugangsdaten.');
$host=strtolower($u['host']??''); $path=$u['path']??'/';
$allowed=[
 'query1.finance.yahoo.com'=>'~^/v8/finance/chart/[A-Za-z0-9%._=^-]+$~D',
 'query2.finance.yahoo.com'=>'~^/v8/finance/chart/[A-Za-z0-9%._=^-]+$~D',
 'stooq.com'=>'~^/q/d/l/$~D',
 'data-api.binance.vision'=>'~^/api/v3/klines$~D',
 'api.binance.com'=>'~^/api/v3/klines$~D',
 'api.gdeltproject.org'=>'~^/api/v2/doc/doc/?$~D',
 'data-api.ecb.europa.eu'=>'~^/service/data/(EXR|FM)/[A-Za-z0-9.]+$~D',
 'api.frankfurter.dev'=>'~^/(v2/rates|v1/latest)$~D',
 'ec.europa.eu'=>'~^/eurostat/api/dissemination/statistics/1.0/data/prc_hicp_(minr|manr)$~D',
 'api.bls.gov'=>'~^/publicAPI/v2/timeseries/data/LNS14000000$~D',
 'fred.stlouisfed.org'=>'~^/graph/fredgraph.csv$~D',
 'earthquake.usgs.gov'=>'~^/fdsnws/event/1/query$~D',
 'www.ecb.europa.eu'=>'~^/rss/press.html$~D',
 'www.federalreserve.gov'=>'~^/feeds/press_all.xml$~D',
 'www.tagesschau.de'=>'#^/(?:infoservices/alle-meldungen-100|ausland/index|wirtschaft/index|wirtschaft/technologie/index)~rss2\.xml$#D',
 'www.deutschlandfunk.de'=>'~^/nachrichten-100\.rss$~D',
 'news.google.com'=>'~^/rss/search$~D'
];
// The tilde in the Tagesschau filename is literal.
// All four configured Tagesschau feeds are allowlisted above.
if (!isset($allowed[$host])||!preg_match($allowed[$host],$path)) reply(403,'Ziel ist nicht als Datenquelle freigegeben.');
parse_str($u['query']??'',$params);
foreach(array_keys($params) as $key) if(preg_match('/key|token|secret|password|auth/i',(string)$key)) reply(403,'API-Schlüssel werden nicht über diesen Relay übertragen.');
if ($host==='fred.stlouisfed.org'&&($params['id']??'')!=='UNRATE') reply(403,'Nur die konfigurierte FRED-Reihe UNRATE ist erlaubt.');
// The Google endpoint is narrowly limited to the RSS search with the configured DE locale.
if ($host==='news.google.com') {
    if (array_diff(array_keys($params),['q','hl','gl','ceid']) || !is_string($params['q']??null)
        || strlen($params['q'])>400 || trim($params['q'])===''
        || ($params['hl']??null)!=='de' || ($params['gl']??null)!=='DE'
        || ($params['ceid']??null)!=='DE:de') reply(403,'Google News RSS: Nur die konfigurierte öffentliche DE-Suchabfrage ist erlaubt.');
}
if (in_array($host,['www.deutschlandfunk.de','www.tagesschau.de','www.ecb.europa.eu','www.federalreserve.gov'],true)&&$params) reply(403,'RSS/Atom-Endpunkte erlauben keine Abfrageparameter.');
if ($host==='api.gdeltproject.org'&&(($params['mode']??'')!=='artlist'||($params['format']??'')!=='json'||!is_scalar($params['maxrecords']??null)||(int)$params['maxrecords']>20)) reply(400,'Nur GDELT-Artikellisten mit maximal 20 Treffern.');
ksort($params);$url='https://'.$host.$path.($params?'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986):'');
$dir=rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR).'/quanttrend-'.substr(hash('sha256',__DIR__),0,20);
if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir)) reply(503,'Cache-Verzeichnis nicht beschreibbar.');
$key=hash('sha256',$url);$cache=$dir.'/'.$key.'.json';
$ttl=in_array($host,['query1.finance.yahoo.com','query2.finance.yahoo.com','stooq.com'],true)?3600:
    (in_array($host,['news.google.com','www.deutschlandfunk.de','www.tagesschau.de'],true)?600:900);
function cached(string $file,int $ttl): ?array {if(!is_file($file)||time()-(int)filemtime($file)>$ttl)return null;$d=json_decode((string)@file_get_contents($file),true);return is_array($d)&&isset($d['body'],$d['type'])?$d:null;}
function outputData(array $data,string $status): void {header('Content-Type: '.$data['type']);header('X-QuantTrend-Cache: '.$status);echo $data['body'];exit;}
if($d=cached($cache,$ttl))outputData($d,'HIT');
// Distinct topical RSS queries may run concurrently; identical requests retain an exclusive lock.
$group=$host==='news.google.com'?'rss-google-'.$key:
    (str_starts_with($host,'query')&&str_ends_with($host,'.finance.yahoo.com')?'yahoo':$host);
$lock=@fopen($dir.'/'.hash('sha256',$group).'.lock','c+');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))reply(503,'Quelle wird bereits abgefragt. Später erneut versuchen.',15);
if($d=cached($cache,$ttl))outputData($d,'HIT');
rewind($lock);$state=json_decode((string)stream_get_contents($lock),true)?:[];
function remember($lock,array $state): void {rewind($lock);ftruncate($lock,0);fwrite($lock,json_encode($state));fflush($lock);}
$now=microtime(true);$until=(float)($state['until']??0);
if($until>$now)reply(429,'Anbieter pausiert bis '.gmdate('c',(int)ceil($until)).'. Cache und vorhandene Daten bleiben erhalten.',(int)ceil($until-$now));
$isGdelt=$host==='api.gdeltproject.org';
if($isGdelt&&$now-(float)($state['last']??0)<6)reply(429,'Zentrale GDELT-Abfragepause aktiv.',(int)ceil(6-($now-(float)$state['last'])));
$state['last']=$now;remember($lock,$state);
$body='';$headers=[];$tooLarge=false;$status=0;$ok=false;
if(extension_loaded('curl')){
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_USERAGENT=>'QuantTrend/12.0.1 (personal research; cached public data)',CURLOPT_ENCODING=>'',CURLOPT_HEADERFUNCTION=>function($c,$line)use(&$headers){$pair=explode(':',$line,2);if(count($pair)===2)$headers[strtolower(trim($pair[0]))]=trim($pair[1]);return strlen($line);},CURLOPT_WRITEFUNCTION=>function($c,$chunk)use(&$body,&$tooLarge){if(strlen($body)+strlen($chunk)>20*1024*1024){$tooLarge=true;return 0;}$body.=$chunk;return strlen($chunk);}]);
    $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
}else{
    // No redirects, no compression, verified TLS, fixed HTTPS allowlist and bounded read.
    $context=stream_context_create([
        'http'=>['method'=>'GET','timeout'=>30,'ignore_errors'=>true,'follow_location'=>0,'max_redirects'=>0,
            'protocol_version'=>1.1,'header'=>"User-Agent: QuantTrend/12.0.1 (personal research; cached public data)\r\nAccept-Encoding: identity\r\n"],
        'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,'SNI_enabled'=>true,'peer_name'=>$host]
    ]);
    $stream=@fopen($url,'rb',false,$context);
    if($stream!==false){
        foreach(($http_response_header??[]) as $index=>$line){
            if($index===0&&preg_match('~^HTTP/\S+\s+(\d{3})~',$line,$match))$status=(int)$match[1];
            elseif(($pair=explode(':',$line,2))&&count($pair)===2)$headers[strtolower(trim($pair[0]))]=trim($pair[1]);
        }
        $read=stream_get_contents($stream,20*1024*1024+1);fclose($stream);
        if($read!==false){$body=$read;$tooLarge=strlen($body)>20*1024*1024;$ok=!$tooLarge&&$status===200&&$body!=='';}
    }
}
$limited=$status===429||($isGdelt&&preg_match('/please limit requests|too many requests|one (?:request )?every (?:5|five) seconds/i',$body));
if($limited){$strikes=min(5,(int)($state['strikes']??0)+1);$retry=$headers['retry-after']??'';$seconds=is_numeric($retry)?(float)$retry:max(0,(strtotime($retry)?:0)-time());$pause=max($seconds,min(1800,60*(2**($strikes-1))));$state['until']=microtime(true)+$pause;$state['strikes']=$strikes;remember($lock,$state);reply(429,'Anbieter-Abfragelimit. Zentrale Pause aktiv; kein erneuter Abruf vor '.gmdate('c',(int)ceil($state['until'])).'.',(int)ceil($pause));}
if($ok===false||$status!==200||$body===''){$state['until']=microtime(true)+30;remember($lock,$state);reply(502,$tooLarge?'Anbieterantwort überschreitet 20 MB.':'Quelle derzeit nicht abrufbar'.($status?' (HTTP '.$status.')':' (Verbindung/Zeitlimit)').'.',30);}
if($isGdelt){$j=json_decode($body,true);if(!is_array($j)||!isset($j['articles'])||!is_array($j['articles'])){$state['until']=microtime(true)+60;remember($lock,$state);reply(502,'GDELT liefert keine gültige Artikelliste.',60);}}
$type='text/plain; charset=utf-8';$trim=ltrim($body);if(str_starts_with($trim,'{')||str_starts_with($trim,'['))$type='application/json; charset=utf-8';elseif(str_starts_with($trim,'<')){if(preg_match('/<(?:!doctype\\s+html|html)\\b/i',$trim))reply(502,'Anbieter liefert HTML statt Daten.');$type='application/xml; charset=utf-8';}
$state['until']=0;$state['strikes']=0;remember($lock,$state);
$data=['body'=>$body,'type'=>$type];$tmp=tempnam($dir,'write-');if($tmp!==false){chmod($tmp,0600);if(file_put_contents($tmp,json_encode($data,JSON_INVALID_UTF8_SUBSTITUTE))!==false)rename($tmp,$cache);else @unlink($tmp);}
// Bounded cleanup of expired cache entries; locks are never removed.
if(random_int(1,20)===1){$n=0;foreach(glob($dir.'/*.json')?:[] as $old){if(++$n>200)break;if(time()-(int)filemtime($old)>86400)@unlink($old);}}
outputData($data,'MISS');
