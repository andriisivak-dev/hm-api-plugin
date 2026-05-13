<?php

declare(strict_types=1);

namespace CSP\Repositories;

/**
 * Repository for the wp_csp_clients custom table.
 *
 * This is a direct port of CSP_Clients_Repository from hemant-core plugin.
 * The table schema is managed by CustomerMigrations::up().
 *
 * @package CSP\Repositories
 */
class CustomerRepository
{
    // -------------------------------------------------------------------------
    // Table helpers
    // -------------------------------------------------------------------------

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'csp_clients';
    }

    // -------------------------------------------------------------------------
    // Internal query builder
    // -------------------------------------------------------------------------

    /**
     * @return array{0: string[], 1: scalar[]}
     */
    private static function buildFilters(string $search, string $billingCenter): array
    {
        global $wpdb;

        $where  = [];
        $params = [];

        if ($search !== '') {
            $like      = '%' . $wpdb->esc_like($search) . '%';
            $where[]   = '( company_name LIKE %s OR email LIKE %s OR phone LIKE %s OR address LIKE %s OR city LIKE %s OR state LIKE %s OR billing_center LIKE %s )';
            array_push($params, $like, $like, $like, $like, $like, $like, $like);
        }

        if ($billingCenter !== '') {
            $where[]  = 'billing_center = %s';
            $params[] = $billingCenter;
        }

        return [$where, $params];
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    public static function getById(int $id): ?object
    {
        global $wpdb;

        if ($id <= 0) {
            return null;
        }

        $table = self::table();
        $sql   = $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id);
        $row   = $wpdb->get_row($sql);

        return $row ?: null;
    }

    public static function getByExternalId(string $externalId): ?object
    {
        global $wpdb;

        $table = self::table();
        $sql   = $wpdb->prepare("SELECT * FROM {$table} WHERE external_id = %s LIMIT 1", $externalId);
        $row   = $wpdb->get_row($sql);

        return $row ?: null;
    }

    public static function count(string $search = '', string $billingCenter = ''): int
    {
        global $wpdb;

        $table = self::table();
        [$where, $params] = self::buildFilters($search, $billingCenter);

        $sql = "SELECT COUNT(*) FROM {$table}";
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        return (int) $wpdb->get_var($sql);
    }

    /**
     * @return object[]
     */
    public static function getPage(int $page, int $perPage, string $search = '', string $billingCenter = ''): array
    {
        global $wpdb;

        $table  = self::table();
        $offset = ($page - 1) * $perPage;
        [$where, $params] = self::buildFilters($search, $billingCenter);

        $sql = "SELECT * FROM {$table}";
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql     .= ' ORDER BY id DESC LIMIT %d OFFSET %d';
        $params[] = $perPage;
        $params[] = $offset;

        return (array) $wpdb->get_results($wpdb->prepare($sql, ...$params));
    }

    /**
     * @return string[]
     */
    public static function getDistinctBillingCenters(): array
    {
        global $wpdb;

        $table = self::table();
        $rows  = $wpdb->get_col(
            "SELECT DISTINCT billing_center
             FROM {$table}
             WHERE billing_center IS NOT NULL
               AND billing_center <> ''
             ORDER BY billing_center ASC"
        );

        return array_values(array_filter(array_map('strval', (array) $rows)));
    }

    /**
     * Resolve company name from either a numeric DB id or an external_id string.
     * Uses a static in-memory cache for the duration of the request.
     */
    public static function getCompanyNameBySelector(string $selector): string
    {
        static $cache = [];

        $selector = trim($selector);
        if ($selector === '') {
            return '';
        }

        if (array_key_exists($selector, $cache)) {
            return $cache[$selector];
        }

        $row = ctype_digit($selector)
            ? self::getById((int) $selector)
            : self::getByExternalId($selector);

        $name = '';
        if ($row && !empty($row->company_name)) {
            $name = trim((string) $row->company_name);
        }

        $cache[$selector] = $name;

        return $name;
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Upsert by external_id.  Used by the CSV importer.
     *
     * @param array<string,mixed> $data
     * @param array{inserted:int,updated:int,skipped:int} $stats  passed by reference
     */
    public static function upsert(array $data, array &$stats, string $source = 'system'): bool
    {
        global $wpdb;

        $table = self::table();

        $companyName     = trim((string) ($data['company_name'] ?? ''));
        $address         = trim((string) ($data['address'] ?? ''));
        $city            = trim((string) ($data['city'] ?? ''));
        $state           = trim((string) ($data['state'] ?? ''));
        $phone           = trim((string) ($data['phone'] ?? ''));
        $email           = preg_replace('/\s+/', '', (string) ($data['email'] ?? ''));
        $email           = is_string($email) ? $email : '';
        $customerSegment = trim((string) ($data['customer_segment'] ?? ''));
        $billingCenter   = trim((string) ($data['billing_center'] ?? ''));
        $updatedAt       = current_time('mysql');

        if ($companyName === '') {
            $stats['skipped']++;
            return false;
        }

        $externalId = trim((string) ($data['external_id'] ?? ''));
        if ($externalId === '') {
            $externalId = 'IMP-' . strtoupper(substr(md5(strtolower($companyName)), 0, 12));
        }

        $existing = self::getByExternalId($externalId);

        if ($existing) {
            $updated = $wpdb->update(
                $table,
                [
                    'company_name'     => $companyName,
                    'address'          => $address,
                    'city'             => $city,
                    'state'            => $state,
                    'phone'            => $phone,
                    'email'            => $email,
                    'customer_segment' => $customerSegment,
                    'billing_center'   => $billingCenter,
                    'updated_at'       => $updatedAt,
                ],
                ['id' => (int) $existing->id],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s'],
                ['%d']
            );

            if ($updated === false) {
                $stats['skipped']++;
                return false;
            }

            $stats['updated']++;

            $current = self::getById((int) $existing->id) ?: $existing;
            do_action('csp_customer_saved', (int) $existing->id, sanitize_key($source), false, $existing, $current);

            return true;
        }

        $result = $wpdb->insert(
            $table,
            [
                'external_id'      => $externalId,
                'company_name'     => $companyName,
                'address'          => $address,
                'city'             => $city,
                'state'            => $state,
                'phone'            => $phone,
                'email'            => $email,
                'customer_segment' => $customerSegment,
                'billing_center'   => $billingCenter,
                'updated_at'       => $updatedAt,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if ($result === false) {
            $stats['skipped']++;
            return false;
        }

        $new_id = (int) $wpdb->insert_id;
        $current = self::getById($new_id);
        do_action('csp_customer_saved', $new_id, sanitize_key($source), true, null, $current);

        $stats['inserted']++;
        return true;
    }

    /**
     * @param int[] $ids
     */
    public static function deleteByIds(array $ids, string $source = 'system'): void
    {
        global $wpdb;

        $ids = array_map('intval', $ids);
        if (empty($ids)) {
            return;
        }

        $table = self::table();
        $source = sanitize_key($source);

        $rows = self::getByIds($ids);
        foreach ($rows as $row) {
            do_action('csp_customer_deleting', (int) $row->id, $source, $row);
        }

        $wpdb->query("DELETE FROM {$table} WHERE id IN (" . implode(',', $ids) . ')');

        foreach ($rows as $row) {
            do_action('csp_customer_deleted', (int) $row->id, $source, $row);
        }

        self::invalidateCache();
    }

    /**
     * @param int[] $ids
     * @return object[]
     */
    private static function getByIds(array $ids): array
    {
        global $wpdb;

        if ($ids === []) {
            return [];
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = "SELECT * FROM " . self::table() . " WHERE id IN ({$placeholders})";
        $prepared = $wpdb->prepare($sql, ...$ids);

        return (array) $wpdb->get_results($prepared);
    }

    // -------------------------------------------------------------------------
    // Cache
    // -------------------------------------------------------------------------

    public static function invalidateCache(): void
    {
        delete_transient('csp_clients_dropdown_choices_v1');
    }
}
