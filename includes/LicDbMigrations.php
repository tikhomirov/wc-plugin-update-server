<?php
/**
 * Versioned database repairs for the integration.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LicDbMigrations
{
    public const VERSION_OPTION = 'wc_pus_db_version';
    public const REPORT_OPTION = 'wc_pus_db_migration_report';
    private const LOCK_OPTION = 'wc_pus_db_migration_lock';
    private const TARGET_VERSION = 1;
    private const LOCK_TTL = 600;

    public function add_actions(): void
    {
        self::register_cli_commands();

        if (!defined('WP_CLI') || !WP_CLI) {
            self::maybe_migrate();
        }
    }

    public static function maybe_migrate(): void
    {
        if (self::is_up_to_date()) {
            return;
        }

        if (!apply_filters('wc_pus_run_migrations', true)) {
            return;
        }

        $report = self::migrate();

        if ('failed' === $report['status']) {
            error_log('WC PUS database migration failed: ' . wp_json_encode($report));
        }
    }

    public static function is_up_to_date(): bool
    {
        return (int) get_option(self::VERSION_OPTION, 0) >= self::TARGET_VERSION;
    }

    /**
     * @return array{status: string, from: int, to: int, steps: array<string, array<string, mixed>>}
     */
    public static function migrate(bool $force = false): array
    {
        $from = (int) get_option(self::VERSION_OPTION, 0);

        if (!$force && $from >= self::TARGET_VERSION) {
            return self::report('up-to-date', $from, $from, []);
        }

        if (!self::acquire_lock()) {
            return self::report('locked', $from, $from, []);
        }

        try {
            $steps = self::restore_auto_increment_columns();

            foreach ($steps as $step) {
                if (isset($step['error']) || empty($step['verified'])) {
                    return self::report('failed', $from, $from, $steps);
                }
            }

            update_option(self::VERSION_OPTION, self::TARGET_VERSION, false);

            return self::report('migrated', $from, self::TARGET_VERSION, $steps);
        } finally {
            delete_option(self::LOCK_OPTION);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function restore_auto_increment_columns(): array
    {
        global $wpdb;

        $targets = [
            'comments' => [
                $wpdb->comments,
                'comment_ID',
                [[$wpdb->commentmeta, 'comment_id']],
            ],
            'licenses' => [$wpdb->prefix . 'upserv_licenses', 'id', []],
            'nonces' => [$wpdb->prefix . 'upserv_nonce', 'id', []],
        ];

        $steps = [];

        foreach ($targets as $name => [$table, $column, $references]) {
            $steps[$name] = self::restore_auto_increment($table, $column, $references);

            if (isset($steps[$name]['error']) || empty($steps[$name]['verified'])) {
                break;
            }
        }

        return $steps;
    }

    /**
     * @param array<int, array{0: string, 1: string}> $references
     *
     * @return array<string, mixed>
     */
    private static function restore_auto_increment(string $table, string $column, array $references = []): array
    {
        global $wpdb;

        $schema = self::read_column_schema($table, $column);

        if (null === $schema) {
            return ['verified' => false, 'error' => sprintf('%s.%s does not exist.', $table, $column)];
        }

        if ('PRI' !== $schema['COLUMN_KEY'] || 'NO' !== $schema['IS_NULLABLE']) {
            return ['verified' => false, 'error' => sprintf('%s.%s is not a non-null primary key.', $table, $column)];
        }

        if (self::has_auto_increment($schema['EXTRA'])) {
            return ['verified' => true, 'skipped' => sprintf('%s.%s already has AUTO_INCREMENT.', $table, $column)];
        }

        $definition = self::build_column_definition($schema);

        if (null === $definition) {
            return ['verified' => false, 'error' => sprintf('Unexpected type for %s.%s: %s.', $table, $column, $schema['COLUMN_TYPE'])];
        }

        $moved_zero_id = self::move_zero_id($table, $column, $references);

        if (isset($moved_zero_id['error'])) {
            return ['verified' => false] + $moved_zero_id;
        }

        $previous_sql_mode = (string) $wpdb->get_var('SELECT @@SESSION.sql_mode');
        $wpdb->query("SET SESSION sql_mode = ''");

        try {
            $ddl = sprintf('ALTER TABLE `%s` MODIFY COLUMN `%s` %s', $table, $column, $definition);
            $result = $wpdb->query($ddl); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $error = $wpdb->last_error;
        } finally {
            $wpdb->query($wpdb->prepare('SET SESSION sql_mode = %s', $previous_sql_mode));
        }

        if (false === $result) {
            return ['verified' => false, 'error' => $error, 'moved_zero_id' => $moved_zero_id['moved']];
        }

        $verified = self::has_auto_increment(self::read_column_extra($table, $column));

        return [
            'verified' => $verified,
            'moved_zero_id' => $moved_zero_id['moved'],
            'next_id' => self::read_next_auto_increment($table),
        ];
    }

    /**
     * AUTO_INCREMENT converts zero to one during ALTER. Move an existing zero
     * first because ID 1 normally already exists and would make the ALTER fail.
     *
     * @param array<int, array{0: string, 1: string}> $references
     *
     * @return array{moved: int|null, error?: string}
     */
    private static function move_zero_id(string $table, string $column, array $references): array
    {
        global $wpdb;

        $zero_exists = (int) $wpdb->get_var(sprintf('SELECT COUNT(*) FROM `%s` WHERE `%s` = 0', $table, $column));

        if (0 === $zero_exists) {
            return ['moved' => null];
        }

        if (!self::supports_transactions($table, $references)) {
            return ['moved' => null, 'error' => sprintf('Cannot safely move ID 0 in non-transactional table %s.', $table)];
        }

        $new_id = (int) $wpdb->get_var(sprintf('SELECT COALESCE(MAX(`%s`), 0) + 1 FROM `%s`', $column, $table));
        $wpdb->query('START TRANSACTION');

        $updated = $wpdb->query(
            $wpdb->prepare(sprintf('UPDATE `%s` SET `%s` = %%d WHERE `%s` = 0', $table, $column, $column), $new_id)
        );

        if (1 !== $updated) {
            $error = $wpdb->last_error ?: sprintf('Expected to move one row in %s, moved %d.', $table, (int) $updated);
            $wpdb->query('ROLLBACK');

            return ['moved' => null, 'error' => $error];
        }

        foreach ($references as [$reference_table, $reference_column]) {
            $result = $wpdb->query(
                $wpdb->prepare(
                    sprintf('UPDATE `%s` SET `%s` = %%d WHERE `%s` = 0', $reference_table, $reference_column, $reference_column),
                    $new_id
                )
            );

            if (false === $result) {
                $error = $wpdb->last_error;
                $wpdb->query('ROLLBACK');

                return ['moved' => null, 'error' => $error];
            }
        }

        if (false === $wpdb->query('COMMIT')) {
            return ['moved' => null, 'error' => $wpdb->last_error ?: 'Could not commit zero-ID repair.'];
        }

        return ['moved' => $new_id];
    }

    /**
     * @param array<int, array{0: string, 1: string}> $references
     */
    private static function supports_transactions(string $table, array $references): bool
    {
        $tables = [$table];

        foreach ($references as [$reference_table]) {
            $tables[] = $reference_table;
        }

        foreach ($tables as $candidate) {
            if ('InnoDB' !== self::read_table_engine($candidate)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, string>|null
     */
    private static function read_column_schema(string $table, string $column): ?array
    {
        global $wpdb;

        $schema = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                $table,
                $column
            ),
            ARRAY_A
        );

        return is_array($schema) ? $schema : null;
    }

    /**
     * @param array<string, string> $schema
     */
    private static function build_column_definition(array $schema): ?string
    {
        $type = strtolower(trim($schema['COLUMN_TYPE']));

        if (1 !== preg_match('/^(?:tinyint|smallint|mediumint|int|bigint)(?:\(\d+\))?(?: unsigned)?$/', $type)) {
            return null;
        }

        return $type . ' NOT NULL AUTO_INCREMENT';
    }

    private static function has_auto_increment(string $extra): bool
    {
        return false !== stripos($extra, 'auto_increment');
    }

    private static function read_column_extra(string $table, string $column): string
    {
        $schema = self::read_column_schema($table, $column);

        return $schema['EXTRA'] ?? '';
    }

    private static function read_table_engine(string $table): string
    {
        global $wpdb;

        return (string) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table
            )
        );
    }

    private static function read_next_auto_increment(string $table): ?int
    {
        global $wpdb;

        $next = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                $table
            )
        );

        return null === $next ? null : (int) $next;
    }

    private static function acquire_lock(): bool
    {
        if (add_option(self::LOCK_OPTION, time(), '', false)) {
            return true;
        }

        if ((int) get_option(self::LOCK_OPTION, 0) > time() - self::LOCK_TTL) {
            return false;
        }

        delete_option(self::LOCK_OPTION);

        return add_option(self::LOCK_OPTION, time(), '', false);
    }

    /**
     * @param array<string, array<string, mixed>> $steps
     *
     * @return array{status: string, from: int, to: int, steps: array<string, array<string, mixed>>}
     */
    private static function report(string $status, int $from, int $to, array $steps): array
    {
        $report = compact('status', 'from', 'to', 'steps');
        $report['timestamp'] = current_time('mysql', true);
        update_option(self::REPORT_OPTION, $report, false);

        return $report;
    }

    private static function register_cli_commands(): void
    {
        if (!defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI')) {
            return;
        }

        WP_CLI::add_command('wc-pus db-status', static function (): void {
            WP_CLI::line(wp_json_encode([
                'version' => (int) get_option(self::VERSION_OPTION, 0),
                'target' => self::TARGET_VERSION,
                'report' => get_option(self::REPORT_OPTION, []),
            ], JSON_PRETTY_PRINT));
        });

        WP_CLI::add_command('wc-pus db-migrate', static function (array $args, array $assoc_args): void {
            $report = self::migrate(isset($assoc_args['force']));

            if ('failed' === $report['status']) {
                WP_CLI::error(wp_json_encode($report, JSON_PRETTY_PRINT));
            }

            WP_CLI::success(wp_json_encode($report, JSON_PRETTY_PRINT));
        });
    }
}
