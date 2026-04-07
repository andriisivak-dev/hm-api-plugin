<?php

declare(strict_types=1);

namespace CSP\Admin\Customers;

use CSP\Repositories\CustomerRepository;

/**
 * Imports customers from a CSV file (local path or Google Sheets URL).
 *
 * Port of CSP_Clients_Importer from hemant-core plugin.
 *
 * @package CSP\Admin\Customers
 */
class CustomerImporter
{
    /**
     * Resolve a URL/path to a local temp file path, downloading if necessary.
     */
    public static function prepareSource(string $source): string
    {
        // Force Google Sheets CSV export URL
        if (str_contains($source, 'docs.google.com/spreadsheets')) {
            if (preg_match('~/d/([^/]+)~', $source, $m)) {
                $sheetId = $m[1];
            } else {
                return '';
            }

            $gid = '0';
            if (preg_match('/gid=([0-9]+)/', $source, $m)) {
                $gid = $m[1];
            }

            $source = sprintf(
                'https://docs.google.com/spreadsheets/d/%s/export?format=csv&gid=%s',
                $sheetId,
                $gid
            );
        }

        $tmp = wp_tempnam('customers.csv');

        wp_remote_get($source, [
            'timeout'  => 120,
            'stream'   => true,
            'filename' => $tmp,
        ]);

        return $tmp;
    }

    /**
     * Process a chunk of rows from the CSV file.
     *
     * @param string $filepath  Local path to the CSV file
     * @param int    $offset    Number of data rows to skip
     * @param int    $limit     Maximum rows to process in this call
     * @return array{done:bool,processed:int,stats:array{inserted:int,updated:int,skipped:int}}|array{error:string}
     */
    public static function importChunk(string $filepath, int $offset, int $limit): array
    {
        $handle = fopen($filepath, 'rb');
        if (!$handle) {
            return ['error' => 'Cannot open file'];
        }

        $header    = fgetcsv($handle);
        $headerMap = self::buildHeaderMap(is_array($header) ? $header : []);

        $current   = 0;
        $processed = 0;
        $eof       = false;
        $stats     = ['inserted' => 0, 'updated' => 0, 'skipped' => 0];

        while (true) {
            $row = fgetcsv($handle);

            if ($row === false) {
                $eof = true;
                break;
            }

            if ($current < $offset) {
                $current++;
                continue;
            }

            if ($processed >= $limit) {
                break;
            }

            $data = self::rowToData($row, $headerMap);

            if ('' === $data['company_name']) {
                $stats['skipped']++;
            } else {
                CustomerRepository::upsert($data, $stats);
            }

            $current++;
            $processed++;
        }

        fclose($handle);

        return [
            'done'      => $eof,
            'processed' => $processed,
            'stats'     => $stats,
        ];
    }

    // -------------------------------------------------------------------------
    // Internal CSV parsing helpers
    // -------------------------------------------------------------------------

    /**
     * @param string[] $header
     * @return array<string,int>
     */
    private static function buildHeaderMap(array $header): array
    {
        $map = [];
        foreach ($header as $index => $columnName) {
            $key = self::normalizeHeaderKey((string) $columnName);
            if ($key !== '' && !array_key_exists($key, $map)) {
                $map[$key] = (int) $index;
            }
        }
        return $map;
    }

    private static function normalizeHeaderKey(string $header): string
    {
        $key = strtolower(trim($header));
        $key = str_replace([' ', '-'], '_', $key);
        $key = preg_replace('/[^a-z0-9_]/', '', $key);
        return is_string($key) ? trim($key, '_') : '';
    }

    /**
     * @param array<int,string> $row
     * @param array<string,int> $headerMap
     * @return array<string,string>
     */
    private static function rowToData(array $row, array $headerMap): array
    {
        return [
            'external_id'      => self::readColumn($row, $headerMap, ['external_id', 'externalid', 'client_id', 'id'], 0),
            'company_name'     => self::readColumn($row, $headerMap, ['company_name', 'company', 'client_name', 'name'], 1),
            'address'          => self::readColumn($row, $headerMap, ['address', 'location'], 2),
            'phone'            => self::readColumn($row, $headerMap, ['phone', 'phone_number'], 3),
            'email'            => self::readColumn($row, $headerMap, ['email', 'email_address'], 4),
            'customer_segment' => self::readColumn($row, $headerMap, ['customer_segment', 'segment'], 5),
            'city'             => self::readColumn($row, $headerMap, ['city'], 6),
            'state'            => self::readColumn($row, $headerMap, ['state'], 7),
            'billing_center'   => self::readColumn($row, $headerMap, ['billing_center', 'billingcentre', 'billing'], 8),
        ];
    }

    /**
     * @param array<int,string>  $row
     * @param array<string,int>  $headerMap
     * @param string[]           $aliases
     */
    private static function readColumn(array $row, array $headerMap, array $aliases, int $fallbackIndex): string
    {
        foreach ($aliases as $name) {
            if (array_key_exists($name, $headerMap)) {
                return trim((string) ($row[$headerMap[$name]] ?? ''));
            }
        }

        if (!empty($headerMap)) {
            return '';
        }

        return trim((string) ($row[$fallbackIndex] ?? ''));
    }
}
