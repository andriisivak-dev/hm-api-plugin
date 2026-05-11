<?php

declare(strict_types=1);

namespace CSP\Admin\Customers;

use CSP\Brevo\CustomerBrevoSyncMetaRepository;
use CSP\Brevo\CustomerSyncService;
use CSP\Brevo\SyncQueueFactory;
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

        if (
            isset($_POST['action'])
            && $_POST['action'] === 'csp-brevo-sync-customer'
            && isset($_POST['customer_id'])
            && isset($_POST['page'])
            && $_POST['page'] === 'csp-customers'
        ) {
            $customerId = (int) $_POST['customer_id'];
            check_admin_referer('csp_brevo_sync_customer_' . $customerId);
            $this->handleManualBrevoSyncAction($customerId);
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
        if (!current_user_can('manage_options')) {
            return;
        }

        $syncCustomerId = isset($_GET['customer_brevo_sync']) ? (int) $_GET['customer_brevo_sync'] : 0;
        if ($syncCustomerId > 0) {
            $this->renderBrevoSyncPage($syncCustomerId);
            return;
        }

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

    private function handleManualBrevoSyncAction(int $customerId): void
    {
        $customer = CustomerRepository::getById($customerId);
        if (!$customer) {
            $this->redirectToBrevoSyncPage($customerId, 'not_found');
        }

        $job = [
            'customer_id' => $customerId,
            'action' => CustomerSyncService::ACTION_UPSERT,
            'source' => 'admin_manual',
        ];

        $queue = SyncQueueFactory::create();

        if ($queue->is_job_queued($job) || $queue->enqueue($job)) {
            $this->redirectToBrevoSyncPage($customerId, 'scheduled');
        }

        $result = (new CustomerSyncService())->sync_upsert($customerId, 'admin_manual');
        if (!empty($result['success'])) {
            $this->redirectToBrevoSyncPage($customerId, 'completed');
        }

        $this->redirectToBrevoSyncPage($customerId, 'failed');
    }

    private function redirectToBrevoSyncPage(int $customerId, string $notice): void
    {
        $url = add_query_arg([
            'page' => 'csp-customers',
            'customer_brevo_sync' => $customerId,
            'brevo_sync_notice' => sanitize_key($notice),
        ], admin_url('admin.php'));

        wp_safe_redirect($url);
        exit;
    }

    private function renderBrevoSyncPage(int $customerId): void
    {
        $customer = CustomerRepository::getById($customerId);
        $backUrl = add_query_arg(['page' => 'csp-customers'], admin_url('admin.php'));
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Brevo Sync Status', 'hm-case-study-api'); ?></h1>
            <?php $this->renderBrevoSyncNotice(); ?>
            <p><a href="<?php echo esc_url($backUrl); ?>">&larr; <?php esc_html_e('Back to Customers', 'hm-case-study-api'); ?></a></p>
            <?php if (!$customer): ?>
                <div class="notice notice-error"><p><?php esc_html_e('Customer not found.', 'hm-case-study-api'); ?></p></div>
            </div>
                <?php return; ?>
            <?php endif; ?>

            <?php
            $syncMeta = (new CustomerBrevoSyncMetaRepository())->get_all_meta($customerId);
            $status = (string) ($syncMeta[CustomerBrevoSyncMetaRepository::META_SYNC_STATUS] ?? '');
            $lastAttempt = (string) ($syncMeta[CustomerBrevoSyncMetaRepository::META_LAST_ATTEMPT_AT] ?? '');
            $lastSuccess = (string) ($syncMeta[CustomerBrevoSyncMetaRepository::META_LAST_SUCCESS_AT] ?? '');
            $lastError = (string) ($syncMeta[CustomerBrevoSyncMetaRepository::META_LAST_ERROR] ?? '');
            $contactId = (string) ($syncMeta[CustomerBrevoSyncMetaRepository::META_CONTACT_ID] ?? '');
            ?>

            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><?php esc_html_e('Customer', 'hm-case-study-api'); ?></th>
                        <td><?php echo esc_html((string) ($customer->company_name ?? '')); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Last Sync Status', 'hm-case-study-api'); ?></th>
                        <td><?php echo esc_html($status !== '' ? $status : '-'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Last Attempt Date', 'hm-case-study-api'); ?></th>
                        <td><?php echo esc_html($lastAttempt !== '' ? $lastAttempt : '-'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Last Success Date', 'hm-case-study-api'); ?></th>
                        <td><?php echo esc_html($lastSuccess !== '' ? $lastSuccess : '-'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Last Safe Error', 'hm-case-study-api'); ?></th>
                        <td><?php echo esc_html($lastError !== '' ? $lastError : '-'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Brevo Contact ID', 'hm-case-study-api'); ?></th>
                        <td><?php echo esc_html($contactId !== '' ? $contactId : '-'); ?></td>
                    </tr>
                </tbody>
            </table>

            <form method="post">
                <input type="hidden" name="page" value="csp-customers" />
                <input type="hidden" name="action" value="csp-brevo-sync-customer" />
                <input type="hidden" name="customer_id" value="<?php echo esc_attr((string) $customerId); ?>" />
                <?php wp_nonce_field('csp_brevo_sync_customer_' . $customerId); ?>
                <?php submit_button(__('Sync this Customer with Brevo', 'hm-case-study-api')); ?>
            </form>
        </div>
        <?php
    }

    private function renderBrevoSyncNotice(): void
    {
        $notice = isset($_GET['brevo_sync_notice']) ? sanitize_key((string) $_GET['brevo_sync_notice']) : '';
        if ($notice === '') {
            return;
        }

        $map = [
            'scheduled' => ['class' => 'notice notice-success', 'text' => __('Customer sync was scheduled.', 'hm-case-study-api')],
            'completed' => ['class' => 'notice notice-success', 'text' => __('Customer sync completed.', 'hm-case-study-api')],
            'failed' => ['class' => 'notice notice-error', 'text' => __('Customer sync failed. Check logs for details.', 'hm-case-study-api')],
            'not_found' => ['class' => 'notice notice-error', 'text' => __('Customer not found.', 'hm-case-study-api')],
        ];

        if (!isset($map[$notice])) {
            return;
        }

        $item = $map[$notice];
        ?>
        <div class="<?php echo esc_attr((string) $item['class']); ?>"><p><?php echo esc_html((string) $item['text']); ?></p></div>
        <?php
    }
}
