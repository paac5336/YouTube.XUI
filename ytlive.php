<?php
/*
 * ytlive.php v3 — YouTube Live -> HLS (.m3u8) para XUI
 * CAOOSS.tv (baseado no script original de @BelAScripts)
 *
 * UTILIZAÇÃO (fonte do canal no XUI):
 *   Por vídeo:  http://127.0.0.1:8088/ytlive.php?v=guvwk0S17w4
 *   Por canal:  http://127.0.0.1:8088/ytlive.php?c=@handle
 *               http://127.0.0.1:8088/ytlive.php?c=UCxxxxxxxxxxxxxxxxxxxxxx
 *   Canal com vários diretos — escolher pelo título (&t=palavras):
 *               http://127.0.0.1:8088/ytlive.php?c=@handle&t=Fabrica
 *               (todas as palavras têm de estar no título; maiúsculas e acentos não contam)
 *   Ver os diretos ativos de um canal:
 *               curl "http://127.0.0.1:8088/ytlive.php?c=@handle&list=1"
 *   Diagnóstico:
 *               curl "http://127.0.0.1:8088/ytlive.php?v=guvwk0S17w4&debug=1"
 *
 * Como obtém o link (tenta por esta ordem até um funcionar):
 *   1. página web do YouTube (método antigo)
 *   2. API interna do YouTube como app de iPhone (IOS)
 *   3. API interna do YouTube como app Android VR
 *   4. yt-dlp (se estiver instalado em /usr/local/bin/yt-dlp)
 */

// ===================== CONFIGURAÇÃO =====================
const ONLY_LOCALHOST = true;          // true = só o próprio servidor pode usar o script
const CACHE_DIR      = '/tmp/ytlive_cache';
const CACHE_MARGIN   = 600;           // renovar o link 10 min antes de expirar
const CACHE_FALLBACK = 3 * 3600;      // validade se o link não indicar expiração (3 h)
const HTTP_TIMEOUT   = 15;            // segundos
const YTDLP          = '/usr/local/bin/yt-dlp';
const WEB_UA         = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                     . '(KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';
// ========================================================

function fail(int $code, string $msg, array $log = []): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    error_log("[ytlive] $code $msg | " . implode(' | ', $log));
    echo $msg, "\n";
    if (isset($_GET['debug']) && $log) echo "\nTentativas:\n  - ", implode("\n  - ", $log), "\n";
    exit;
}

function http(string $url, string $method = 'GET', array $headers = [], ?string $body = null): ?string {
    $opts = ['http' => [
        'method'        => $method,
        'timeout'       => HTTP_TIMEOUT,
        'ignore_errors' => true,
        'header'        => implode("\r\n", $headers),
    ]];
    if ($body !== null) $opts['http']['content'] = $body;
    $r = @file_get_contents($url, false, stream_context_create($opts));
    return ($r === false || $r === '') ? null : $r;
}

function clean_url(string $u): string {
    $u = str_replace('\\\\', '\\', $u);
    $d = json_decode('"' . $u . '"');
    if (is_string($d)) $u = $d;
    return str_replace('\/', '/', $u);
}

function is_hls(?string $u): bool {
    return $u !== null && (bool)preg_match('#^https://[a-z0-9.-]+\.googlevideo\.com/\S+#i', $u);
}

// --- Método 2/3: API interna (innertube) com cliente de app ---
function innertube(string $videoId, string $client): ?string {
    $clients = [
        'IOS' => [
            'id' => 5, 'ua' => 'com.google.ios.youtube/20.10.4 (iPhone16,2; U; CPU iOS 18_3_2 like Mac OS X;)',
            'client' => ['clientName' => 'IOS', 'clientVersion' => '20.10.4', 'deviceMake' => 'Apple',
                         'deviceModel' => 'iPhone16,2', 'osName' => 'iPhone', 'osVersion' => '18.3.2.22D82'],
        ],
        'ANDROID_VR' => [
            'id' => 28, 'ua' => 'com.google.android.apps.youtube.vr.oculus/1.62.27 (Linux; U; Android 12L; eureka-user Build/SQ3A.220605.009.A1) gzip',
            'client' => ['clientName' => 'ANDROID_VR', 'clientVersion' => '1.62.27', 'deviceMake' => 'Oculus',
                         'deviceModel' => 'Quest 3', 'androidSdkVersion' => 32, 'osName' => 'Android', 'osVersion' => '12L'],
        ],
    ];
    $c = $clients[$client];
    $body = json_encode([
        'context' => ['client' => $c['client'] + ['hl' => 'pt', 'gl' => 'PT']],
        'videoId' => $videoId,
        'contentCheckOk' => true, 'racyCheckOk' => true,
    ]);
    $r = http('https://www.youtube.com/youtubei/v1/player?prettyPrint=false', 'POST', [
        'Content-Type: application/json',
        'User-Agent: ' . $c['ua'],
        'X-Youtube-Client-Name: ' . $c['id'],
        'X-Youtube-Client-Version: ' . $c['client']['clientVersion'],
        'Origin: https://www.youtube.com',
    ], $body);
    if ($r === null) return null;
    $j = json_decode($r, true);
    $u = $j['streamingData']['hlsManifestUrl'] ?? null;
    return is_hls($u) ? $u : null;
}

// --- Método 4: yt-dlp ---
function ytdlp(string $videoId, string &$err): ?string {
    if (!is_executable(YTDLP)) { $err = 'yt-dlp não instalado'; return null; }
    // pasta de trabalho para o yt-dlp/Deno (o utilizador www-data não tem HOME com escrita)
    $home = CACHE_DIR . '/home';
    if (!is_dir($home)) @mkdir($home, 0700, true);
    $env = 'HOME=' . escapeshellarg($home) . ' DENO_DIR=' . escapeshellarg("$home/deno")
         . ' PATH=/usr/local/bin:/usr/bin:/bin ';
    $url = escapeshellarg("https://www.youtube.com/watch?v=$videoId");
    // 1º: clientes web (funcionam mesmo com a reprodução fora do YouTube desativada); 2º: padrão do yt-dlp
    $tries = [
        '--extractor-args ' . escapeshellarg('youtube:player_client=web_safari,web,mweb,tv'),
        '',
    ];
    $errs = [];
    foreach ($tries as $extra) {
        $cmd = $env . 'timeout 60 ' . escapeshellarg(YTDLP) . " -g --no-warnings --no-cache-dir --no-playlist $extra $url 2>&1";
        $out = (string)shell_exec($cmd);
        foreach (preg_split('/\R/', trim($out)) as $line) {
            if (is_hls(trim($line))) return trim($line);
        }
        $errs[] = substr(trim($out), 0, 160);
    }
    $err = 'yt-dlp: ' . implode(' || ', $errs);
    return null;
}

// --- Texto normalizado (sem acentos, minúsculas) para comparar títulos ---
function norm(string $s): string {
    $map = ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','Á'=>'a','À'=>'a','Â'=>'a','Ã'=>'a','Ä'=>'a',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','É'=>'e','È'=>'e','Ê'=>'e','Ë'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','Í'=>'i','Ì'=>'i','Î'=>'i','Ï'=>'i',
            'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','Ó'=>'o','Ò'=>'o','Ô'=>'o','Õ'=>'o','Ö'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','Ú'=>'u','Ù'=>'u','Û'=>'u','Ü'=>'u',
            'ç'=>'c','Ç'=>'c','ñ'=>'n','Ñ'=>'n'];
    return strtolower(trim(preg_replace('/\s+/', ' ', strtr($s, $map))));
}

function title_matches(string $title, string $words): bool {
    $t = norm($title);
    foreach (explode(' ', norm($words)) as $w) {
        if ($w !== '' && strpos($t, $w) === false) return false;
    }
    return true;
}

// --- Lista os diretos ATIVOS de um canal: [['id'=>..., 'title'=>...], ...] (mais recente primeiro) ---
function list_lives(string $channelBase, array &$log): array {
    $lives = [];
    $html = http($channelBase . '/streams', 'GET', [
        'User-Agent: ' . WEB_UA,
        'Accept-Language: pt-PT,pt;q=0.9,en;q=0.8',
        'Cookie: CONSENT=YES+cb; SOCS=CAI',
    ]);
    if ($html !== null && preg_match('/ytInitialData\s*=\s*(\{.+?\});\s*<\/script>/s', $html, $m)) {
        $data = json_decode($m[1], true);
        $seen = [];
        $walk = function ($node) use (&$walk, &$lives, &$seen) {
            if (!is_array($node)) return;
            if (isset($node['videoRenderer']['videoId'])) {                     // formato clássico
                $vr = $node['videoRenderer']; $enc = json_encode($vr);
                $title = $vr['title']['simpleText'] ?? implode('', array_column($vr['title']['runs'] ?? [], 'text'));
                $live = !isset($vr['upcomingEventData'])
                     && (strpos($enc, 'LIVE_NOW') !== false || strpos($enc, '"style":"LIVE"') !== false);
                if ($live && !isset($seen[$vr['videoId']])) { $seen[$vr['videoId']] = 1; $lives[] = ['id' => $vr['videoId'], 'title' => $title]; }
            }
            if (isset($node['lockupViewModel']['contentId'])) {                 // formato novo
                $lv = $node['lockupViewModel']; $enc = json_encode($lv);
                $title = $lv['metadata']['lockupMetadataViewModel']['title']['content'] ?? '';
                $live = strpos($enc, 'UPCOMING') === false
                     && (strpos($enc, 'BADGE_STYLE_LIVE') !== false || strpos($enc, 'LIVE_NOW') !== false);
                if ($live && !isset($seen[$lv['contentId']])) { $seen[$lv['contentId']] = 1; $lives[] = ['id' => $lv['contentId'], 'title' => $title]; }
            }
            foreach ($node as $child) if (is_array($child)) $walk($child);
        };
        if (is_array($data)) $walk($data);
    }
    if ($lives) return $lives;
    $log[] = 'lista de diretos: página do canal sem diretos reconhecidos';

    // recurso: pedir a lista ao yt-dlp
    if (is_executable(YTDLP)) {
        $home = CACHE_DIR . '/home';
        if (!is_dir($home)) @mkdir($home, 0700, true);
        $cmd = 'HOME=' . escapeshellarg($home) . ' DENO_DIR=' . escapeshellarg("$home/deno") . ' PATH=/usr/local/bin:/usr/bin:/bin '
             . 'timeout 60 ' . escapeshellarg(YTDLP) . ' --flat-playlist --playlist-end 30 --no-warnings --no-cache-dir '
             . '--print ' . escapeshellarg('%(id)s|%(live_status)s|%(title)s') . ' '
             . escapeshellarg($channelBase . '/streams') . ' 2>/dev/null';
        foreach (preg_split('/\R/', trim((string)shell_exec($cmd))) as $line) {
            $p = explode('|', $line, 3);
            if (count($p) === 3 && $p[1] === 'is_live' && preg_match('/^[A-Za-z0-9_-]{11}$/', $p[0])) {
                $lives[] = ['id' => $p[0], 'title' => $p[2]];
            }
        }
        if (!$lives) $log[] = 'lista de diretos (yt-dlp): nenhum direto ativo';
    }
    return $lives;
}

// ======================= INÍCIO =======================

if (ONLY_LOCALHOST) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, ['127.0.0.1', '::1'], true)) fail(403, 'Acesso negado.');
}
$debug = isset($_GET['debug']);

$v = trim($_GET['v'] ?? '');
$c = trim($_GET['c'] ?? '');
$t = trim($_GET['t'] ?? '');
if (strlen($t) > 150) fail(400, 'Título (&t=) demasiado longo.');
$channelBase = null;
if ($v !== '') {
    if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $v)) fail(400, 'ID de vídeo inválido.');
    $pageUrl = "https://www.youtube.com/watch?v=$v";
    $cacheKey = "v_$v";
    $t = '';                                    // com ?v= o título não é preciso
} elseif ($c !== '') {
    if (preg_match('/^UC[A-Za-z0-9_-]{22}$/', $c))            $channelBase = "https://www.youtube.com/channel/$c";
    elseif (preg_match('/^@?[A-Za-z0-9._-]{3,100}$/', $c))   $channelBase = 'https://www.youtube.com/@' . ltrim($c, '@');
    else fail(400, 'Canal inválido. Use @handle ou o ID UC... do canal.');
    $pageUrl = $channelBase . '/live';
    $cacheKey = 'c_' . strtolower(ltrim($c, '@')) . ($t !== '' ? '_t_' . substr(md5(norm($t)), 0, 10) : '');
} else {
    fail(400, 'Falta o parâmetro ?v=ID_DO_VIDEO ou ?c=@canal');
}

// ---- &list=1: mostrar os diretos ativos do canal ----
if (isset($_GET['list'])) {
    if (!$channelBase) fail(400, 'Use &list=1 com ?c=@canal');
    $log = [];
    $lives = list_lives($channelBase, $log);
    header('Content-Type: text/plain; charset=utf-8');
    if (!$lives) { echo "Nenhum direto ativo neste canal.\n"; if ($log) echo "  - ", implode("\n  - ", $log), "\n"; exit; }
    echo count($lives), " direto(s) ativo(s):\n\n";
    foreach ($lives as $i => $l) {
        echo '  ', $i + 1, ". {$l['id']}  {$l['title']}\n";
    }
    echo "\nFonte por título:  http://127.0.0.1:8088/ytlive.php?c=" . rawurlencode($c) . "&t=PALAVRAS&p=1\n";
    echo "Fonte fixa:        http://127.0.0.1:8088/ytlive.php?v=ID&p=1\n";
    exit;
}

// ---- cache ----
if (!is_dir(CACHE_DIR)) @mkdir(CACHE_DIR, 0700, true);
$cacheFile = CACHE_DIR . '/' . preg_replace('/[^a-z0-9_.-]/i', '_', $cacheKey) . '.json';
function readCache(string $f): ?array {
    if (!is_file($f)) return null;
    $d = json_decode((string)@file_get_contents($f), true);
    return (is_array($d) && !empty($d['url']) && ($d['expires'] ?? 0) > time()) ? $d : null;
}

$cached = $debug ? null : readCache($cacheFile);   // em debug testa sempre de novo

if (!$cached) {
    $lock = fopen($cacheFile . '.lock', 'c');
    if ($lock) flock($lock, LOCK_EX);
    $cached = $debug ? null : readCache($cacheFile);
    $unlock = function () use ($lock) { if ($lock) { flock($lock, LOCK_UN); fclose($lock); } };

    if (!$cached) {
        $log = []; $m3u8 = null; $method = null; $chosenTitle = null;

        // ---- &t=: escolher, entre os diretos ativos do canal, o que tem estas palavras no título ----
        if ($t !== '') {
            $lives = list_lives($channelBase, $log);
            foreach ($lives as $l) {
                if (title_matches($l['title'], $t)) { $v = $l['id']; $chosenTitle = $l['title']; break; }
            }
            if ($v === '') {
                $unlock();
                foreach ($lives as $l) $log[] = "ativo: {$l['id']}  {$l['title']}";
                fail(404, $lives ? "Nenhum direto ativo do canal tem \"$t\" no título."
                                 : 'O canal não tem nenhum direto ativo neste momento.', $log);
            }
            $pageUrl = "https://www.youtube.com/watch?v=$v";
        }

        // ---- 1. página web ----
        $html = http($pageUrl, 'GET', [
            'User-Agent: ' . WEB_UA,
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: pt-PT,pt;q=0.9,en;q=0.8',
            'Cookie: CONSENT=YES+cb; SOCS=CAI',
        ]);
        if ($html === null) { $unlock(); fail(502, 'Não foi possível contactar o YouTube.'); }

        if (stripos($html, 'g-recaptcha') !== false || stripos($html, 'not a robot') !== false
            || stripos($html, 'confirm you') !== false) {
            $log[] = 'página: YouTube pediu verificação anti-robô';
        }

        // ID do vídeo (necessário para ?c=, já conhecido para ?v=)
        $videoId = $v;
        if ($videoId === '') {
            if (preg_match('#<link rel="canonical" href="https://www\.youtube\.com/watch\?v=([A-Za-z0-9_-]{11})"#', $html, $m)
                || preg_match('/"liveStreamabilityRenderer":\{"videoId":"([A-Za-z0-9_-]{11})"/', $html, $m)
                || preg_match('/"videoDetails":\{"videoId":"([A-Za-z0-9_-]{11})"/', $html, $m)) {
                $videoId = $m[1];
            } else {
                $unlock(); fail(404, 'O canal não tem nenhum direto ativo neste momento.', $log);
            }
        }
        $isLive = strpos($html, '"isLive":true') !== false;

        if (preg_match('/"hlsManifestUrl"\s*:\s*"([^"]+)"/', $html, $m)
            || preg_match('/\\\\"hlsManifestUrl\\\\":\\\\"(.*?)\\\\"/', $html, $m)) {
            $u = clean_url($m[1]);
            if (is_hls($u)) { $m3u8 = $u; $method = 'página web'; }
        }
        if (!$m3u8) $log[] = 'página web: sem hlsManifestUrl' . ($isLive ? ' (mas isLive=true)' : '');

        // ---- 2 e 3. API interna ----
        foreach (['IOS', 'ANDROID_VR'] as $cl) {
            if ($m3u8) break;
            $u = innertube($videoId, $cl);
            if ($u) { $m3u8 = $u; $method = "API $cl"; }
            else $log[] = "API $cl: sem link";
        }

        // ---- 4. yt-dlp ----
        if (!$m3u8) {
            $err = '';
            $u = ytdlp($videoId, $err);
            if ($u) { $m3u8 = $u; $method = 'yt-dlp'; }
            else $log[] = $err;
        }

        if (!$m3u8) {
            $unlock();
            if (!$isLive) fail(404, 'Sem direto ativo (o vídeo não é um direto ou já terminou).', $log);
            fail(502, 'O direto existe, mas não foi possível obter o link HLS.', $log);
        }

        $expires = time() + CACHE_FALLBACK;
        if (preg_match('#/expire/(\d{9,11})/#', $m3u8, $e) || preg_match('#[?&]expire=(\d{9,11})#', $m3u8, $e)) {
            $expires = min($expires, (int)$e[1] - CACHE_MARGIN);
        }
        $cached = ['url' => $m3u8, 'expires' => $expires, 'source' => $pageUrl,
                   'videoId' => $videoId, 'title' => $chosenTitle, 'method' => $method, 'created' => time(), 'log' => $log];
        @file_put_contents($cacheFile, json_encode($cached), LOCK_EX);
    }
    $unlock();
}

if ($debug) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "OK\n";
    echo "Video:   {$cached['videoId']}\n";
    if (!empty($cached['title'])) echo "Titulo:  {$cached['title']}\n";
    echo "Metodo:  {$cached['method']}\n";
    echo "Expira:  " . date('Y-m-d H:i:s', $cached['expires']) . "\n";
    echo "Link:    {$cached['url']}\n";
    if (!empty($cached['log'])) echo "\nTentativas falhadas antes:\n  - " . implode("\n  - ", $cached['log']) . "\n";
    exit;
}

// ---- Modo proxy (&p=1): entrega a lista .m3u8 diretamente, sem redirecionamento ----
if (isset($_GET['p'])) {
    $fetch = function (string $u): array {
        $r = @file_get_contents($u, false, stream_context_create(['http' => [
            'timeout' => HTTP_TIMEOUT, 'ignore_errors' => true,
            'header' => 'User-Agent: ' . WEB_UA,
        ]]));
        $code = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $code = (int)$m[1];   // último = resposta final
        }
        return [$code, $r === false ? '' : $r];
    };
    [$code, $body] = $fetch($cached['url']);
    if ($code !== 200 || strpos($body, '#EXTM3U') === false) {
        // link expirado ou recusado: apagar a cache — o próximo pedido do XUI vai buscar um link novo
        @unlink($cacheFile);
        fail(503, "O YouTube recusou a lista HLS (HTTP $code). Link renovado no próximo pedido.");
    }
    // segmentos com caminho relativo -> absoluto
    $base = preg_replace('#[^/]*$#', '', strtok($cached['url'], '?'));
    $body = preg_replace_callback('#^(?!\#)(?!https?://)(\S+)$#m', fn($m) => $base . $m[1], $body);
    header('Content-Type: application/vnd.apple.mpegurl');
    header('Cache-Control: no-store');
    echo $body;
    exit;
}

header('Cache-Control: no-store');
header('Location: ' . $cached['url'], true, 302);
exit;
