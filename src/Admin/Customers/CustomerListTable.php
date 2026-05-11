<?php

declare(strict_types=1);

namespace CSP\Admin\Customers;

use CSP\Repositories\CustomerRepository;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WP_List_Table implementation for the Customers admin list.
 *
 * Port of CSP_Clients_Table from hemant-core plugin.
 *
 * @package CSP\Admin\Customers
 */
class CustomerListTable extends \WP_List_Table
{
    public function get_columns(): array
    {
        return [
            'cb'               => '<input type="checkbox" />',
            'external_id'      => __('ID', 'hm-case-study-api'),
            'company_name'     => __('Company', 'hm-case-study-api'),
            'email'            => __('Email', 'hm-case-study-api'),
            'phone'            => __('Phone', 'hm-case-study-api'),
            'customer_segment' => __('Segment', 'hm-case-study-api'),
            'billing_center'   => __('Billing Center', 'hm-case-study-api'),
        ];
    }

    protected function column_cb($item): string
    {
        return sprintf('<input type="checkbox" name="customer_ids[]" value="%d" />', (int) $item->id);
    }

    /**
     * @param object $item
     */
    protected function column_company_name($item): string
    {
        $deleteUrl = wp_nonce_url(
            add_query_arg([
                'action' => 'delete',
                'id'     => $item->id,
            ]),
            'csp_delete_customer_' . $item->id
        );

        $brevoSyncUrl = add_query_arg([
            'page' => 'csp-customers',
            'customer_brevo_sync' => (int) $item->id,
        ], admin_url('admin.php'));

        $actions = [
            'brevo_sync' => sprintf(
                '<a href="%s">%s</a>',
                esc_url($brevoSyncUrl),
                __('Brevo Sync', 'hm-case-study-api')
            ),
            'delete' => sprintf(
                '<a href="%s" style="color:red;">%s</a>',
                esc_url($deleteUrl),
                __('Delete', 'hm-case-study-api')
            ),
        ];

        return sprintf(
            '<strong>%s</strong> %s',
            esc_html($item->company_name),
            $this->row_actions($actions)
        );
    }

    /**
     * @param object $item
     */
    protected function column_default($item, $column_name): string
    {
        return esc_html((string) ($item->$column_name ?? ''));
    }

    protected function get_bulk_actions(): array
    {
        return [
            'bulk-delete' => __('Delete', 'hm-case-study-api'),
        ];
    }

    public function prepare_items(): void
    {
        $this->process_bulk_action();

        $columns  = $this->get_columns();
        $hidden   = [];
        $sortable = [];

        $this->_column_headers = [$columns, $hidden, $sortable];

        $perPage     = 50;
        $currentPage = $this->get_pagenum();

        $search = isset($_REQUEST['s'])
            ? sanitize_text_field((string) $_REQUEST['s'])
            : '';

        $totalItems = CustomerRepository::count($search);
        $items      = CustomerRepository::getPage($currentPage, $perPage, $search);

        $this->items = $items;

        $this->set_pagination_args([
            'total_items' => $totalItems,
            'per_page'    => $perPage,
        ]);
    }
}
