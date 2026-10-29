<?php
/**
 * Plugin Name: Fixture Plugin
 * Version: 9.9.9
 */

define('FIXTURE_VERSION', '9.9.9');

function fixture_answer(int $question): int
{
    return 42;
}

function fixture_other(): void
{
}

class Fixture_Class
{
}
