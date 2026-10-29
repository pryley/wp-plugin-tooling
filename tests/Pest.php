<?php

/*
 * The bins run as separate processes from a scratch copy of a fixture, so a test
 * sees what a plugin's `make` target would.
 */

/**
 * Copy a fixture folder to a fresh temporary folder and return its path.
 */
function scratch(string $fixture): string
{
    $dir = sys_get_temp_dir().'/wp-plugin-tooling-test-'.uniqid();
    mkdir($dir, 0777, true);
    if ('' !== $fixture) {
        exec(sprintf('cp -R %s/. %s', escapeshellarg(__DIR__."/fixtures/{$fixture}"), escapeshellarg($dir)));
    }
    return $dir;
}

/**
 * Run one of the package's bins in a folder.
 *
 * @return array{code: int, output: string}
 */
function runBin(string $bin, string $cwd, array $args = [], string $input = ''): array
{
    $command = array_merge([PHP_BINARY, dirname(__DIR__)."/bin/{$bin}"], $args);
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    fwrite($pipes[0], $input);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($process), 'output' => (string) $output];
}
