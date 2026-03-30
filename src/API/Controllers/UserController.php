<?php

declare(strict_types=1);

namespace CSP\API\Controllers;

use WP_REST_Request;
use CSP\API\Responses\ApiResponse;
use CSP\API\Responses\ErrorCodes;
use CSP\Repositories\UserRepository;
use CSP\DTO\DTOMapper;

class UserController
{
    private UserRepository $userRepo;
    private DTOMapper $dtoMapper;

    public function __construct(UserRepository $userRepo, DTOMapper $dtoMapper)
    {
        $this->userRepo = $userRepo;
        $this->dtoMapper = $dtoMapper;
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
            $users[] = $this->dtoMapper->toUser((int)$user_id);
        }

        return ApiResponse::success($users, null, [
            'total'       => $result['total'],
            'total_pages' => $result['total_pages'],
            'page'        => $result['page'],
            'per_page'    => $result['per_page'],
        ]);
    }
}
