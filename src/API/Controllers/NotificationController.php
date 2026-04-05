<?php

declare(strict_types=1);

namespace CSP\API\Controllers;

use WP_REST_Request;
use CSP\API\Responses\ApiResponse;
use CSP\API\Responses\ErrorCodes;
use CSP\Repositories\NotificationRepository;
use CSP\DTO\DTOMapper;

class NotificationController
{
    private NotificationRepository $notifRepo;
    private DTOMapper $dtoMapper;

    public function __construct(NotificationRepository $notifRepo, DTOMapper $dtoMapper)
    {
        $this->notifRepo = $notifRepo;
        $this->dtoMapper = $dtoMapper;
    }

    public function index(WP_REST_Request $request)
    {
        $current_user_id = get_current_user_id();
        $args = $request->get_params();

        $result = $this->notifRepo->getNotifications($current_user_id, $args);

        $notifications = [];
        foreach ($result['notifications'] as $notif) {
            $notifications[] = $this->dtoMapper->toNotification($notif);
        }

        return ApiResponse::success($notifications, '', [
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
