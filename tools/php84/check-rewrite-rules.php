<?php
/**
 * Verify that bundled rewrite examples keep the public friendly URL contract.
 */

$root = dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR;
$checks = array(
    array(
        'file' => '.htaccess',
        'patterns' => array(
            'Apache pay route' => '/RewriteRule\s+\^pay\/\(\.\*\)\$\s+pay\.php\?s=\$1/i',
            'Apache API route' => '/RewriteRule\s+\^api\/\(\.\*\)\$\s+api\.php\?s=\$1/i',
            'Apache document route' => '/RewriteRule\s+\^doc\/.*index\.php\?doc=\$1/i',
            'Apache pretty page route' => '/RewriteRule\s+\^\(\.\[a-zA-Z0-9/',
        ),
    ),
    array(
        'file' => 'nginx.txt',
        'patterns' => array(
            'Nginx pay route' => '/rewrite\s+\^\/pay\/\(\.\*\)\$\s+\/pay\.php\?s=\$1\s+last/i',
            'Nginx API route' => '/rewrite\s+\^\/api\/\(\.\*\)\$\s+\/api\.php\?s=\$1\s+last/i',
            'Nginx includes deny' => '/location\s+\^~\s+\/includes\s*\{\s*deny\s+all;/is',
            'Nginx plugins deny' => '/location\s+\^~\s+\/plugins\s*\{\s*deny\s+all;/is',
        ),
    ),
    array(
        'file' => 'IIS.txt',
        'patterns' => array(
            'IIS pay route' => '/<match\s+url="?\^pay\/\(\.\*\)"?.*?<action\s+type="Rewrite"\s+url="pay\.php\?s=\{R:1\}"/is',
            'IIS API route' => '/<match\s+url="?\^api\/\(\.\*\)"?.*?<action\s+type="Rewrite"\s+url="api\.php\?s=\{R:1\}"/is',
        ),
    ),
);

$failed = 0;

foreach ($checks as $check) {
    $path = $root . $check['file'];
    $content = file_get_contents($path);
    if ($content === false) {
        echo '[FAIL] unable to read rewrite file: ' . $check['file'] . PHP_EOL;
        $failed++;
        continue;
    }

    foreach ($check['patterns'] as $name => $pattern) {
        if (preg_match($pattern, $content)) {
            echo '[OK] ' . $name . PHP_EOL;
        } else {
            echo '[FAIL] ' . $name . ' missing or changed in ' . $check['file'] . PHP_EOL;
            $failed++;
        }
    }
}

if ($failed > 0) {
    echo 'Rewrite rule check failed with ' . $failed . ' issue(s).' . PHP_EOL;
    exit(1);
}

echo 'Rewrite rule check passed.' . PHP_EOL;
