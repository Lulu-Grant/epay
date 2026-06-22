<?php
/**
 * Static PHP 7.4 floor check.
 *
 * PHP 7.4 lint catches parse-level incompatibilities when it is available.
 * This scanner adds an explicit guard for PHP 8+ constructs and common PHP 8+
 * standard-library calls so the compatibility floor is visible in CI output.
 */

$root = dirname(dirname(__DIR__)) . DIRECTORY_SEPARATOR;
$failed = 0;
$checked = 0;

$php8FunctionCalls = array(
    'array_is_list' => 'PHP 8.1',
    'enum_exists' => 'PHP 8.1',
    'fdiv' => 'PHP 8.0',
    'get_debug_type' => 'PHP 8.0',
    'get_resource_id' => 'PHP 8.0',
    'json_validate' => 'PHP 8.3',
    'mb_str_pad' => 'PHP 8.3',
    'mysqli_execute_query' => 'PHP 8.2',
    'preg_last_error_msg' => 'PHP 8.0',
    'str_contains' => 'PHP 8.0',
    'str_ends_with' => 'PHP 8.0',
    'str_starts_with' => 'PHP 8.0',
);

$php8Tokens = array();
foreach (array(
    'T_ATTRIBUTE' => 'PHP 8.0 attributes are not PHP 7.4 compatible',
    'T_ENUM' => 'PHP 8.1 enums are not PHP 7.4 compatible',
    'T_MATCH' => 'PHP 8.0 match expressions are not PHP 7.4 compatible',
    'T_READONLY' => 'PHP 8.1 readonly is not PHP 7.4 compatible',
) as $constant => $message) {
    if (defined($constant)) {
        $php8Tokens[constant($constant)] = $message;
    }
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        function ($current, $key, $iterator) {
            $path = $current->getPathname();
            if ($current->isDir()) {
                $name = $current->getFilename();
                if ($name === '.git') {
                    return false;
                }
                $normalized = str_replace(DIRECTORY_SEPARATOR, '/', $path);
                if (strpos($normalized, '/includes/vendor') !== false) {
                    return false;
                }
            }
            return true;
        }
    )
);

foreach ($iterator as $file) {
    if (!$file->isFile() || substr($file->getFilename(), -4) !== '.php') {
        continue;
    }

    $checked++;
    $path = $file->getPathname();
    $relative = './' . str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root)));
    $code = file_get_contents($path);
    if ($code === false) {
        echo '[FAIL] ' . $relative . ': unable to read file' . PHP_EOL;
        $failed++;
        continue;
    }

    $tokens = token_get_all($code);
    $count = count($tokens);
    $inFunctionDefinition = false;
    $pendingFunctionName = false;
    $lastSignificantToken = null;

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        $id = is_array($token) ? $token[0] : null;
        $text = is_array($token) ? $token[1] : $token;
        $line = is_array($token) ? $token[2] : null;

        if (is_array($token) && isset($php8Tokens[$id])) {
            echo '[FAIL] ' . $relative . ':' . $line . ': ' . $php8Tokens[$id] . PHP_EOL;
            $failed++;
        }

        if ($id === T_FUNCTION) {
            $inFunctionDefinition = true;
            $pendingFunctionName = true;
            $lastSignificantToken = $token;
            continue;
        }

        if ($id === T_STRING) {
            $lower = strtolower($text);
            $next = nextSignificantToken($tokens, $i + 1);
            $prev = previousSignificantToken($tokens, $i - 1);

            if ($pendingFunctionName) {
                $pendingFunctionName = false;
                $inFunctionDefinition = false;
                $lastSignificantToken = $token;
                continue;
            }

            if (isset($php8FunctionCalls[$lower]) && $next === '(' && !isObjectOrStaticAccess($prev)) {
                echo '[FAIL] ' . $relative . ':' . $line . ': direct call to ' . $lower . '() requires ' . $php8FunctionCalls[$lower] . PHP_EOL;
                $failed++;
            }
        }

        if ($text === '(' && $pendingFunctionName) {
            $pendingFunctionName = false;
            $inFunctionDefinition = false;
        }

        if (isSignificant($token)) {
            $lastSignificantToken = $token;
        }
    }
}

if ($failed > 0) {
    echo 'PHP 7.4 floor check failed. Checked PHP files: ' . $checked . PHP_EOL;
    exit(1);
}

echo 'PHP 7.4 floor check passed. Checked PHP files: ' . $checked . PHP_EOL;

function isSignificant($token)
{
    if (!is_array($token)) {
        return trim($token) !== '';
    }
    return !in_array($token[0], array(T_WHITESPACE, T_COMMENT, T_DOC_COMMENT), true);
}

function previousSignificantToken(array $tokens, $index)
{
    for ($i = $index; $i >= 0; $i--) {
        if (isSignificant($tokens[$i])) {
            return $tokens[$i];
        }
    }
    return null;
}

function nextSignificantToken(array $tokens, $index)
{
    $count = count($tokens);
    for ($i = $index; $i < $count; $i++) {
        if (isSignificant($tokens[$i])) {
            return is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
        }
    }
    return null;
}

function isObjectOrStaticAccess($token)
{
    if (!is_array($token)) {
        return false;
    }
    return in_array($token[0], array(T_OBJECT_OPERATOR, T_DOUBLE_COLON), true);
}
