<?php

namespace Pryley\WpPluginTooling\Pest;

/**
 * The per-test isolation of a WordPress plugin's Pest suite: a database transaction, the hook
 * snapshot, the request reset, and tripwires for a commit or a WP_IMPORTING nobody declared.
 *
 *     $isolation = new Isolation(reset: fn () => resetGlobalState(), purge: fn () => purgeCommittedRows());
 *     uses()->beforeEach($isolation->beforeEach())->afterEach($isolation->afterEach())->in('Unit', 'Integration');
 */
final class Isolation
{
    private array $counts = [];
    private string $sentinel = '';

    public function __construct(
        private readonly ?\Closure $prelude = null, // runs first in beforeEach, outside the transaction
        private readonly ?\Closure $reset = null, // runs in afterEach once the object cache is flushed
        private readonly ?\Closure $purge = null, // removes the plugin's rows after a declared commit
    ) {
    }

    public function afterEach(): \Closure
    {
        $isolation = $this; // Pest binds the closure to the test case, which replaces $this
        return fn () => $isolation->close();
    }

    public function beforeEach(): \Closure
    {
        $isolation = $this;
        return fn () => $isolation->open();
    }

    /**
     * End the test's transaction, reset what a transaction cannot, and fail on a leak.
     */
    public function close(): void
    {
        global $wpdb;
        $declared = commitWasDeclared();
        commitWasDeclared(false);
        // Under autocommit = 0 a DDL statement commits and opens a new transaction.
        // What a declared test writes after its last DDL is usually its cleanup, so it is committed too.
        $wpdb->query($declared ? 'COMMIT' : 'ROLLBACK');
        $wpdb->query('SET autocommit = 1');
        // The sentinel was written inside the transaction, so only a commit leaves it behind.
        $committed = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", $this->sentinel
        ));
        if ($committed) {
            $wpdb->delete($wpdb->options, ['option_name' => $this->sentinel]);
        }
        if ($committed && $declared && null !== $this->purge) {
            ($this->purge)();
        }
        restoreHooks();
        resetRequestState();
        wp_cache_flush(); // before the reset, which may read options the rollback changed
        if (null !== $this->reset) {
            ($this->reset)();
        }
        if ($committed && !$declared) {
            throw new \RuntimeException(
                'This test COMMITTED its transaction, so the rows it wrote before that point are now '.
                'permanent and will break a later test. Something it called ran DDL (CREATE, ALTER, '.
                'DROP or TRUNCATE TABLE) or its own START TRANSACTION. If that is intended, declare it '.
                'with commitsTransaction().'
            );
        }
        $leaked = $committed && $declared ? $this->leakedRows() : [];
        if (!empty($leaked)) {
            throw new \RuntimeException(sprintf(
                'This test committed its transaction and left rows behind (%s). Delete what it wrote '.
                'in its own cleanup, or remove it in the purge the suite passes to Isolation.',
                implode(', ', $leaked)
            ));
        }
        $importing = wpImportingWasDeclared();
        wpImportingWasDeclared(false);
        if (defined('WP_IMPORTING') && !$importing) {
            throw new \RuntimeException(
                'This test caused WP_IMPORTING to be defined and did not say so. The constant cannot '.
                'be undefined, so every later test in this process now runs as an import. Move the '.
                'test into the import suite (declared last in phpunit.xml), which says '.
                'definesWpImporting() in its beforeEach.'
            );
        }
    }

    /**
     * Start the test's transaction and take the snapshots close() compares and restores.
     */
    public function open(): void
    {
        global $wpdb;
        if (null !== $this->prelude) {
            ($this->prelude)();
        }
        $this->counts = $this->counts();
        $wpdb->query('SET autocommit = 0');
        $wpdb->query('START TRANSACTION');
        $this->sentinel = 'wp_plugin_tooling_sentinel_'.uniqid();
        $wpdb->insert($wpdb->options, [
            'autoload' => 'no',
            'option_name' => $this->sentinel,
            'option_value' => '1',
        ]);
        backupHooks();
        emptyMailbox();
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        global $wpdb;
        return [
            // WordPress writes transients during a test, the doing_cron lock among them.
            'options' => (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->options}
                 WHERE option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%'"
            ),
            'posts' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}"),
            'terms' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->terms}"),
        ];
    }

    /**
     * @return string[] each table whose row count differs from before the test, with the difference
     */
    private function leakedRows(): array
    {
        $leaked = [];
        foreach ($this->counts() as $table => $count) {
            $difference = $count - ($this->counts[$table] ?? $count);
            if (0 !== $difference) {
                $leaked[] = sprintf('%s %+d', $table, $difference);
            }
        }
        return $leaked;
    }
}
