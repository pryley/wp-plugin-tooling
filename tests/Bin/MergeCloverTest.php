<?php

test('a line covered by any input is covered in the merged file', function () {
    $dir = scratch('clover');

    $run = runBin('merge-clover', $dir, ['main.xml', 'multisite.xml', 'merged.xml']);

    $merged = simplexml_load_file("{$dir}/merged.xml");
    $counts = [];
    foreach ($merged->xpath('//file[contains(@name, "Alpha.php")]/line') as $line) {
        $counts[(int) $line['num']] = (int) $line['count'];
    }
    expect($run['code'])->toBe(0)
        ->and($counts)->toBe([3 => 2, 4 => 1, 5 => 0])
        ->and((string) $merged->xpath('//project/metrics')[0]['coveredstatements'])->toBe('2');
});

test('the merged table names each file from the folder they all share', function () {
    $dir = scratch('clover');

    $run = runBin('merge-clover', $dir, ['main.xml', 'multisite.xml', 'merged.xml']);

    expect($run['output'])->toContain('Alpha ')
        ->and($run['output'])->toContain('Sub/Beta ')
        ->and($run['output'])->not->toContain('/var/www');
});

test('merging nothing is an error', function () {
    $dir = scratch('clover');

    $run = runBin('merge-clover', $dir, ['missing.xml', 'merged.xml']);

    expect($run['code'])->toBe(1)
        ->and("{$dir}/merged.xml")->not->toBeFile();
});
