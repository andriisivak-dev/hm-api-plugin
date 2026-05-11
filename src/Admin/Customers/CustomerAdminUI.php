<?php

declare(strict_types=1);

namespace CSP\Admin\Customers;

use CSP\Repositories\CustomerRepository;

/**
 * WordPress admin UI for Customers: list table + CSV importer page.
 *
 * Port of CSP_Clients_Admin_UI, CSP_Clients_Table, clients-actions.php
 * and ajax-import.php from hemant-core plugin.
 *
 * Registered sub-menu pages live under "hm_case" CPT in WP Admin.
 *
 * @package CSP\Admin\Customers
 */
class CustomerAdminUI
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPages']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_init', [$this, 'handleActions']);
        add_action('wp_ajax_csp_customers_import_chunk', [$this, 'ajaxImportChunk']);
    }

    // -------------------------------------------------------------------------
    // Admin Menu
    // -------------------------------------------------------------------------

    public function addMenuPages(): void
    {
        add_submenu_page(
            'edit.php?post_type=hm_case',
            __('Customers Import', 'hm-case-study-api'),
            __('Customers Import', 'hm-case-study-api'),
            'manage_options',
            'csp-customers-import',
            [$this, 'renderImportPage']
        );

        add_submenu_page(
            'edit.php?post_type=hm_case',
            __('Customers', 'hm-case-study-api'),
            __('Customers', 'hm-case-study-api'),
            'manage_options',
            'csp-customers',
            [$this, 'renderListPage']
        );
    }

    // -------------------------------------------------------------------------
    // Asset enqueue
    // -------------------------------------------------------------------------

    public function enqueueAssets(): void
    {
        wp_enqueue_script(
            'csp-customers-import',
            plugins_url('assets/customers-import.js', HM_CASE_STUDY_API_FILE),
            ['jquery'],
            '1.0',
            true
        );

        wp_localize_script('csp-customers-import', 'CSPImport', [
            'ajax'  => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('csp_customers_import'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Bulk actions (single delete / bulk delete from list table)
    // -------------------------------------------------------------------------

    public function handleActions(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Single delete
        if (
            isset($_GET['action'], $_GET['id'])
            && $_GET['action'] === 'delete'
            && isset($_GET['page'])
            && $_GET['page'] === 'csp-customers'
        ) {
            $id = (int) $_GET['id'];
            check_admin_referer('csp_delete_customer_' . $id);
            CustomerRepository::deleteByIds([$id], 'admin');
            wp_redirect(remove_query_arg(['action', 'id', '_wpnonce']));
            exit;
        }

        // Bulk delete
        if (
            isset($_POST['action'])
            && $_POST['action'] === 'bulk-delete'
            && isset($_POST['page'])
            && $_POST['page'] === 'csp-customers'
        ) {
            $ids = array_map('intval', (array) ($_POST['customer_ids'] ?? []));
            if ($ids) {
                CustomerRepository::deleteByIds($ids, 'admin_bulk');
            }
        }
    }

    // -------------------------------------------------------------------------
    // AJAX: chunked CSV import
    // -------------------------------------------------------------------------

    public function ajaxImportChunk(): void
    {
        check_ajax_referer('csp_customers_import', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden.'], 403);
        }

        $source = sanitize_text_field($_POST['file'] ?? '');
        $file   = sanitize_text_field($_POST['local_file'] ?? '');
        $offset = (int) ($_POST['offset'] ?? 0);
        $limit  = 200;

        if (0 === $offset && '' === $file) {
            $file = CustomerImporter::prepareSource($source);
        }

        $result                = CustomerImporter::importChunk($file, $offset, $limit);
        $result['local_file']  = $file;

        wp_send_json_success($result);
    }

    // -------------------------------------------------------------------------
    // Pages
    // -------------------------------------------------------------------------

    public function renderImportPage(): void
    {
        ?>
        <div class="wrap">
            <h1>Customers Import</h1>
            <input type="url" id="csp_google_url" placeholder="Google Sheets CSV URL" class="regular-text" />
            <button class="button button-primary" id="csp-start-import">Import</button>
            <pre id="csp-log" style="margin-top:20px; max-height:400px; overflow:auto; background:#111; color:#0f0; padding:10px;"></pre>
        </div>
        <?php
    }

    public function renderListPage(): void
    {
        $table = new CustomerListTable();
        $table->prepare_items();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Customers', 'hm-case-study-api'); ?></h1>
            <form method="post">
                <input type="hidden" name="page" value="csp-customers" />
                <?php
                $table->search_box(__('Search', 'hm-case-study-api'), 'csp-customers');
                $table->display();
                ?>
            </form>
        </div>
        <?php
    }
}
