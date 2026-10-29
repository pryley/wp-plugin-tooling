<?php

/**
 * A fixture plugin committed to a fresh git repository.
 */
function releasable(): string
{
    $dir = scratch('plugin');
    exec(sprintf(
        'cd %s && git init -q && git add -A && git -c user.name=Test -c user.email=test@example.org commit -qm fixture',
        escapeshellarg($dir)
    ));
    return $dir;
}

test('a committed plugin whose versions match walks through the checklist', function () {
    $dir = releasable();

    $run = runBin('release-check', $dir, ['--plugin-file=fixture-plugin.php', '--ask=Updated the What\'s New page?'], str_repeat("\n", 20));

    expect($run['code'])->toBe(0)
        ->and($run['output'])->toContain("Updated the 'Requires PHP: 8.1' in fixture-plugin.php?")
        ->and($run['output'])->toContain('Verified the changelog release date (2026-10-28)?')
        ->and($run['output'])->toContain("Updated the What's New page?")
        ->and($run['output'])->not->toContain('wpml-config.xml')
        ->and($run['output'])->toContain('PRESS [ENTER] TO BEGIN RELEASING 1.2.3');
});

test('the wpml-config.xml item appears only when the plugin has the file', function () {
    $dir = releasable();
    file_put_contents("{$dir}/wpml-config.xml", '<wpml-config/>');
    exec(sprintf('cd %s && git add -A && git -c user.name=Test -c user.email=test@example.org commit -qm wpml', escapeshellarg($dir)));

    $run = runBin('release-check', $dir, ['--plugin-file=fixture-plugin.php'], str_repeat("\n", 20));

    expect($run['output'])->toContain('Updated wpml-config.xml?');
});

test('uncommitted changes stop the release', function () {
    $dir = releasable();
    file_put_contents("{$dir}/readme.txt", "\n", FILE_APPEND);

    $run = runBin('release-check', $dir, ['--plugin-file=fixture-plugin.php']);

    expect($run['code'])->toBe(1)
        ->and($run['output'])->toContain('You forgot to commit changes.');
});

test('a version that differs from the stable tag stops the release', function () {
    $dir = releasable();
    file_put_contents("{$dir}/readme.txt", str_replace('Stable tag: 1.2.3', 'Stable tag: 1.2.2', (string) file_get_contents("{$dir}/readme.txt")));
    exec(sprintf('cd %s && git -c user.name=Test -c user.email=test@example.org commit -qam mismatch', escapeshellarg($dir)));

    $run = runBin('release-check', $dir, ['--plugin-file=fixture-plugin.php']);

    expect($run['code'])->toBe(1)
        ->and($run['output'])->toContain('Version mismatch.');
});

test('a folder that is not a git repository stops the release', function () {
    $dir = scratch('plugin');

    $run = runBin('release-check', $dir, ['--plugin-file=fixture-plugin.php']);

    expect($run['code'])->toBe(1)
        ->and($run['output'])->toContain('not a git repository');
});
