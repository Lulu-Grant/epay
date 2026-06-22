<?php
/**
 * Static safety checks for download endpoints.
 *
 * These endpoints should generate export content or proxy controlled media;
 * they must not read arbitrary local files based on request parameters.
 */

$root = dirname(__DIR__, 2);
$files = array(
    'admin/download.php' => array(
        'login_guard' => '$islogin==1',
    ),
    'user/download.php' => array(
        'login_guard' => '$islogin2==1',
    ),
);

$blockedPatterns = array(
    'readfile\s*\(' => 'readfile()',
    'fpassthru\s*\(' => 'fpassthru()',
    'fopen\s*\(' => 'fopen()',
    'file\s*\(' => 'file()',
    'SplFileObject\s*\(' => 'SplFileObject',
);

$failures = 0;

foreach ($files as $relative => $rules) {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        echo "[FAIL] download safety: missing {$relative}\n";
        $failures++;
        continue;
    }

    $source = file_get_contents($path);
    if ($source === false) {
        echo "[FAIL] download safety: unable to read {$relative}\n";
        $failures++;
        continue;
    }

    if (strpos($source, $rules['login_guard']) === false) {
        echo "[FAIL] download safety: {$relative} missing login guard {$rules['login_guard']}\n";
        $failures++;
    } else {
        echo "[OK] download safety: {$relative} login guard found\n";
    }

    foreach ($blockedPatterns as $pattern => $label) {
        if (preg_match('/' . $pattern . '/i', $source)) {
            echo "[FAIL] download safety: {$relative} contains blocked local file output primitive {$label}\n";
            $failures++;
        }
    }

    if ($relative === 'admin/download.php' && preg_match('/file_get_contents\s*\(/i', $source)) {
        echo "[FAIL] download safety: {$relative} should not use file_get_contents()\n";
        $failures++;
    }

    echo "[OK] download safety: {$relative} has no blocked local file output primitives\n";
}

if ($failures > 0) {
    echo "Download safety checks failed: {$failures}\n";
    exit(1);
}

echo "Download safety checks passed\n";
