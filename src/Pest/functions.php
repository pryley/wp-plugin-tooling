<?php

namespace Pryley\WpPluginTooling\Pest;

/*
 * The helpers a WordPress plugin's Pest suite shares with the isolation harness (Isolation):
 * the hook snapshot, the request reset, the mailbox, the HTTP guard and the declarations a
 * test makes about what it cannot undo.
 */

/**
 * The hook globals from wp-includes/plugin.php besides $wp_filter:
 *
 *     int[]     $wp_actions         did_action counts
 *     int[]     $wp_filters         did_filter counts (6.1+)
 *     string[]  $wp_current_filter  the hook stack
 */
const HOOK_GLOBALS = ['wp_actions', 'wp_filters', 'wp_current_filter'];

/**
 * Return the snapshot of the hook globals that backupHooks() takes and restoreHooks() puts back.
 */
function hookBackup(): \ArrayObject
{
    static $backup;
    return $backup ??= new \ArrayObject([]);
}

/**
 * Copy the hook globals into the snapshot.
 */
function backupHooks(): void
{
    $backup = ['wp_filter' => []];
    foreach (HOOK_GLOBALS as $key) {
        if (isset($GLOBALS[$key])) {
            $backup[$key] = $GLOBALS[$key];
        }
    }
    foreach ($GLOBALS['wp_filter'] as $hookName => $hook) {
        $backup['wp_filter'][$hookName] = clone $hook; // a WP_Hook is an object, so a copy would share it
    }
    hookBackup()->exchangeArray($backup);
}

function restoreHooks(): void
{
    $backup = hookBackup()->getArrayCopy();
    if (empty($backup)) {
        return;
    }
    foreach (HOOK_GLOBALS as $key) {
        if (array_key_exists($key, $backup)) {
            $GLOBALS[$key] = $backup[$key];
        }
    }
    $GLOBALS['wp_filter'] = [];
    foreach ($backup['wp_filter'] as $hookName => $hook) {
        $GLOBALS['wp_filter'][$hookName] = clone $hook;
    }
}

function resetRequestState(): void
{
    wp_set_current_user(0);
    $_GET = [];
    $_POST = [];
    $_REQUEST = [];
    $_FILES = [];
}

/**
 * Return the mail interceptMail() has recorded, which Isolation empties before each test.
 */
function mailbox(): \ArrayObject
{
    static $mailbox;
    return $mailbox ??= new \ArrayObject([]);
}

/**
 * Record every wp_mail() call in the mailbox instead of sending it. Call it once from the suite's bootstrap.
 */
function interceptMail(): void
{
    // A test container has no mail transport, so wp_mail() would return false.
    add_filter('pre_wp_mail', function ($shortCircuit, $atts) {
        mailbox()->append($atts);
        return true; // what wp_mail() returns on success
    }, 10, 2);
}

/**
 * @return array[] one entry per wp_mail() call: to, subject, message, headers…
 */
function sentMail(): array
{
    return mailbox()->getArrayCopy();
}

/**
 * Return the recipients of one email as a list.
 *
 * @return string[]
 */
function sentTo(int $index = 0): array
{
    $mail = sentMail();
    return array_values((array) ($mail[$index]['to'] ?? [])); // a filtered list can keep holes in its keys
}

function emptyMailbox(): void
{
    mailbox()->exchangeArray([]);
}

/**
 * Refuse every HTTP request a test has not intercepted. Call it once from the suite's bootstrap.
 */
function blockHttpRequests(): void
{
    // pre_http_request runs before the URL is validated, so it catches every transport.
    // At priority 999 it runs after interceptHttp(), which hooks in at the default priority.
    add_filter('pre_http_request', function ($response, $args, $url) {
        if (false !== $response) {
            return $response; // interceptHttp() or another filter has answered it
        }
        return new \WP_Error('http_request_failed', sprintf(
            'The test suite does not make HTTP requests, and something tried to reach %s. '.
            'If the code under test is meant to, intercept it with interceptHttp().',
            $url
        ));
    }, 999, 3);
}

/**
 * Record every HTTP request the code under test makes and answer each with a 200.
 *
 * @return \ArrayObject<int, array{url:string, args:array}>
 */
function interceptHttp(array $response = []): \ArrayObject
{
    $requests = new \ArrayObject();
    add_filter('pre_http_request', function ($pre, $args, $url) use ($requests, $response) {
        $requests->append(compact('args', 'url'));
        return array_replace([
            'body' => '',
            'cookies' => [],
            'filename' => null,
            'headers' => [],
            'http_response' => null,
            'response' => ['code' => 200, 'message' => 'OK'],
        ], $response);
    }, 10, 3);
    return $requests;
}

/**
 * Return the decoded JSON body of an intercepted request.
 */
function sentJson(\ArrayObject $requests, int $index = 0): array
{
    $body = $requests[$index]['args']['body'] ?? '';
    return json_decode((string) $body, true) ?? [];
}

/**
 * Declare that this test commits the per-test transaction.
 * Call it in a test that runs DDL (CREATE, ALTER, DROP or TRUNCATE TABLE) or runs START TRANSACTION.
 */
function commitsTransaction(): void
{
    commitWasDeclared(true);
}

/**
 * Set or return the flag commitsTransaction() sets, which Isolation reads and then clears.
 */
function commitWasDeclared(?bool $set = null): bool
{
    static $declared = false;
    if (null !== $set) {
        $declared = $set;
    }
    return $declared;
}

/**
 * Declare that this test causes WP_IMPORTING to be defined.
 * Only an import suite declared last in phpunit.xml should, in its beforeEach.
 */
function definesWpImporting(): void
{
    wpImportingWasDeclared(true);
}

/**
 * Set or return the flag definesWpImporting() sets, which Isolation reads and then clears.
 */
function wpImportingWasDeclared(?bool $set = null): bool
{
    static $declared = false;
    if (null !== $set) {
        $declared = $set;
    }
    return $declared;
}
