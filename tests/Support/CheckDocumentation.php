<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Symfony\Component\Process\Process;

/** Lint each standalone PHP example, including the named-argument examples. */
function checkDocExample(string $code, string $file, int $line): void
{
    if (trim($code) === '') {
        return;
    }
    $temporary = tempnam(sys_get_temp_dir(), 'pathwise-doc-example-');
    try {
        file_put_contents($temporary, "<?php\n" . $code);
        $process = new Process([PHP_BINARY, '-l', $temporary]);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new RuntimeException($file . ':' . $line . ' ' . $process->getErrorOutput() . $process->getOutput());
        }
    } finally {
        unlink($temporary);
    }
}

$files = glob(dirname(__DIR__, 2) . '/docs/*.rst');
$files[] = dirname(__DIR__, 2) . '/README.md';
$count = 0;
foreach ($files as $file) {
    $code = null;
    $start = 0;
    $markdown = false;
    foreach (file($file) as $index => $line) {
        if ($code === null) {
            if (in_array(trim($line), ['.. code-block:: php', '```php'], true)) {
                $code = '';
                $start = $index + 1;
                $markdown = trim($line) === '```php';
            }
            continue;
        }
        $end = $markdown ? str_starts_with($line, '```') : (trim($line) !== '' && !str_starts_with($line, '   '));
        if (!$end) {
            $code .= $markdown ? $line : (str_starts_with($line, '   ') ? substr($line, 3) : $line);
            continue;
        }
        checkDocExample($code, $file, $start);
        $count++;
        $code = null;
    }
    if ($code !== null) {
        checkDocExample($code, $file, $start);
        $count++;
    }
}
printf("%d documented PHP examples passed syntax validation.\n", $count);
