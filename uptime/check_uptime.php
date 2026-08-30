<?php
/**
 * Site uptime monitor for api.imageert.be/uptime/
 *
 * Probes the live homepages. Do not use robots.txt as a stand-in for jori.com.
 */
date_default_timezone_set('Europe/Brussels');

$dir = __DIR__;
$sites = array(
    'https://www.jori.com/',
    'https://imageert.be',
    'https://smappee.com',
);

$ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';
$now = date('Y-m-d H:i:s');

function load_json_file($path, $fallback)
{
    if (!is_file($path)) {
        return $fallback;
    }
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : $fallback;
}

function save_json_file($path, $data)
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, $json) === false) {
        return false;
    }
    return rename($tmp, $path);
}

function host_from_url($url)
{
    $parts = parse_url($url);
    return isset($parts['host']) ? $parts['host'] : '';
}

function tls_days_left($url)
{
    $host = host_from_url($url);
    if ($host === '') {
        return null;
    }
    $errno = 0;
    $errstr = '';
    $ctx = stream_context_create(array(
        'ssl' => array(
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ),
    ));
    $client = @stream_socket_client(
        'ssl://' . $host . ':443',
        $errno,
        $errstr,
        15,
        STREAM_CLIENT_CONNECT,
        $ctx
    );
    if (!$client) {
        return null;
    }
    $params = stream_context_get_params($client);
    fclose($client);
    if (empty($params['options']['ssl']['peer_certificate'])) {
        return null;
    }
    $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
    if (!$cert || empty($cert['validTo_time_t'])) {
        return null;
    }
    return (int) floor(($cert['validTo_time_t'] - time()) / 86400);
}

function http_upgrades_to_https($url, $ua)
{
    $host = host_from_url($url);
    if ($host === '') {
        return false;
    }
    $httpUrl = 'http://' . $host . '/';
    $ch = curl_init($httpUrl);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 8,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_USERAGENT => $ua,
        CURLOPT_NOBODY => true,
        CURLOPT_HEADER => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ));
    curl_exec($ch);
    $final = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return is_string($final) && strpos($final, 'https://') === 0;
}

function probe_url($url, $ua)
{
    $result = array(
        'url' => $url,
        'check_url' => $url,
        'timestamp' => date('Y-m-d H:i:s'),
        'status' => 'error',
        'http_code' => 0,
        'ttfb' => 0,
        'total_time' => 0,
        'final_url' => $url,
        'tls_days_left' => null,
        'http_upgrades_to_https' => false,
        'errors' => array(),
        'healthy' => false,
    );

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 8,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_USERAGENT => $ua,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => array(
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: nl-BE,nl;q=0.9,en;q=0.8',
        ),
    ));
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $result['http_code'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $result['ttfb'] = round((float) curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME), 3);
    $result['total_time'] = round((float) curl_getinfo($ch, CURLINFO_TOTAL_TIME), 3);
    $final = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    if (is_string($final) && $final !== '') {
        $result['final_url'] = $final;
    }
    curl_close($ch);

    if ($errno) {
        $result['errors'][] = $error !== '' ? $error : ('cURL error ' . $errno);
        if ($errno === CURLE_OPERATION_TIMEDOUT) {
            $result['status'] = 'timeout';
        }
        return $result;
    }

    if ($body === false) {
        $result['errors'][] = 'Empty response';
        return $result;
    }

    $result['status'] = 'ok';
    return $result;
}

function evaluate_health($result, $baselineTtfb)
{
    $errors = $result['errors'];
    $code = $result['http_code'];
    if ($code < 200 || $code >= 300) {
        $errors[] = 'HTTP status is not 2xx';
    }
    if ($result['ttfb'] > 3) {
        $errors[] = 'TTFB exceeds 3 seconds';
    }
    if ($baselineTtfb > 0 && $result['ttfb'] > (2 * $baselineTtfb)) {
        $errors[] = 'TTFB is more than 2x the learned baseline';
    }
    if ($result['total_time'] > 5) {
        $errors[] = 'Total response time exceeds 5 seconds';
    }
    if ($result['tls_days_left'] !== null && $result['tls_days_left'] < 14) {
        $errors[] = 'TLS certificate expires within 14 days';
    }
    if (!$result['http_upgrades_to_https']) {
        $errors[] = 'HTTP does not upgrade to HTTPS';
    }
    $result['errors'] = $errors;
    $result['healthy'] = count($errors) === 0 && $result['status'] === 'ok';
    return $result;
}

$baselinePath = $dir . '/baseline_data.json';
$historyPath = $dir . '/uptime_history.json';
$dataPath = $dir . '/uptime_data.json';
$reportPath = $dir . '/uptime_report.html';

$baselines = load_json_file($baselinePath, array());
$baselineByUrl = array();
foreach ($baselines as $row) {
    if (isset($row['url'])) {
        $baselineByUrl[$row['url']] = $row;
    }
}

$results = array();
foreach ($sites as $url) {
    $row = probe_url($url, $ua);
    $row['tls_days_left'] = tls_days_left($url);
    $row['http_upgrades_to_https'] = http_upgrades_to_https($url, $ua);
    $baselineTtfb = 0;
    if (isset($baselineByUrl[$url]['ttfb'])) {
        $baselineTtfb = (float) $baselineByUrl[$url]['ttfb'];
    }
    $row = evaluate_health($row, $baselineTtfb);
    $results[] = $row;

    if ($row['healthy']) {
        $oldTtfb = $baselineTtfb > 0 ? $baselineTtfb : $row['ttfb'];
        $oldTotal = isset($baselineByUrl[$url]['total_time'])
            ? (float) $baselineByUrl[$url]['total_time']
            : $row['total_time'];
        $baselineByUrl[$url] = array(
            'url' => $url,
            'ttfb' => round((0.9 * $oldTtfb) + (0.1 * $row['ttfb']), 3),
            'total_time' => round((0.9 * $oldTotal) + (0.1 * $row['total_time']), 3),
            'updated' => $now,
        );
    }
}

$healthy = 0;
foreach ($results as $row) {
    if ($row['healthy']) {
        $healthy++;
    }
}
$total = count($results);
$unhealthy = $total - $healthy;

$payload = array(
    'check_time' => $now,
    'results' => $results,
);
save_json_file($dataPath, $payload);
save_json_file($baselinePath, array_values($baselineByUrl));

$history = load_json_file($historyPath, array());
$history[] = array(
    'check_time' => $now,
    'summary' => array(
        'total_sites' => $total,
        'healthy_sites' => $healthy,
        'unhealthy_sites' => $unhealthy,
    ),
);
if (count($history) > 200) {
    $history = array_slice($history, -200);
}
save_json_file($historyPath, $history);

$html = "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n";
$html .= "    <meta charset=\"UTF-8\">\n";
$html .= "    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\">\n";
$html .= "    <title>Site Uptime Report</title>\n";
$html .= "    <style>\n";
$html .= "        body { font-family: Arial, sans-serif; line-height: 1.6; padding: 20px; }\n";
$html .= "        h1 { color: #333; }\n";
$html .= "        .summary { background-color: #f4f4f4; padding: 10px; margin-bottom: 20px; }\n";
$html .= "        .site-check { margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }\n";
$html .= "        .site-check.healthy { background-color: #e8f5e9; }\n";
$html .= "        .site-check.unhealthy { background-color: #ffebee; }\n";
$html .= "        .metric { margin: 5px 0; }\n";
$html .= "        .error { color: #d32f2f; font-weight: bold; }\n";
$html .= "        .url { font-weight: bold; color: #1976d2; }\n";
$html .= "    </style>\n</head>\n<body>\n";
$html .= "    <h1>Site Uptime Report</h1>\n";
$html .= "    <div class=\"summary\">\n";
$html .= "        <strong>Check Time:</strong> " . htmlspecialchars($now) . "<br>\n";
$html .= "        <strong>Total Sites:</strong> $total<br>\n";
$html .= "        <strong>Healthy:</strong> $healthy<br>\n";
$html .= "        <strong>Unhealthy:</strong> $unhealthy\n";
$html .= "    </div>\n";
foreach ($results as $row) {
    $cls = $row['healthy'] ? 'healthy' : 'unhealthy';
    $label = $row['healthy'] ? 'HEALTHY' : 'UNHEALTHY';
    $html .= "    <div class=\"site-check $cls\">\n";
    $html .= "        <div class=\"url\">" . htmlspecialchars($row['url']) . " - $label</div>\n";
    $html .= "        <div class=\"metric\">Status: " . htmlspecialchars($row['status']) . "</div>\n";
    $html .= "        <div class=\"metric\">HTTP Code: " . (int) $row['http_code'] . "</div>\n";
    $html .= "        <div class=\"metric\">TTFB: " . htmlspecialchars((string) $row['ttfb']) . "s</div>\n";
    $html .= "        <div class=\"metric\">Total Time: " . htmlspecialchars((string) $row['total_time']) . "s</div>\n";
    $html .= "        <div class=\"metric\">Final URL: " . htmlspecialchars($row['final_url']) . "</div>";
    if ($row['tls_days_left'] !== null) {
        $html .= "<div class=\"metric\">TLS Days Left: " . (int) $row['tls_days_left'] . "</div>";
    }
    $html .= "<div class=\"metric\">HTTP → HTTPS: " . ($row['http_upgrades_to_https'] ? 'Yes' : 'No') . "</div>";
    foreach ($row['errors'] as $err) {
        $html .= "<div class=\"metric error\">" . htmlspecialchars($err) . "</div>";
    }
    $html .= "</div>\n";
}
$html .= "</body>\n</html>\n";
file_put_contents($reportPath, $html);

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=UTF-8');
}
echo "Uptime check completed.\n";
