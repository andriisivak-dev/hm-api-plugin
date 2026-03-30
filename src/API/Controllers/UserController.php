<?php

declare(strict_types=1);

namespace CSP\API\Controllers;

use WP_REST_Request;
use CSP\API\Responses\ApiResponse;
use CSP\API\Responses\ErrorCodes;
use CSP\Repositories\UserRepository;

class UserController
{
    private UserRepository $userRepo;

    public function __construct(UserRepository $userRepo)
    {
        $this->userRepo = $userRepo;
    }

    public function index(WP_REST_Request $request)
    {
        $args = $request->get_params();

        // Enforce permissions for users endpoint (admin only typically, or manager for own agents)
        $current_user = get_userdata(get_current_user_id());
        $is_admin = in_array('administrator', $current_user->roles) || in_array('hm_administrator', $current_user->roles);
        $is_manager = in_array('hm_manager', $current_user->roles);

        if (!$is_admin) {
            if ($is_manager) {
                // Manager only sees their assigned field agents
                $agent_ids_raw = get_user_meta($current_user->ID, '_assigned_agent_ids', true);
                if (empty($agent_ids_raw)) {
                    return ApiResponse::success([], null, ['total' => 0, 'total_pages' => 1, 'page' => 1, 'per_page' => 20]);
                }
                $args['include'] = json_decode($agent_ids_raw, true);
            } else {
                return ApiResponse::error(ErrorCodes::FORBIDDEN, __('Forbidden', 'csp'), 403);
            }
        }

        $result = $this->userRepo->getUsers($args);

        $users = [];
        foreach ($result['users'] as $user_id) {
            $user_info = get_userdata((int)$user_id);
            if ($user_info) {
                $status = get_user_meta((int)$user_id, '_user_status', true) ?: 'active';
                $users[] = [
                    'id'         => $user_info->ID,
                    'full_name'  => $user_info->display_name,
                    'email'      => $user_info->user_email,
                    'role'       => !empty($user_info->roles) ? $user_info->roles[0] : '',
                    'status'     => $status,
                    'created_at' => $user_info->user_registered,
                ];
            }
        }

        return ApiResponse::success($users, null, [
            'total'       => $result['total'],
            'total_pages' => $result['total_pages'],
            'page'        => $result['page'],
            'per_page'    => $result['per_page'],
        ]);
    }
}
