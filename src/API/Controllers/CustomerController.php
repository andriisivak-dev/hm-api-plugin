<?php

declare(strict_types=1);

namespace CSP\API\Controllers;

use WP_REST_Request;
use CSP\API\Responses\ApiResponse;
use CSP\API\Responses\ErrorCodes;
use CSP\Brevo\BrevoLogger;
use CSP\Brevo\CustomerChangeDetector;
use CSP\Brevo\CustomerSyncService;
use CSP\Brevo\SyncQueueFactory;
use CSP\Brevo\SyncQueueInterface;
use CSP\Repositories\CustomerRepository;
use CSP\Exceptions\ApiException;

/**
 * REST Controller — Customers
 *
 * Routes (under /wp-json/csp/v1):
 *   GET    /customers                    — paginated list (super-admin only)
 *   POST   /customers                    — create customer (super-admin only)
 *   GET    /customers/stats              — total count (super-admin only)
 *   GET    /customers/industry-segments  — available taxonomy segments (super-admin only)
 *   GET    /customers/{id}               — single record (super-admin only)
 *   PATCH  /customers/{id}               — update customer (super-admin only)
 *   DELETE /customers/{id}               — delete customer (super-admin only)
 *   POST   /customers/{id}/logo          — upload logo file (super-admin only)
 *
 * This is a direct port of Hemant_REST_Customers_Controller from hemant-core,
 * adapted to the CSP plugin architecture (ApiResponse, ApiException, ErrorCodes).
 *
 * @package CSP\API\Controllers
 */
class CustomerController
{
    private const INDUSTRY_SEGMENT_TAXONOMY = 'hm_industry_segment';

    private SyncQueueInterface $sync_queue;
    private CustomerChangeDetector $change_detector;
    private CustomerSyncService $sync_service;
    private BrevoLogger $logger;

    public function __construct(
        ?SyncQueueInterface $sync_queue = null,
        ?CustomerChangeDetector $change_detector = null,
        ?CustomerSyncService $sync_service = null,
        ?BrevoLogger $logger = null
    ) {
        $this->sync_queue = $sync_queue ?? SyncQueueFactory::create();
        $this->change_detector = $change_detector ?? new CustomerChangeDetector();
        $this->sync_service = $sync_service ?? new CustomerSyncService();
        $this->logger = $logger ?? new BrevoLogger();
    }

    // -------------------------------------------------------------------------
    // Permission guard
    // -------------------------------------------------------------------------

    private function requireSuperAdmin(): void
    {
        $user = get_userdata(get_current_user_id());
        if (!$user) {
            throw new ApiException('Unauthorized.', ErrorCodes::UNAUTHORIZED, 401);
        }
        if (!in_array('administrator', (array) $user->roles, true)) {
            throw new ApiException('Forbidden.', ErrorCodes::FORBIDDEN, 403);
        }
    }

    // -------------------------------------------------------------------------
    // GET /customers
    // -------------------------------------------------------------------------

    public function index(WP_REST_Request $request)
    {
        $this->requireSuperAdmin();

        $page          = max(1, (int) $request->get_param('page'));
        $perPage       = max(1, (int) ($request->get_param('per_page') ?: 20));
        $search        = $this->sanitizeSearch((string) ($request->get_param('search') ?? ''));
        $billingCenter = sanitize_text_field((string) ($request->get_param('billing_center') ?? ''));

        $total = CustomerRepository::count($search, $billingCenter);
        $rows  = CustomerRepository::getPage($page, $perPage, $search, $billingCenter);

        $items = array_map(
            fn($row) => $this->prepareItem($row),
            $rows
        );

        $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 1;

        return ApiResponse::success($items, '', [
            'total'       => $total,
            'page'        => $page,
            'per_page'    => $perPage,
            'total_pages' => $totalPages,
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /customers
    // -------------------------------------------------------------------------

    public function create(WP_REST_Request $request)
    {
        global $wpdb;

        $this->requireSuperAdmin();

        $table = CustomerRepository::table();

        $externalId      = sanitize_text_field((string) ($request->get_param('external_id') ?? ''));
        $companyName     = sanitize_text_field((string) ($request->get_param('company_name') ?? ''));
        $address         = sanitize_textarea_field((string) ($request->get_param('address') ?? ''));
        $city            = sanitize_text_field((string) ($request->get_param('city') ?? ''));
        $state           = sanitize_text_field((string) ($request->get_param('state') ?? ''));
        $phone           = sanitize_text_field((string) ($request->get_param('phone') ?? ''));
        $emailRaw        = trim((string) ($request->get_param('email') ?? ''));
        $email           = $emailRaw !== '' ? sanitize_email($emailRaw) : '';
        $customerSegment = $this->normalizeCustomerSegment(
            (string) ($request->get_param('customer_segment') ?? ''),
            (string) ($request->get_param('customer_segment_slug') ?? '')
        );
        $billingCenter   = sanitize_text_field((string) ($request->get_param('billing_center') ?? ''));
        $updatedAt       = current_time('mysql');

        $logoUpload = $this->maybeUploadLogo($request, 'logo');
        if ($logoUpload instanceof \WP_REST_Response) {
            return $logoUpload; // error response
        }
        $logoId = (int) $logoUpload;

        $validation = $this->validateRequiredFields($companyName, $emailRaw);
        if ($validation !== true) {
            return $validation;
        }

        if ($externalId === '') {
            $externalId = $this->generateExternalId();
        } else {
            if (CustomerRepository::getByExternalId($externalId)) {
                return ApiResponse::error(ErrorCodes::CONFLICT, 'A customer with this External ID already exists.', 409);
            }
        }

        $existingByCompany = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$table} WHERE company_name = %s LIMIT 1", $companyName)
        );
        if ($existingByCompany) {
            return ApiResponse::error(ErrorCodes::CONFLICT, 'A customer with this Company Name already exists.', 409);
        }

        $inserted = $wpdb->insert(
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
                'logo_id'          => $logoId > 0 ? $logoId : null,
                'updated_at'       => $updatedAt,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s']
        );

        if ($inserted === false) {
            if ($logoId > 0) {
                wp_delete_attachment($logoId, true);
            }
            return ApiResponse::error(ErrorCodes::INTERNAL_ERROR, 'Failed to create customer.', 500);
        }

        $customer = CustomerRepository::getById((int) $wpdb->insert_id);
        if (!$customer) {
            return ApiResponse::error(ErrorCodes::INTERNAL_ERROR, 'Customer created but failed to load created record.', 500);
        }

        $this->queueCustomerSync($customer, 'rest_create', CustomerSyncService::ACTION_UPSERT);

        return new \WP_REST_Response(
            ['success' => true, 'data' => $this->prepareItem($customer), 'message' => ''],
            201
        );
    }

    // -------------------------------------------------------------------------
    // GET /customers/stats
    // -------------------------------------------------------------------------

    public function stats(WP_REST_Request $request)
    {
        $this->requireSuperAdmin();

        return ApiResponse::success(['total' => CustomerRepository::count()]);
    }

    // -------------------------------------------------------------------------
    // GET /customers/industry-segments
    // -------------------------------------------------------------------------

    public function industrySegments(WP_REST_Request $request)
    {
        $this->requireSuperAdmin();

        $terms = get_terms([
            'taxonomy' => self::INDUSTRY_SEGMENT_TAXONOMY,
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);

        if (is_wp_error($terms)) {
            return ApiResponse::success([]);
        }

        $items = array_map(static function ($term): array {
            return [
                'term_id' => (int) ($term->term_id ?? 0),
                'name' => (string) ($term->name ?? ''),
                'slug' => (string) ($term->slug ?? ''),
                'count' => (int) ($term->count ?? 0),
            ];
        }, (array) $terms);

        return ApiResponse::success($items);
    }

    // -------------------------------------------------------------------------
    // GET /customers/{id}
    // -------------------------------------------------------------------------

    public function show(WP_REST_Request $request)
    {
        $this->requireSuperAdmin();

        $id       = (int) $request->get_param('id');
        $customer = CustomerRepository::getById($id);

        if (!$customer) {
            return ApiResponse::error(ErrorCodes::NOT_FOUND, 'Customer not found.', 404);
        }

        return ApiResponse::success($this->prepareItem($customer));
    }

    // -------------------------------------------------------------------------
    // PATCH /customers/{id}
    // -------------------------------------------------------------------------

    public function update(WP_REST_Request $request)
    {
        global $wpdb;

        $this->requireSuperAdmin();

        $table   = CustomerRepository::table();
        $id      = (int) $request->get_param('id');
        $current = CustomerRepository::getById($id);

        if (!$current) {
            return ApiResponse::error(ErrorCodes::NOT_FOUND, 'Customer not found.', 404);
        }

        $json = $request->get_json_params() ?? [];

        $companyName = array_key_exists('company_name', $json)
            ? sanitize_text_field((string) $json['company_name'])
            : (string) ($current->company_name ?? '');
        $address = array_key_exists('address', $json)
            ? sanitize_textarea_field((string) $json['address'])
            : (string) ($current->address ?? '');
        $city = array_key_exists('city', $json)
            ? sanitize_text_field((string) $json['city'])
            : (string) ($current->city ?? '');
        $state = array_key_exists('state', $json)
            ? sanitize_text_field((string) $json['state'])
            : (string) ($current->state ?? '');
        $phone = array_key_exists('phone', $json)
            ? sanitize_text_field((string) $json['phone'])
            : (string) ($current->phone ?? '');
        $emailRaw = array_key_exists('email', $json)
            ? trim((string) $json['email'])
            : (string) ($current->email ?? '');
        $email = $emailRaw !== '' ? sanitize_email($emailRaw) : '';
        $segmentProvided = array_key_exists('customer_segment', $json)
            || array_key_exists('customer_segment_slug', $json);
        $customerSegment = $segmentProvided
            ? $this->normalizeCustomerSegment(
                (string) ($json['customer_segment'] ?? ''),
                (string) ($json['customer_segment_slug'] ?? '')
            )
            : (string) ($current->customer_segment ?? '');
        $billingCenter = array_key_exists('billing_center', $json)
            ? sanitize_text_field((string) $json['billing_center'])
            : (string) ($current->billing_center ?? '');
        $externalId = array_key_exists('external_id', $json)
            ? sanitize_text_field((string) $json['external_id'])
            : (string) ($current->external_id ?? '');

        $currentLogoId       = isset($current->logo_id) ? (int) $current->logo_id : 0;
        $logoUpdateRequested = array_key_exists('logo_id', $json);
        $logoId              = $logoUpdateRequested ? max(0, (int) $json['logo_id']) : $currentLogoId;

        if ($logoUpdateRequested && $logoId > 0 && $logoId !== $currentLogoId) {
            return ApiResponse::error(
                ErrorCodes::BAD_REQUEST,
                'Logo ID cannot be assigned directly. Upload a file instead.',
                400
            );
        }

        $validation = $this->validateRequiredFields($companyName, $emailRaw);
        if ($validation !== true) {
            return $validation;
        }

        if ($externalId === '') {
            return ApiResponse::error(ErrorCodes::BAD_REQUEST, 'External ID cannot be empty.', 400);
        }

        $existingByCompany = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$table} WHERE company_name = %s AND id <> %d LIMIT 1", $companyName, $id)
        );
        if ($existingByCompany) {
            return ApiResponse::error(ErrorCodes::CONFLICT, 'A customer with this Company Name already exists.', 409);
        }

        $existingByExternalId = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$table} WHERE external_id = %s AND id <> %d LIMIT 1", $externalId, $id)
        );
        if ($existingByExternalId) {
            return ApiResponse::error(ErrorCodes::CONFLICT, 'A customer with this External ID already exists.', 409);
        }

        $updated = $wpdb->update(
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
                'logo_id'          => $logoId > 0 ? $logoId : null,
                'updated_at'       => current_time('mysql'),
            ],
            ['id' => $id],
            ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s'],
            ['%d']
        );

        if ($updated === false) {
            return ApiResponse::error(ErrorCodes::INTERNAL_ERROR, 'Failed to update customer.', 500);
        }

        if ($logoUpdateRequested && $logoId === 0 && $currentLogoId > 0) {
            wp_delete_attachment($currentLogoId, true);
        }

        $customer = CustomerRepository::getById($id);
        if (!$customer) {
            return ApiResponse::error(ErrorCodes::INTERNAL_ERROR, 'Customer updated but failed to load updated record.', 500);
        }

        if ($this->change_detector->should_sync_on_update($id, $customer)) {
            $this->queueCustomerSync($customer, 'rest_update', CustomerSyncService::ACTION_UPSERT);
        }

        return ApiResponse::success($this->prepareItem($customer));
    }

    // -------------------------------------------------------------------------
    // DELETE /customers/{id}
    // -------------------------------------------------------------------------

    public function delete(WP_REST_Request $request)
    {
        $this->requireSuperAdmin();

        $id       = (int) $request->get_param('id');
        $customer = CustomerRepository::getById($id);

        if (!$customer) {
            return ApiResponse::error(ErrorCodes::NOT_FOUND, 'Customer not found.', 404);
        }

        $this->queueCustomerSync($customer, 'rest_delete', CustomerSyncService::ACTION_SOFT_DELETE);

        $previous = $this->prepareItem($customer);

        CustomerRepository::deleteByIds([$id], 'rest_delete');

        if (CustomerRepository::getById($id)) {
            return ApiResponse::error(ErrorCodes::INTERNAL_ERROR, 'Failed to delete customer.', 500);
        }

        return ApiResponse::success([
            'deleted'  => true,
            'id'       => $id,
            'previous' => $previous,
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /customers/{id}/logo
    // -------------------------------------------------------------------------

    public function uploadLogo(WP_REST_Request $request)
    {
        global $wpdb;

        $this->requireSuperAdmin();

        $id      = (int) $request->get_param('id');
        $table   = CustomerRepository::table();
        $current = CustomerRepository::getById($id);

        if (!$current) {
            return ApiResponse::error(ErrorCodes::NOT_FOUND, 'Customer not found.', 404);
        }

        $logoUpload = $this->maybeUploadLogo($request, 'logo');
        if ($logoUpload instanceof \WP_REST_Response) {
            return $logoUpload;
        }

        $newLogoId = (int) $logoUpload;
        if ($newLogoId <= 0) {
            return ApiResponse::error(ErrorCodes::BAD_REQUEST, 'Please select a logo file.', 400);
        }

        $updated = $wpdb->update(
            $table,
            [
                'logo_id'    => $newLogoId,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $id],
            ['%d', '%s'],
            ['%d']
        );

        if ($updated === false) {
            wp_delete_attachment($newLogoId, true);
            return ApiResponse::error(ErrorCodes::INTERNAL_ERROR, 'Failed to update customer logo.', 500);
        }

        $previousLogoId = isset($current->logo_id) ? (int) $current->logo_id : 0;
        if ($previousLogoId > 0 && $previousLogoId !== $newLogoId) {
            wp_delete_attachment($previousLogoId, true);
        }

        $customer = CustomerRepository::getById($id);
        if (!$customer) {
            return ApiResponse::error(ErrorCodes::INTERNAL_ERROR, 'Customer logo updated but failed to load updated record.', 500);
        }

        return ApiResponse::success($this->prepareItem($customer));
    }

    // -------------------------------------------------------------------------
    // DTO
    // -------------------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private function prepareItem(object $item): array
    {
        $customerSegment = (string) ($item->customer_segment ?? '');

        return [
            'id'               => (int) ($item->id ?? 0),
            'external_id'      => (string) ($item->external_id ?? ''),
            'company_name'     => (string) ($item->company_name ?? ''),
            'email'            => (string) ($item->email ?? ''),
            'phone'            => (string) ($item->phone ?? ''),
            'address'          => (string) ($item->address ?? ''),
            'city'             => (string) ($item->city ?? ''),
            'state'            => (string) ($item->state ?? ''),
            'billing_center'   => (string) ($item->billing_center ?? ''),
            'customer_segment' => $customerSegment,
            'customer_segment_slug' => $this->resolveCustomerSegmentSlug($customerSegment),
            'logo_id'          => (int) ($item->logo_id ?? 0),
            'logo_url'         => $this->resolveLogoUrl($item->logo_id ?? 0),
            'updated_at'       => (string) ($item->updated_at ?? ''),
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function generateExternalId(): string
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidate = sprintf('CUST-%s-%04d', gmdate('YmdHis'), wp_rand(0, 9999));
            if (!CustomerRepository::getByExternalId($candidate)) {
                return $candidate;
            }
        }
        return 'CUST-' . wp_generate_uuid4();
    }

    /**
     * @return \WP_REST_Response|int  Error response or attachment ID (0 = no file)
     */
    private function maybeUploadLogo(WP_REST_Request $request, string $fieldName)
    {
        $files = $request->get_file_params();
        $file  = $files[$fieldName] ?? null;

        if (!$file || empty($file['tmp_name'])) {
            return 0;
        }

        if (!empty($file['error']) && UPLOAD_ERR_OK !== (int) $file['error']) {
            return ApiResponse::error(ErrorCodes::BAD_REQUEST, 'Logo upload failed.', 400);
        }

        $maxSize = 5 * 1024 * 1024;
        if (!empty($file['size']) && (int) $file['size'] > $maxSize) {
            return ApiResponse::error(ErrorCodes::BAD_REQUEST, 'Logo must be 5MB or less.', 400);
        }

        $allowedMimes = [
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png'          => 'image/png',
            'webp'         => 'image/webp',
            'svg'          => 'image/svg+xml',
        ];

        $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], $allowedMimes);
        if (empty($check['ext']) || empty($check['type'])) {
            return ApiResponse::error(ErrorCodes::BAD_REQUEST, 'Only SVG, PNG, JPG, JPEG, or WEBP files are allowed.', 400);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $uploaded = wp_handle_upload($file, ['test_form' => false, 'mimes' => $allowedMimes]);

        if (!empty($uploaded['error'])) {
            return ApiResponse::error(ErrorCodes::BAD_REQUEST, (string) $uploaded['error'], 400);
        }

        $attachmentId = wp_insert_attachment(
            [
                'post_mime_type' => $uploaded['type'],
                'post_title'     => sanitize_file_name(pathinfo((string) $file['name'], PATHINFO_FILENAME)),
                'post_content'   => '',
                'post_status'    => 'inherit',
                'post_author'    => (int) get_current_user_id(),
            ],
            $uploaded['file']
        );

        if (is_wp_error($attachmentId) || !$attachmentId) {
            return ApiResponse::error(ErrorCodes::INTERNAL_ERROR, 'Could not save logo.', 500);
        }

        $metadata = wp_generate_attachment_metadata($attachmentId, $uploaded['file']);
        if (!empty($metadata)) {
            wp_update_attachment_metadata($attachmentId, $metadata);
        }

        return (int) $attachmentId;
    }

    private function resolveLogoUrl(mixed $logoId): string
    {
        $logoId = (int) $logoId;
        if ($logoId <= 0) {
            return '';
        }
        $url = wp_get_attachment_url($logoId);
        return is_string($url) ? $url : '';
    }

    private function normalizeCustomerSegment(string $segmentInput, string $slugInput = ''): string
    {
        $segment = sanitize_text_field($segmentInput);
        $slug = sanitize_title($slugInput);

        if ($segment === '' && $slug === '') {
            return '';
        }

        if ($slug !== '') {
            $termByExplicitSlug = get_term_by('slug', $slug, self::INDUSTRY_SEGMENT_TAXONOMY);
            if ($termByExplicitSlug instanceof \WP_Term) {
                return (string) $termByExplicitSlug->name;
            }
        }

        if ($segment !== '') {
            $segmentAsSlug = sanitize_title($segment);
            $termBySegmentSlug = get_term_by('slug', $segmentAsSlug, self::INDUSTRY_SEGMENT_TAXONOMY);
            if ($termBySegmentSlug instanceof \WP_Term) {
                return (string) $termBySegmentSlug->name;
            }

            $termByName = get_term_by('name', $segment, self::INDUSTRY_SEGMENT_TAXONOMY);
            if ($termByName instanceof \WP_Term) {
                return (string) $termByName->name;
            }

            return $segment;
        }

        return $slug;
    }

    private function resolveCustomerSegmentSlug(string $customerSegment): string
    {
        $segment = sanitize_text_field($customerSegment);
        if ($segment === '') {
            return '';
        }

        $termBySlug = get_term_by('slug', sanitize_title($segment), self::INDUSTRY_SEGMENT_TAXONOMY);
        if ($termBySlug instanceof \WP_Term) {
            return (string) $termBySlug->slug;
        }

        $termByName = get_term_by('name', $segment, self::INDUSTRY_SEGMENT_TAXONOMY);
        if ($termByName instanceof \WP_Term) {
            return (string) $termByName->slug;
        }

        return '';
    }

    private function sanitizeSearch(string $search): string
    {
        return sanitize_text_field(wp_unslash($search));
    }

    private function queueCustomerSync(object $customer, string $source, string $action): void
    {
        $source = sanitize_key($source);
        $customerId = (int) ($customer->id ?? 0);

        if ($customerId <= 0) {
            return;
        }

        $job = [
            'customer_id' => $customerId,
            'action' => sanitize_key($action),
            'source' => $source,
        ];

        if ($action === CustomerSyncService::ACTION_SOFT_DELETE) {
            $job['customer_snapshot'] = get_object_vars($customer);
        }

        try {
            if ($this->sync_queue->is_job_queued($job)) {
                return;
            }

            if ($this->sync_queue->enqueue($job)) {
                return;
            }
        } catch (\Throwable $exception) {
            $this->logger->warning('brevo_rest_sync_enqueue_exception', [
                'customer_id' => $customerId,
                'source' => $source,
                'action' => $action,
                'error_type' => get_class($exception),
                'error_message' => $exception->getMessage(),
            ]);
        }

        $result = $action === CustomerSyncService::ACTION_SOFT_DELETE
            ? $this->sync_service->sync_customer_snapshot(
                get_object_vars($customer),
                $source,
                CustomerSyncService::ACTION_SOFT_DELETE,
                $customerId
            )
            : $this->sync_service->sync_customer($customerId, $source, CustomerSyncService::ACTION_UPSERT);

        if (!($result['success'] ?? false)) {
            $this->logger->warning('brevo_rest_sync_fallback_failed', [
                'customer_id' => $customerId,
                'source' => $source,
                'action' => $action,
                'error' => (string) ($result['error'] ?? ''),
            ]);
        }
    }

    /**
     * @return true|\WP_REST_Response
     */
    private function validateRequiredFields(string $companyName, string $emailRaw)
    {
        if ($companyName === '') {
            return ApiResponse::error(ErrorCodes::VALIDATION_ERROR, 'Company name is required.', 400);
        }
        if ($emailRaw !== '' && !is_email($emailRaw)) {
            return ApiResponse::error(ErrorCodes::VALIDATION_ERROR, 'Invalid email address.', 400);
        }
        return true;
    }
}
