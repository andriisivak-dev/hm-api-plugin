<?php

declare(strict_types=1);

namespace CSP\Services;

class UserService
{
    /**
     * Rebuilds the _assigned_agent_ids array for a specific manager.
     */
    public function rebuildManagerAgents(int $manager_id): void
    {
        if ($manager_id <= 0) {
            return;
        }

        // Get all active users who have this manager_id assigned.
        // We ensure we only fetch hm_field_agent or hm_manager roles that are assigned
        $args = [
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key'     => '_assigned_manager_id',
                    'value'   => $manager_id,
                    'compare' => '=',
                ],
                [
                    'relation' => 'OR',
                    [
                        'key'     => '_user_status',
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key'     => '_user_status',
                        'value'   => 'inactive',
                        'compare' => '!=',
                    ]
                ]
            ],
            'fields' => 'ID',
        ];

        $users = get_users($args);
        $agent_ids = array_map('intval', $users);

        update_user_meta($manager_id, '_assigned_agent_ids', $agent_ids);
    }

    /**
     * Upload and assign avatar for a user.
     *
     * @param int $userId
     * @param array|null $file The $_FILES array entry for the avatar
     * @return array
     * @throws \CSP\Exceptions\ApiException
     */
    public function uploadAvatar(int $userId, ?array $file): array
    {
        if ($userId <= 0) {
            throw new \CSP\Exceptions\ApiException('Invalid user.', \CSP\API\Responses\ErrorCodes::BAD_REQUEST, 400);
        }

        if (!$file || empty($file['tmp_name'])) {
            throw new \CSP\Exceptions\ApiException('Please select an image file.', \CSP\API\Responses\ErrorCodes::VALIDATION_ERROR, 400);
        }

        if (!empty($file['error']) && UPLOAD_ERR_OK !== (int)$file['error']) {
            throw new \CSP\Exceptions\ApiException('Avatar upload failed.', \CSP\API\Responses\ErrorCodes::BAD_REQUEST, 400);
        }

        $maxSize = 3 * 1024 * 1024;
        if (!empty($file['size']) && (int)$file['size'] > $maxSize) {
            throw new \CSP\Exceptions\ApiException('Avatar must be 3MB or less.', \CSP\API\Responses\ErrorCodes::VALIDATION_ERROR, 400);
        }

        $allowedMimes = [
            'jpg|jpeg|jpe' => 'image/jpeg',
            'png'          => 'image/png',
            'webp'         => 'image/webp',
        ];

        $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], $allowedMimes);
        if (empty($check['ext']) || empty($check['type'])) {
            throw new \CSP\Exceptions\ApiException('Only JPG, PNG, or WEBP images are allowed.', \CSP\API\Responses\ErrorCodes::VALIDATION_ERROR, 400);
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $uploaded = wp_handle_upload($file, [
            'test_form' => false,
            'mimes'     => $allowedMimes,
        ]);

        if (!empty($uploaded['error'])) {
            throw new \CSP\Exceptions\ApiException((string)$uploaded['error'], \CSP\API\Responses\ErrorCodes::BAD_REQUEST, 400);
        }

        $attachmentId = wp_insert_attachment([
            'post_mime_type' => $uploaded['type'],
            'post_title'     => sanitize_file_name(pathinfo((string)$file['name'], PATHINFO_FILENAME)),
            'post_content'   => '',
            'post_status'    => 'inherit',
            'post_author'    => $userId,
        ], $uploaded['file']);

        if (is_wp_error($attachmentId) || !$attachmentId) {
            throw new \CSP\Exceptions\ApiException('Could not save avatar.', \CSP\API\Responses\ErrorCodes::INTERNAL_ERROR, 500);
        }

        $metadata = wp_generate_attachment_metadata($attachmentId, $uploaded['file']);
        wp_update_attachment_metadata($attachmentId, $metadata);

        $previousAvatarId = function_exists('hemant_get_user_avatar_id') 
            ? hemant_get_user_avatar_id($userId) 
            : (int)get_user_meta($userId, 'hemant_user_avatar_id', true);

        if (function_exists('hemant_set_user_avatar_id')) {
            hemant_set_user_avatar_id($userId, (int)$attachmentId);
        } else {
            update_user_meta($userId, 'hemant_user_avatar_id', (int)$attachmentId);
        }

        // cleanup previous uploaded avatar if it belongs to the same user.
        if ($previousAvatarId > 0) {
            $previousPost = get_post($previousAvatarId);
            if (
                $previousPost instanceof \WP_Post
                && 'attachment' === $previousPost->post_type
                && (int)$previousPost->post_author === $userId
            ) {
                wp_delete_attachment($previousAvatarId, true);
            }
        }

        return [
            'updated'    => true,
            'avatar_id'  => (int)$attachmentId,
            'avatar_url' => wp_get_attachment_image_url((int)$attachmentId, 'thumbnail'),
        ];
    }
}
