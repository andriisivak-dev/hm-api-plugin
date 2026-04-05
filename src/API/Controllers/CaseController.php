<?php

declare(strict_types=1);

namespace CSP\API\Controllers;

use WP_REST_Request;
use CSP\API\Responses\ApiResponse;
use CSP\API\Responses\ErrorCodes;
use CSP\Services\CaseService;
use CSP\Services\CaseFormDataService;
use CSP\Services\CaseStatusService;
use CSP\Services\CasePermissionService;
use CSP\Repositories\CaseRepository;
use CSP\DTO\DTOMapper;

class CaseController
{
    private CaseService $caseService;
    private CaseFormDataService $formDataService;
    private CaseStatusService $statusService;
    private CasePermissionService $permissionService;
    private CaseRepository $caseRepo;
    private DTOMapper $dtoMapper;

    public function __construct(
        CaseService $caseService,
        CaseFormDataService $formDataService,
        CaseStatusService $statusService,
        CasePermissionService $permissionService,
        CaseRepository $caseRepo,
        DTOMapper $dtoMapper
    ) {
        $this->caseService = $caseService;
        $this->formDataService = $formDataService;
        $this->statusService = $statusService;
        $this->permissionService = $permissionService;
        $this->caseRepo = $caseRepo;
        $this->dtoMapper = $dtoMapper;
    }

    public function index(WP_REST_Request $request)
    {
        $current_user_id = get_current_user_id();
        $args = $request->get_params();

        // 1. Enforce scope based on role
        $user = get_userdata($current_user_id);
        if (!$user) {
            return ApiResponse::error(ErrorCodes::UNAUTHORIZED, __('Unauthorized', 'csp'), 401);
        }

        $is_admin = in_array('administrator', $user->roles) || in_array('hm_administrator', $user->roles);
        $is_manager = in_array('hm_manager', $user->roles);
        $is_marketing = in_array('hm_marketing', $user->roles);

        if (!$is_admin && !$is_marketing) {
            if ($is_manager) {
                $agent_ids_raw = get_user_meta($current_user_id, '_assigned_agent_ids', true);
                $agent_ids = is_array($agent_ids_raw) ? $agent_ids_raw : (!empty($agent_ids_raw) ? json_decode((string)$agent_ids_raw, true) : []);
                $agent_ids = is_array($agent_ids) ? $agent_ids : [];
                $agent_ids[] = $current_user_id; // Include own cases
                $args['author__in'] = $agent_ids;
            } else {
                // Field Agent sees only own
                $args['author__in'] = [$current_user_id];
            }
        }

        $result = $this->caseRepo->getCases($args);

        // Map array of IDs to Case objects
        // Ideally we use a DTO here (Phase 7). For now, simple hydration via getCase.
        $cases = [];
        foreach ($result['cases'] as $case_id) {
            $raw_case = $this->caseService->getCase((int)$case_id);
            if ($raw_case) {
                $cases[] = $this->dtoMapper->toCaseListItem((int)$case_id, $raw_case);
            }
        }

        return ApiResponse::success($cases, '', [
            'total'       => $result['total'],
            'total_pages' => $result['total_pages'],
            'page'        => $result['page'],
            'per_page'    => $result['per_page'],
        ]);
    }

    public function library(WP_REST_Request $request)
    {
        // 1. Enforce login check via generic middleware (done in Router / Router callback).
        // For the library, we do not restrict by role or authorship; all logged-in users 
        // can view cases that are 'approved'.
        
        $args = $request->get_params();

        // Only approved cases are allowed in the library
        $args['status'] = 'approved';

        $result = $this->caseRepo->getCases($args);

        $cases = [];
        foreach ($result['cases'] as $case_id) {
            $raw_case = $this->caseService->getCase((int)$case_id);
            if ($raw_case) {
                // Double check it is approved due to legacy statuses handling
                if ($raw_case['status'] === 'approved' || $raw_case['status'] === 'complete') {
                    $cases[] = $this->dtoMapper->toCaseListItem((int)$case_id, $raw_case);
                }
            }
        }

        return ApiResponse::success($cases, '', [
            'total'       => count($cases), // Fallback if custom filtering reduces count
            'total_pages' => $result['total_pages'],
            'page'        => $result['page'],
            'per_page'    => $result['per_page'],
        ]);
    }

    public function create(WP_REST_Request $request)
    {
        $form_id = (int) $request->get_param('form_id');
        $total_steps = (int) $request->get_param('total_steps'); // Ideally fetched from Schema locally, but passing it works

        if (!$form_id) {
            return ApiResponse::error(ErrorCodes::VALIDATION_ERROR, __('form_id is required', 'csp'), 400);
        }
        
        // Default total_steps fallback
        if (!$total_steps) {
            $total_steps = 1;
        }

        $result = $this->caseService->createDraftCase($form_id, $total_steps);

        if (is_wp_error($result)) {
            return ApiResponse::error(
                $result->get_error_code(),
                $result->get_error_message(),
                $result->get_error_data()['status'] ?? 400
            );
        }

        return ApiResponse::success($this->caseService->getCase((int)$result), __('Case created successfully', 'csp'), [], 201);
    }

    public function show(WP_REST_Request $request)
    {
        $case_id = (int) $request->get_param('id');
        $current_user_id = get_current_user_id();

        if (!$this->permissionService->canView($case_id, $current_user_id)) {
            return ApiResponse::error(ErrorCodes::FORBIDDEN, __('You do not have permission to view this case', 'csp'), 403);
        }

        $case = $this->caseService->getCase($case_id);
        if (!$case) {
            return ApiResponse::error(ErrorCodes::NOT_FOUND, __('Case not found', 'csp'), 404);
        }

        $permissions = $this->permissionService->getPermissions($case_id, $current_user_id);
        $dto = $this->dtoMapper->toCaseDetail($case_id, $case, $permissions);

        return ApiResponse::success($dto);
    }

    public function getFormData(WP_REST_Request $request)
    {
        $case_id = (int) $request->get_param('id');
        $current_user_id = get_current_user_id();

        if (!$this->permissionService->canView($case_id, $current_user_id)) {
            return ApiResponse::error(ErrorCodes::FORBIDDEN, __('Forbidden', 'csp'), 403);
        }

        $result = $this->formDataService->getFormData($case_id);

        if (is_wp_error($result)) {
            return ApiResponse::error($result->get_error_code(), $result->get_error_message(), 404);
        }

        return ApiResponse::success($result);
    }

    public function updateFormData(WP_REST_Request $request)
    {
        $case_id = (int) $request->get_param('id');
        $current_user_id = get_current_user_id();

        if (!$this->permissionService->canEdit($case_id, $current_user_id)) {
            return ApiResponse::error(ErrorCodes::FORBIDDEN, __('Forbidden', 'csp'), 403);
        }

        $fields = $request->get_param('fields') ?? [];
        $current_step = (int) $request->get_param('current_step');

        if (empty($fields)) {
            return ApiResponse::error(ErrorCodes::VALIDATION_ERROR, __('fields object is required', 'csp'), 400);
        }

        $result = $this->formDataService->saveFormData($case_id, $fields, $current_step);

        if (is_wp_error($result)) {
            return ApiResponse::error($result->get_error_code(), $result->get_error_message(), 400);
        }

        return ApiResponse::success($result);
    }

    public function submit(WP_REST_Request $request)
    {
        return $this->handleStatusAction($request, 'submit');
    }

    public function approve(WP_REST_Request $request)
    {
        return $this->handleStatusAction($request, 'approve');
    }

    public function reject(WP_REST_Request $request)
    {
        return $this->handleStatusAction($request, 'reject', true);
    }

    public function returnForRevision(WP_REST_Request $request)
    {
        return $this->handleStatusAction($request, 'return', true);
    }

    public function overrideStatus(WP_REST_Request $request)
    {
        $case_id = (int) $request->get_param('id');
        $status = sanitize_text_field($request->get_param('status') ?? '');
        $message = sanitize_text_field($request->get_param('message') ?? '');

        if (!$status) {
            return ApiResponse::error(ErrorCodes::VALIDATION_ERROR, __('Status is required', 'csp'), 400);
        }

        $result = $this->statusService->override($case_id, get_current_user_id(), $status, empty($message) ? null : $message);

        if (is_wp_error($result)) {
            return ApiResponse::error($result->get_error_code(), $result->get_error_message(), 403);
        }

        return ApiResponse::success($result);
    }

    public function delete(WP_REST_Request $request)
    {
        $case_id = (int) $request->get_param('id');
        $current_user_id = get_current_user_id();

        if (!$this->permissionService->canDelete($case_id, $current_user_id)) {
            return ApiResponse::error(ErrorCodes::FORBIDDEN, __('Forbidden', 'csp'), 403);
        }

        $success = wp_trash_post($case_id);
        if (!$success) {
            return ApiResponse::error(ErrorCodes::INTERNAL_ERROR, __('Failed to delete case', 'csp'), 500);
        }

        return ApiResponse::success(null, __('Case successfully soft-deleted', 'csp'));
    }

    private function handleStatusAction(WP_REST_Request $request, string $action, bool $requiresMessage = false)
    {
        $case_id = (int) $request->get_param('id');
        $current_user_id = get_current_user_id();
        $message = sanitize_text_field($request->get_param('message') ?? '');

        if ($requiresMessage && empty($message)) {
            return ApiResponse::error(ErrorCodes::VALIDATION_ERROR, __('message is required for this action', 'csp'), 400);
        }

        if ($action === 'submit') {
            $result = $this->statusService->submit($case_id, $current_user_id);
        } elseif ($action === 'approve') {
            $result = $this->statusService->approve($case_id, $current_user_id);
        } elseif ($action === 'reject') {
            $result = $this->statusService->reject($case_id, $current_user_id, $message);
        } elseif ($action === 'return') {
            $result = $this->statusService->return($case_id, $current_user_id, $message);
        }

        if (is_wp_error($result)) {
            return ApiResponse::error(
                $result->get_error_code(),
                $result->get_error_message(),
                $result->get_error_data()['status'] ?? 400
            );
        }

        return ApiResponse::success($result);
    }

    public function getActivities(WP_REST_Request $request)
    {
        $current_user = wp_get_current_user();
        if (!$current_user || !$current_user->ID) {
            return ApiResponse::error(ErrorCodes::UNAUTHORIZED, __('Unauthorized', 'csp'), 401);
        }

        $roles = (array) $current_user->roles;

        $is_admin = in_array('administrator', $roles, true) || in_array('hm_administrator', $roles, true);
        $is_marketing = in_array('hm_marketing', $roles, true) || in_array('marketing', $roles, true);
        $is_manager = in_array('hm_manager', $roles, true) || in_array('manager', $roles, true) || in_array('supervisor', $roles, true);

        // field_agent has no activities, super admin & marketing & managers can see
        $can_view = $is_admin || $is_marketing || $is_manager;
        if (!$can_view) {
            return ApiResponse::error(ErrorCodes::FORBIDDEN, __('Forbidden', 'csp'), 403);
        }

        $stats = [
            'pending_review' => 0,
            'returned' => 0,
            'approved' => 0,
            'rejected' => 0,
            'draft' => 0,
        ];

        $current_user_id = $current_user->ID;
        $cache_key = 'csp_case_activities_stats_' . $current_user_id;
        $cached = wp_cache_get($cache_key, 'csp_cases');
        if (is_array($cached)) {
            return ApiResponse::success(wp_parse_args($cached, $stats));
        }

        $query_args = [
            'posts_per_page' => -1,
            'post_status' => ['publish', 'draft', 'in_review', 'returned', 'approved', 'rejected'],
        ];

        if (!$is_admin && !$is_marketing) {
            if ($is_manager) {
                $agent_ids_raw = get_user_meta($current_user_id, '_assigned_agent_ids', true);
                $agent_ids = is_array($agent_ids_raw) ? $agent_ids_raw : (!empty($agent_ids_raw) ? json_decode((string)$agent_ids_raw, true) : []);
                $agent_ids = is_array($agent_ids) ? $agent_ids : [];
                $agent_ids[] = $current_user_id;
                $query_args['author__in'] = $agent_ids;
            } else {
                $query_args['author__in'] = [$current_user_id];
            }
        }

        $result = $this->caseRepo->getCases($query_args);

        foreach ($result['cases'] as $case_id) {
            $case_id = (int) $case_id;

            $status = sanitize_key((string) get_post_meta($case_id, 'status', true));
            if ('' === $status) {
                $status = sanitize_key((string) get_post_meta($case_id, 'workflow_state', true));
            }

            if ('complete' === $status) {
                $status = 'approved';
            }

            if ('' === $status) {
                $legacy_case_status = sanitize_key((string) get_post_meta($case_id, 'case_status', true));
                if ('closed' === $legacy_case_status) {
                    $status = 'rejected';
                }
            }

            if ('' === $status) {
                $post_status = sanitize_key((string) get_post_status($case_id));
                $status = 'publish' === $post_status ? 'in_review' : $post_status;
            }

            switch ($status) {
                case 'draft':
                    ++$stats['draft'];
                    break;
                case 'returned':
                    ++$stats['returned'];
                    break;
                case 'approved':
                    ++$stats['approved'];
                    break;
                case 'rejected':
                    ++$stats['rejected'];
                    break;
                case 'in_review':
                    ++$stats['pending_review'];
                    break;
            }
        }

        wp_cache_set($cache_key, $stats, 'csp_cases', MINUTE_IN_SECONDS);

        return ApiResponse::success($stats);
    }
}