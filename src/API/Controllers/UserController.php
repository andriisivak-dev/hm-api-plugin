<?php

declare(strict_types=1);

namespace CSP\API\Controllers;

use WP_REST_Request;
use CSP\API\Responses\ApiResponse;
use CSP\API\Responses\ErrorCodes;
use CSP\Repositories\UserRepository;
use CSP\DTO\DTOMapper;
use CSP\Exceptions\ApiException;

class UserController
{
    private UserRepository $userRepo;
    private DTOMapper $dtoMapper;
    private \CSP\Services\UserService $userService;

    public function __construct(UserRepository $userRepo, DTOMapper $dtoMapper, \CSP\Services\UserService $userService)
    {
        $this->userRepo = $userRepo;
        $this->dtoMapper = $dtoMapper;
        $this->userService = $userService;
    }

    public function index(WP_REST_Request $request)
    {
        $args = $request->get_params();

        // Enforce permissions for users endpoint (admin only typically, or manager for own agents)
        $current_user = get_userdata(get_current_user_id());
        $is_admin     = in_array('administrator', (array) $current_user->roles);
        $is_manager   = in_array('hm_manager', (array) $current_user->roles);

        if (!$is_admin) {
            if ($is_manager) {
                // Manager only sees their assigned field agents
                $agent_ids_raw = get_user_meta($current_user->ID, '_assigned_agent_ids', true);
                if (empty($agent_ids_raw)) {
                    return ApiResponse::success([], '', ['total' => 0, 'total_pages' => 1, 'page' => 1, 'per_page' => 20]);
                }
                $args['include'] = is_array($agent_ids_raw) ? $agent_ids_raw : json_decode((string)$agent_ids_raw, true);
            } else {
                return ApiResponse::error(ErrorCodes::FORBIDDEN, __('Forbidden', 'csp'), 403);
            }
        }

        $result = $this->userRepo->getUsers($args);

        $users = [];
        foreach ($result['users'] as $user_id) {
            $users[] = $this->dtoMapper->toUser((int)$user_id);
        }

        return ApiResponse::success($users, '', [
            'total'       => $result['total'],
            'total_pages' => $result['total_pages'],
            'page'        => $result['page'],
            'per_page'    => $result['per_page'],
        ]);
    }

    public function create(WP_REST_Request $request)
    {
        $this->requireSuperAdmin();
        $params = $request->get_json_params() ?? [];

        $full_name = sanitize_text_field($params['full_name'] ?? '');
        $email = sanitize_email($params['email'] ?? '');
        $role = sanitize_key($params['role'] ?? '');
        $password = $params['password'] ?? '';
        $manager_id = (int)($params['manager_id'] ?? 0);

        if (!$full_name || !$email || !$role || !$password) {
            return ApiResponse::error(ErrorCodes::VALIDATION_ERROR, 'Missing required fields.', 400);
        }

        if (!is_email($email)) {
             return ApiResponse::error(ErrorCodes::VALIDATION_ERROR, 'Invalid email.', 400);
        }

        if (email_exists($email)) {
            return ApiResponse::error(ErrorCodes::CONFLICT, 'Email address is already in use.', 409);
        }

        $allowed_roles = ['hm_manager', 'hm_field_agent', 'hm_marketing'];
        if (!in_array($role, $allowed_roles, true)) {
            return ApiResponse::error(ErrorCodes::VALIDATION_ERROR, 'Invalid role.', 400);
        }
        
        $base = sanitize_user(strtolower(str_replace(' ', '.', $full_name)), true);
        if (empty($base)) {
            $base = sanitize_user(explode('@', $email)[0], true);
        }
        $username = $base;
        $i = 1;
        while (username_exists($username)) {
            $username = $base . $i++;
        }
        
        $user_id = wp_insert_user([
            'user_login' => $username,
            'user_email' => $email,
            'display_name' => $full_name,
            'first_name' => explode(' ', trim($full_name), 2)[0] ?? '',
            'last_name' => explode(' ', trim($full_name), 2)[1] ?? '',
            'user_pass' => $password,
            'role' => $role,
        ]);

        if (is_wp_error($user_id)) {
            return ApiResponse::error(ErrorCodes::INTERNAL_ERROR, $user_id->get_error_message(), 500);
        }

        if ($manager_id > 0 && $role === 'hm_field_agent') {
            update_user_meta($user_id, '_assigned_manager_id', $manager_id);
            $this->userService->rebuildManagerAgents($manager_id);
        }
        
        update_user_meta($user_id, '_user_status', 'active');
        
        wp_new_user_notification($user_id, null, 'user');

        return ApiResponse::success($this->dtoMapper->toUser($user_id));
    }

    public function update(WP_REST_Request $request)
    {
        $this->requireSuperAdmin();
        $user_id = (int)$request->get_param('id');
        $user = get_userdata($user_id);
        
        if (!$user) {
            return ApiResponse::error(ErrorCodes::NOT_FOUND, 'User not found.', 404);
        }

        if (in_array('administrator', $user->roles)) {
            return ApiResponse::error(ErrorCodes::FORBIDDEN, 'Cannot modify a super admin account via this endpoint.', 403);
        }
        
        $params = $request->get_json_params() ?? [];
        $update_args = ['ID' => $user_id];
        
        if (isset($params['full_name'])) {
            $update_args['display_name'] = sanitize_text_field($params['full_name']);
            $parts = explode(' ', trim($update_args['display_name']), 2);
            $update_args['first_name'] = $parts[0] ?? '';
            $update_args['last_name'] = $parts[1] ?? '';
        }
        
        if (isset($params['email'])) {
            $new_email = sanitize_email($params['email']);
            if (!is_email($new_email)) {
                return ApiResponse::error(ErrorCodes::VALIDATION_ERROR, 'Invalid email.', 400);
            }
            $existing = email_exists($new_email);
            if ($existing && (int)$existing !== $user_id) {
                return ApiResponse::error(ErrorCodes::CONFLICT, 'Email address is already in use.', 409);
            }
            $update_args['user_email'] = $new_email;
        }
        
        if (isset($params['role'])) {
            $role = sanitize_key($params['role']);
            $allowed_roles = ['hm_manager', 'hm_field_agent', 'hm_marketing'];
            if (!in_array($role, $allowed_roles, true)) {
                return ApiResponse::error(ErrorCodes::VALIDATION_ERROR, 'Invalid role.', 400);
            }
            $update_args['role'] = $role;
            if (!in_array($role, ['hm_field_agent'])) {
                $old_manager_id = (int) get_user_meta($user_id, '_assigned_manager_id', true);
                delete_user_meta($user_id, '_assigned_manager_id');
                if ($old_manager_id > 0) {
                    $this->userService->rebuildManagerAgents($old_manager_id);
                }
            }
        }
        
        if (isset($params['password']) && !empty($params['password'])) {
            if (strlen($params['password']) < 8) {
                return ApiResponse::error(ErrorCodes::VALIDATION_ERROR, 'Password must be at least 8 characters.', 400);
            }
            $update_args['user_pass'] = $params['password'];
        }
        
        $result = wp_update_user($update_args);
        if (is_wp_error($result)) {
            return ApiResponse::error(ErrorCodes::INTERNAL_ERROR, $result->get_error_message(), 500);
        }

        $current_role = $update_args['role'] ?? current((array) $user->roles);
        if ($current_role === 'hm_field_agent' && isset($params['manager_id'])) {
            $manager_id = (int) $params['manager_id'];
            $old_manager_id = (int) get_user_meta($user_id, '_assigned_manager_id', true);

            update_user_meta($user_id, '_assigned_manager_id', $manager_id);
            
            if ($manager_id > 0) {
                $this->userService->rebuildManagerAgents($manager_id);
            }
            if ($old_manager_id > 0 && $old_manager_id !== $manager_id) {
                $this->userService->rebuildManagerAgents($old_manager_id);
            }
        }
        
        if (isset($params['status'])) {
            update_user_meta($user_id, '_user_status', $params['status'] === 'inactive' ? 'inactive' : 'active');
        }

        return ApiResponse::success($this->dtoMapper->toUser($user_id));
    }

    public function delete(WP_REST_Request $request)
    {
        $this->requireSuperAdmin();
        $user_id = (int)$request->get_param('id');
        
        if (get_current_user_id() === $user_id) {
            return ApiResponse::error(ErrorCodes::FORBIDDEN, 'Cannot delete your own account.', 403);
        }
        
        $user = get_userdata($user_id);
        if (!$user) {
            return ApiResponse::error(ErrorCodes::NOT_FOUND, 'User not found.', 404);
        }

        if (in_array('administrator', $user->roles)) {
            return ApiResponse::error(ErrorCodes::FORBIDDEN, 'Cannot delete a super admin account via this endpoint.', 403);
        }
        
        update_user_meta($user_id, '_user_status', 'inactive');
        
        return ApiResponse::success($this->dtoMapper->toUser($user_id), 'User deactivated successfully.');
    }

    public function updateAvatar(WP_REST_Request $request)
    {
        $userId = (int)get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error(ErrorCodes::UNAUTHORIZED, 'Invalid user.', 401);
        }

        $files = $request->get_file_params();
        $file = $files['avatar'] ?? null;

        $result = $this->userService->uploadAvatar($userId, $file);

        return ApiResponse::success($result, 'Avatar updated successfully.');
    }

    private function requireSuperAdmin(): void
    {
        $current_user = get_userdata(get_current_user_id());
        if (!$current_user) {
            throw new ApiException('Unauthorized', ErrorCodes::UNAUTHORIZED, 401);
        }
        $is_admin = in_array('administrator', (array) $current_user->roles);
        if (!$is_admin) {
            throw new ApiException('Forbidden', ErrorCodes::FORBIDDEN, 403);
        }
    }
}
