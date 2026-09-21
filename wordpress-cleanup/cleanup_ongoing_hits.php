<?php
/**
 * Quarantine the ongoing Imunify hits on gtje.be and kinglun.ch.
 *
 * Report only:
 *   php cleanup_ongoing_hits.php
 *
 * Quarantine the four dropped files and restore the two Twenty Twenty
 * class files from the official 3.2 copies in clean/:
 *   php cleanup_ongoing_hits.php --apply
 *
 * Run this on the OVH host as the imageee user. A web request is refused.
 * Set CLEANUP_HOME to point at a fixture when testing away from the host.
 */
if (PHP_SAPI !== 'cli') {
    if (!headers_sent()) {
        header('HTTP/1.0 404 Not Found');
    }
    exit;
}

$apply = in_array('--apply', $argv, true);
$home = getenv('CLEANUP_HOME');
if (!is_string($home) || $home === '') {
    $home = '/home/imageee';
}
$home = rtrim($home, '/');
$cleanDir = __DIR__ . '/clean/twentytwenty-3.2/classes';
$quarantine = $home . '/malware-quarantine/' . date('Ymd-His');

$drops = array(
    'kinglun.ch/wp-content/plugins/toolset-blocks/vendor/toolset/common-es/server/Compatibility/Style/Rule/mysqli.dbi.lib.php',
    'kinglun.ch/wp-content/plugins/toolset-blocks/vendor/toolset/toolset-common/inc/autoloaded/shortcode/attr/item/UnderFreeElect.php',
    'kinglun.ch/wp-content/plugins/toolset-blocks/vendor/toolset/toolset-common/lib/Twig/src/Node/comparison_list.php',
    'kinglun.ch/wp-content/plugins/toolset-blocks/vendor/toolset/toolset-common/utility/condition/theme/page-builder-framework/DIME.php',
);

$restores = array(
    'gtje.be/wp-content/themes/twentytwenty/classes/class-twentytwenty-walker-comment.php' => 'class-twentytwenty-walker-comment.php',
    'kinglun.ch/wp-content/themes/twentytwenty/classes/class-twentytwenty-separator-control.php' => 'class-twentytwenty-separator-control.php',
);

$failures = 0;

function out($line)
{
    echo $line . "\n";
}

function rel_to_abs($home, $rel)
{
    return $home . '/' . $rel;
}

function short_line($line)
{
    $line = str_replace(array("\r", "\n", "\t"), ' ', $line);
    if (strlen($line) > 160) {
        $line = substr($line, 0, 160) . '...';
    }
    return $line;
}

function first_diff_line($current, $clean)
{
    $a = preg_split("/\r\n|\n|\r/", $current);
    $b = preg_split("/\r\n|\n|\r/", $clean);
    $max = max(count($a), count($b));
    for ($i = 0; $i < $max; $i++) {
        $left = isset($a[$i]) ? $a[$i] : '';
        $right = isset($b[$i]) ? $b[$i] : '';
        if ($left !== $right) {
            return array($i + 1, $left);
        }
    }
    return array(0, '');
}

function ensure_dir($dir)
{
    if (is_dir($dir)) {
        return true;
    }
    return mkdir($dir, 0700, true);
}

function quarantine_file($abs, $home, $quarantine)
{
    $rel = ltrim(substr($abs, strlen($home)), '/');
    $dest = $quarantine . '/' . $rel;
    $destDir = dirname($dest);
    if (!ensure_dir($destDir)) {
        return array(false, 'Could not create ' . $destDir);
    }
    if (!rename($abs, $dest)) {
        return array(false, 'Could not move ' . $abs);
    }
    chmod($dest, 0600);
    return array(true, $dest);
}

function site_roots_from($home, $rels)
{
    $roots = array();
    foreach ($rels as $rel) {
        $site = strtok($rel, '/');
        if ($site !== false && $site !== '') {
            $roots[$site] = $home . '/' . $site;
        }
    }
    return $roots;
}

function iter_php_files($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $ext = strtolower($file->getExtension());
        if ($ext === 'php' || $ext === 'phtml' || $ext === 'suspected') {
            yield $file->getPathname();
        }
    }
}

function search_roots($siteRoot)
{
    $roots = array(
        $siteRoot . '/wp-content/themes',
        $siteRoot . '/wp-content/plugins',
        $siteRoot . '/wp-content/mu-plugins',
    );
    foreach (array('index.php', 'wp-load.php', 'wp-blog-header.php', 'wp-settings.php', 'wp-config.php', '.htaccess') as $name) {
        $path = $siteRoot . '/' . $name;
        if (is_file($path)) {
            $roots[] = $path;
        }
    }
    return $roots;
}

function find_name_references($siteRoot, $basename, $ignoreAbs)
{
    $hits = array();
    foreach (search_roots($siteRoot) as $root) {
        $files = is_file($root) ? array($root) : iterator_to_array(iter_php_files($root));
        if (is_file($root) && substr($root, -9) === '.htaccess') {
            $files = array($root);
        }
        foreach ($files as $path) {
            if ($path === $ignoreAbs || !is_file($path) || !is_readable($path)) {
                continue;
            }
            $contents = file_get_contents($path);
            if ($contents === false || strpos($contents, $basename) === false) {
                continue;
            }
            $lineNo = 0;
            $snippet = '';
            foreach (preg_split("/\r\n|\n|\r/", $contents) as $line) {
                $lineNo++;
                if (strpos($line, $basename) !== false) {
                    $snippet = short_line($line);
                    break;
                }
            }
            $hits[] = $path . ':' . $lineNo . ' ' . $snippet;
        }
    }
    return $hits;
}

out($apply ? 'APPLY' : 'DRY RUN');
out('Home: ' . $home);
out('Quarantine: ' . $quarantine);
out('');

if (!is_dir($home)) {
    out('Home directory is not present. Nothing was changed.');
    exit(1);
}

out('--- Theme files (restore official Twenty Twenty 3.2 first) ---');
$pendingRestores = array();
foreach ($restores as $rel => $cleanName) {
    $abs = rel_to_abs($home, $rel);
    $cleanPath = $cleanDir . '/' . $cleanName;
    out($rel);
    if (!is_file($cleanPath)) {
        $failures++;
        out('  FAILED missing clean copy ' . $cleanPath);
        continue;
    }
    $clean = file_get_contents($cleanPath);
    if ($clean === false || $clean === '') {
        $failures++;
        out('  FAILED could not read clean copy');
        continue;
    }
    if (!is_file($abs)) {
        out('  missing on disk');
        $pendingRestores[] = array('abs' => $abs, 'clean' => $clean, 'mode' => 'write');
        out($apply ? '  will write the official file' : '  would write the official file');
        continue;
    }
    $current = file_get_contents($abs);
    if ($current === false) {
        $failures++;
        out('  FAILED could not read live file');
        continue;
    }
    $mtime = date('Y-m-d H:i:s', filemtime($abs));
    out('  mtime ' . $mtime . '  live sha256 ' . hash('sha256', $current));
    out('  official sha256 ' . hash('sha256', $clean));
    if ($current === $clean) {
        out('  already matches Twenty Twenty 3.2');
        continue;
    }
    list($lineNo, $line) = first_diff_line($current, $clean);
    out('  differs at line ' . $lineNo . ': ' . short_line($line));
    $pendingRestores[] = array('abs' => $abs, 'clean' => $clean, 'mode' => 'replace');
    out($apply ? '  will quarantine the live file and write the official copy' : '  would quarantine the live file and write the official copy');
}

out('');
out('--- Dropped files (quarantine the whole file) ---');
$pendingDrops = array();
foreach ($drops as $rel) {
    $abs = rel_to_abs($home, $rel);
    $base = basename($rel);
    $site = strtok($rel, '/');
    out($rel);
    if (!is_file($abs)) {
        out('  missing');
        continue;
    }
    $size = filesize($abs);
    $mtime = date('Y-m-d H:i:s', filemtime($abs));
    $hash = hash_file('sha256', $abs);
    out('  present  ' . $size . ' bytes  mtime ' . $mtime);
    out('  sha256 ' . $hash);
    $refs = find_name_references($home . '/' . $site, $base, $abs);
    if ($refs) {
        out('  referenced from:');
        foreach ($refs as $ref) {
            out('    ' . $ref);
        }
    } else {
        out('  no plaintext filename reference under themes, plugins, mu-plugins, or core loaders');
    }
    $pendingDrops[] = $abs;
    out($apply ? '  will move to quarantine' : '  would move to quarantine');
}

if ($apply) {
    out('');
    out('--- Applying theme restores ---');
    foreach ($pendingRestores as $job) {
        if ($job['mode'] === 'write') {
            if (file_put_contents($job['abs'], $job['clean']) === false) {
                $failures++;
                out('FAILED could not write ' . $job['abs']);
            } else {
                chmod($job['abs'], 0644);
                out('wrote ' . $job['abs']);
            }
            continue;
        }
        list($ok, $detail) = quarantine_file($job['abs'], $home, $quarantine);
        if (!$ok) {
            $failures++;
            out('FAILED ' . $detail);
            continue;
        }
        if (file_put_contents($job['abs'], $job['clean']) === false) {
            $failures++;
            out('FAILED official write after moving live file to ' . $detail);
            continue;
        }
        chmod($job['abs'], 0644);
        out('restored ' . $job['abs']);
        out('  previous copy: ' . $detail);
    }
    out('--- Applying quarantine ---');
    foreach ($pendingDrops as $abs) {
        if (!is_file($abs)) {
            out('already gone ' . $abs);
            continue;
        }
        list($ok, $detail) = quarantine_file($abs, $home, $quarantine);
        if ($ok) {
            out('moved ' . $detail);
        } else {
            $failures++;
            out('FAILED ' . $detail);
        }
    }
}

out('');
if ($apply) {
    out($failures === 0 ? 'Finished.' : 'Finished with ' . $failures . ' failure(s).');
    out('Rescan both sites in Imunify after this. These six paths were the whole reported set.');
} else {
    out('Dry run only. Re-run with --apply to quarantine and restore.');
}

exit($failures === 0 ? 0 : 1);
