<?php
// 用法: /ico/index.php?url=github.com  (也支持 ?domain= 、完整URL)
// 可选: &refresh=1 强制重新获取
const UA = 'Mozilla/5.0 (compatible; FaviconFetcher/1.0)';
const MAX_SIZE = 1048576;

$dir = __DIR__ . '/public/favicons/';
$fallback = dirname(__DIR__) . '/favicon.ico';

$input = trim($_GET['url'] ?? $_GET['domain'] ?? $_GET['host'] ?? '');
$host = parseHost($input);
if ($host === null) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode(['error' => 'invalid url']));
}

if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
}

$safe = preg_replace('/[^a-z0-9.\-]/', '_', $host);
$cached = findCached($dir, $safe);
if ($cached && empty($_GET['refresh'])) {
    output($cached);
}

$data = fetchFavicon($host);
if ($data !== null) {
    $ext = detectExt($data);
    $file = $dir . $safe . '.' . $ext;
    if (@file_put_contents($file, $data) === false) {
        outputData($data, $ext);
    }
    output($file);
}

// 获取不到: 复制根目录 favicon.ico
$file = $dir . $safe . '.ico';
if (is_file($fallback)) {
    if (!@copy($fallback, $file)) {
        outputData((string)file_get_contents($fallback), 'ico');
    }
    output($file);
}
http_response_code(404);
exit;

function sendHeaders(string $ext, int $len): void
{
    $types = ['ico' => 'image/x-icon', 'png' => 'image/png', 'gif' => 'image/gif',
        'jpg' => 'image/jpeg', 'svg' => 'image/svg+xml', 'webp' => 'image/webp'];
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Cache-Control: public, max-age=86400');
    header('Content-Length: ' . $len);
}

function outputData(string $data, string $ext): void
{
    sendHeaders($ext, strlen($data));
    echo $data;
    exit;
}

function output(string $file): void
{
    sendHeaders(strtolower(pathinfo($file, PATHINFO_EXTENSION)), (int)filesize($file));
    readfile($file);
    exit;
}

function findCached(string $dir, string $safe): ?string
{
    foreach (['ico', 'png', 'gif', 'jpg', 'svg', 'webp'] as $e) {
        if (is_file("$dir$safe.$e") && filesize("$dir$safe.$e") > 0) {
            return "$dir$safe.$e";
        }
    }
    return null;
}

function parseHost(string $in): ?string
{
    if ($in === '') {
        return null;
    }
    if (!preg_match('#^https?://#i', $in)) {
        $in = 'http://' . $in;
    }
    $h = strtolower((string)parse_url($in, PHP_URL_HOST));
    if ($h === '' || !preg_match('/^[a-z0-9]([a-z0-9\-.]*[a-z0-9])?$/', $h) || strpos($h, '.') === false) {
        return null;
    }
    return isPublicHost($h) ? $h : null;
}

// 防止 SSRF: 禁止内网/保留地址
function isPublicHost(string $h): bool
{
    $ips = filter_var($h, FILTER_VALIDATE_IP) ? [$h] : (gethostbynamel($h) ?: []);
    if (!$ips) {
        return false;
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
    }
    return true;
}

function http(string $url, int &$code = 0, ?string &$finalUrl = null): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 4,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT => UA,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_ENCODING => '',
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => function ($r, $total, $now) {
            return $now > MAX_SIZE * 4 ? 1 : 0;
        },
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $finalUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return $body === false ? null : $body;
}

function fetchFavicon(string $host): ?string
{
    $candidates = [];
    foreach (['https', 'http'] as $scheme) {
        $code = 0;
        $final = null;
        $html = http("$scheme://$host/", $code, $final);
        if ($html !== null && $code >= 200 && $code < 400) {
            $base = $final ?: "$scheme://$host/";
            foreach (parseLinks($html, $base) as $u) {
                $candidates[] = $u;
            }
            $p = parse_url($base);
            $candidates[] = $p['scheme'] . '://' . $p['host'] . '/favicon.ico';
            break;
        }
    }
    $candidates[] = "https://$host/favicon.ico";
    $candidates[] = "http://$host/favicon.ico";

    foreach (array_unique($candidates) as $u) {
        $img = loadImage($u);
        if ($img !== null) {
            return $img;
        }
    }
    return null;
}

function loadImage(string $url): ?string
{
    if (strpos($url, 'data:') === 0) {
        if (preg_match('#^data:[^,]*?(;base64)?,(.*)$#s', $url, $m)) {
            $d = $m[1] ? base64_decode($m[2]) : rawurldecode($m[2]);
            return isImage((string)$d) ? $d : null;
        }
        return null;
    }
    $h = parse_url($url, PHP_URL_HOST);
    if (!$h || !isPublicHost(strtolower($h))) {
        return null;
    }
    $code = 0;
    $d = http($url, $code);
    if ($d === null || $code !== 200 || strlen($d) > MAX_SIZE || !isImage($d)) {
        return null;
    }
    return $d;
}

function parseLinks(string $html, string $base): array
{
    $found = [];
    if (!preg_match_all('/<link\b[^>]*>/i', $html, $tags)) {
        return [];
    }
    foreach ($tags[0] as $tag) {
        if (!preg_match('/\brel\s*=\s*["\']?([^"\'>]*)/i', $tag, $r) || stripos($r[1], 'icon') === false) {
            continue;
        }
        if (!preg_match('/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $tag, $h)) {
            continue;
        }
        $href = html_entity_decode(trim(($h[1] ?? '') . ($h[2] ?? '') . ($h[3] ?? '')));
        if ($href === '') {
            continue;
        }
        $score = stripos($r[1], 'apple-touch') !== false ? 1 : 2; // 优先普通 icon
        if (preg_match('/sizes\s*=\s*["\']?(\d+)x/i', $tag, $s)) {
            $score += min((int)$s[1], 64) / 100;
        }
        $found[absUrl($href, $base)] = $score;
    }
    arsort($found);
    return array_keys($found);
}

function absUrl(string $href, string $base): string
{
    if (preg_match('#^(https?:|data:)#i', $href)) {
        return $href;
    }
    $p = parse_url($base);
    $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    if (strpos($href, '//') === 0) {
        return $p['scheme'] . ':' . $href;
    }
    if ($href[0] === '/') {
        return $origin . $href;
    }
    $path = $p['path'] ?? '/';
    $dir = substr($path, 0, strrpos($path, '/') + 1);
    return $origin . $dir . $href;
}

function isImage(string $d): bool
{
    return detectExt($d) !== null;
}

function detectExt(string $d): ?string
{
    if (strncmp($d, "\0\0\1\0", 4) === 0 || strncmp($d, "\0\0\2\0", 4) === 0) return 'ico';
    if (strncmp($d, "\x89PNG", 4) === 0) return 'png';
    if (strncmp($d, 'GIF8', 4) === 0) return 'gif';
    if (strncmp($d, "\xFF\xD8\xFF", 3) === 0) return 'jpg';
    if (strncmp($d, 'RIFF', 4) === 0 && substr($d, 8, 4) === 'WEBP') return 'webp';
    if (preg_match('/^\s*(<\?xml[^>]*>\s*)?(<!--.*?-->\s*)*<svg\b/is', substr($d, 0, 2048))) return 'svg';
    return null;
}
