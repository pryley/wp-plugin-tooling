<?php

/*
 * macOS ships GNU Make 3.81, so plugin.mk must not use what later versions added.
 * CI runs a newer make, which would accept all of these.
 */

dataset('syntax newer than make 3.81', [
    'the ::= assignment (4.0)' => ['/::=/'],
    'the != shell assignment (4.0)' => ['/^\s*[A-Za-z_][A-Za-z0-9_]*\s*!=/m'],
    '.ONESHELL (3.82)' => ['/^\.ONESHELL\b/m'],
    '.RECIPEPREFIX (3.82)' => ['/\.RECIPEPREFIX/'],
    'undefine (3.82)' => ['/^\s*undefine\b/m'],
    'private variables (3.82)' => ['/^\s*private\s+[A-Za-z_]/m'],
    'the file function (4.0)' => ['/\$\(file\s/'],
    'the let function (4.4)' => ['/\$\(let\s/'],
    'the intcmp function (4.4)' => ['/\$\(intcmp\s/'],
]);

test('plugin.mk avoids syntax newer than make 3.81', function (string $pattern) {
    $makefile = (string) file_get_contents(dirname(__DIR__, 2).'/make/plugin.mk');

    expect(preg_match($pattern, $makefile))->toBe(0);
})->with('syntax newer than make 3.81');
