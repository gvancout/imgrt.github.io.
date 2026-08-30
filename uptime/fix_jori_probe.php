<?php
/**
 * One-shot patch for the live Imageert uptime tracker.
 *
 * Upload to /home/imageee/api/uptime/ next to check_uptime.php, then open:
 *   https://api.imageert.be/uptime/fix_jori_probe.php
 *
 * It stops the jori.com probe from using /robots.txt, resets that site's
 * baseline (learned from the crawler file), runs one check, then you can
 * delete this file.
 */
header('Content-Type: text/plain; charset=UTF-8');

$dir = __DIR__;
$target = $dir . '/check_uptime.php';

if (!is_file($target)) {
    http_response_code(500);
    echo "check_uptime.php not found in $dir\n";
    exit(1);
}
if (!is_readable($target) || !is_writable($target)) {
    http_response_code(500);
    echo "check_uptime.php is not writable\n";
    exit(1);
}

$src = file_get_contents($target);
if ($src === false || $src === '') {
    http_response_code(500);
    echo "Could not read check_uptime.php\n";
    exit(1);
}

$backup = $dir . '/check_uptime.php.bak-jori-' . date('Ymd-His');
if (file_put_contents($backup, $src) === false) {
    http_response_code(500);
    echo "Could not write backup $backup\n";
    exit(1);
}

$new = $src;
$count = 0;

$new = str_replace(
    array(
        'https://www.jori.com/robots.txt',
        'http://www.jori.com/robots.txt',
        'https://jori.com/robots.txt',
        'http://jori.com/robots.txt',
    ),
    'https://www.jori.com/',
    $new,
    $c1
);
$count += $c1;

$new = preg_replace(
    '#([\'"])https?://(?:www\.)?jori\.com/?\1\s*\.\s*[\'"]/?robots\.txt[\'"]#i',
    "'https://www.jori.com/'",
    $new,
    -1,
    $c2
);
$count += (int) $c2;

$new = preg_replace(
    '#rtrim\s*\(\s*\$(\w+)\s*,\s*[\'"]/[\'"]\s*\)\s*\.\s*[\'"]/robots\.txt[\'"]#',
    '$\1',
    $new,
    -1,
    $c3
);
$count += (int) $c3;

$stillHasRobots = (stripos($new, 'jori.com') !== false && stripos($new, 'robots.txt') !== false);

if ($new === $src) {
    echo "No robots.txt probe string found to replace.\n";
    echo "Backup kept at $backup\n";
    echo "Replace check_uptime.php with the copy from the git repo instead.\n";
    if (stripos($src, 'robots.txt') !== false) {
        echo "\nrobots.txt still appears in the file. Snippet:\n\n";
        $pos = stripos($src, 'robots.txt');
        echo substr($src, max(0, $pos - 180), 360) . "\n";
    }
    exit(2);
}

if (file_put_contents($target, $new) === false) {
    http_response_code(500);
    echo "Patch computed but write failed. Restore from $backup\n";
    exit(1);
}

echo "Patched check_uptime.php ($count replacement(s)).\n";
echo "Backup: $backup\n";
if ($stillHasRobots) {
    echo "Warning: robots.txt still appears near a jori.com string. Review the backup vs the new file.\n";
}

$baselineFile = $dir . '/baseline_data.json';
if (is_file($baselineFile) && is_writable($baselineFile)) {
    $baseline = json_decode(file_get_contents($baselineFile), true);
    if (is_array($baseline)) {
        $kept = array();
        foreach ($baseline as $row) {
            $url = isset($row['url']) ? $row['url'] : '';
            if (stripos($url, 'jori.com') === false) {
                $kept[] = $row;
            }
        }
        file_put_contents(
            $baselineFile,
            json_encode(array_values($kept), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        echo "Cleared jori.com baseline so TTFB learns from the homepage.\n";
    }
}

echo "Running one check...\n\n";
passthru('php ' . escapeshellarg($target), $code);
echo "\ncheck_uptime.php exit: $code\n";
echo "Dashboard: https://api.imageert.be/uptime/\n";
echo "Delete this patcher when the dashboard no longer says via /robots.txt.\n";
