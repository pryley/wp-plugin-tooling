<?php

/**
 * The version each of the three files carries.
 *
 * @return string[]
 */
function versions(string $dir): array
{
    preg_match('/"version":\s*"([^"]+)"/', (string) file_get_contents("{$dir}/composer.json"), $composer);
    preg_match('/^Stable tag:\s*(\S+)/m', (string) file_get_contents("{$dir}/readme.txt"), $readme);
    preg_match('/^\s*\*\s*Version:\s*(\S+)/m', (string) file_get_contents("{$dir}/fixture-plugin.php"), $plugin);
    return [$composer[1], $readme[1], $plugin[1]];
}

test('a patch bump moves the version in all three files', function () {
    $dir = scratch('plugin');

    $run = runBin('bump', $dir, ['patch', '--plugin-file=fixture-plugin.php']);

    expect($run['code'])->toBe(0)
        ->and(versions($dir))->toBe(['1.2.4', '1.2.4', '1.2.4']);
});

test('a beta bump starts a prerelease of the next patch', function () {
    $dir = scratch('plugin');

    runBin('bump', $dir, ['beta', '--plugin-file=fixture-plugin.php']);

    expect(versions($dir))->toBe(['1.2.4-beta1', '1.2.4-beta1', '1.2.4-beta1']);
});

test('a dry run writes nothing', function () {
    $dir = scratch('plugin');

    $run = runBin('bump', $dir, ['minor', '--dry-run', '--plugin-file=fixture-plugin.php']);

    expect($run['output'])->toContain('[dry-run]')
        ->and(versions($dir))->toBe(['1.2.3', '1.2.3', '1.2.3']);
});

test('a missing plugin file stops the bump before anything is written', function () {
    $dir = scratch('plugin');

    $run = runBin('bump', $dir, ['patch', '--plugin-file=missing.php']);

    expect($run['code'])->toBe(1)
        ->and(versions($dir))->toBe(['1.2.3', '1.2.3', '1.2.3']);
});
