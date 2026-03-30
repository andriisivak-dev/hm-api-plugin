<?php

declare(strict_types=1);

namespace CSP\API\Controllers;

use WP_REST_Request;
use CSP\API\Responses\ApiResponse;
use CSP\API\Responses\ErrorCodes;
use CSP\Repositories\NotificationRepository;

class NotificationController
{
    private NotificationRepository $notifRepo;

    public function __construct(NotificationRepository $notifRepo)
    {
        $this->notifRepo = $notifRepo;
    }

    public function index(WP_REST_Request $request)
    {
        $current_user_id = get_current_user_id();
        $args = $request->get_params();

        $result = $this->notifRepo->getNotifications($current_user_id, $args);

        // Simple mapping, DTOs would be better
        $notifications = [];
        foreach ($result['notifications'] as $notif) {
            $notifications[] = [
                'id'         => (int) $notif['id'],
                'type'       => $notif['type'],
                'case_id'    => (int) $notif['case_id'],
                'message'    => $notif['message'],
                'is_read'    => (bool) $notif['is_read'],
                'created_at' => $notif['created_at'],
            ];
        }

        return ApiResponse::success($notifications, null, [
            'total'       => $result['total'],
            'total_pages' => $result['total_pages'],
            'page'        => $result['page'],
            'per_page'    => $result['per_page'],
        ]);
    }

    public function markAsRead(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        $current_user_id = get_current_user_id();

        $success = $this->notifRepo->markAsRead($id, $current_user_id);
        if (!$success) {
            return ApiResponse::error(ErrorCodes::INTERNAL_ERROR, __('Could not mark notification as read.', 'csp'), 500);
        }

        return ApiResponse::success(['id' => $id, 'is_read' => true]);
    }

    public function readAll(WP_REST_Request $request)
    {
        $current_user_id = get_current_user_id();
        $success = $this->notifRepo->markAllAsRead($current_user_id);

        if (!$success) {
            return ApiResponse::error(ErrorCodes::INTERNAL_ERROR, __('Could not update notifications.', 'csp'), 500);
        }

        return ApiResponse::success(['success' => true]);
    }

    public function getUnreadCount(WP_REST_Request $request)
    {
        $current_user_id = get_current_user_id();
        $count = $this->notifRepo->getUnreadCount($current_user_id);

        return ApiResponse::success(['unread_count' => $count]);
    }
}
