<?php

declare(strict_types=1);

namespace CSP\Hooks;

use CSP\Services\UserService;

class UserHooks
{
    private UserService $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function register(): void
    {
        // When meta is added, updated, or deleted
        add_action('updated_user_meta', [$this, 'onUserMetaChanged'], 10, 4);
        add_action('added_user_meta', [$this, 'onUserMetaChanged'], 10, 4);
        add_action('deleted_user_meta', [$this, 'onUserMetaChanged'], 10, 4);

        // Capture previous meta value before update/delete to rebuild old manager
        add_filter('update_user_metadata', [$this, 'captureOldManagerBeforeUpdate'], 10, 5);
        add_filter('delete_user_metadata', [$this, 'captureOldManagerBeforeDelete'], 10, 5);
    }

    /**
     * Rebuilds mapping when _assigned_manager_id or _user_status changes.
     */
    public function onUserMetaChanged($meta_id, $object_id, $meta_key, $_meta_value): void
    {
        $user_id = (int) $object_id;

        if ($meta_key === '_assigned_manager_id') {
            // Rebuild new manager
            $new_manager_id = (int) get_user_meta($user_id, '_assigned_manager_id', true);
            if ($new_manager_id > 0) {
                $this->userService->rebuildManagerAgents($new_manager_id);
            }

            // Rebuild old manager if stored
            global $csp_old_manager_ids;
            if (!empty($csp_old_manager_ids[$user_id])) {
                $old_manager_id = (int) $csp_old_manager_ids[$user_id];
                if ($old_manager_id !== $new_manager_id && $old_manager_id > 0) {
                    $this->userService->rebuildManagerAgents($old_manager_id);
                }
                unset($csp_old_manager_ids[$user_id]);
            }
        } elseif ($meta_key === '_user_status') {
            // Rebuild if user becomes inactive/active
            $manager_id = (int) get_user_meta($user_id, '_assigned_manager_id', true);
            if ($manager_id > 0) {
                $this->userService->rebuildManagerAgents($manager_id);
            }
        }
    }

    public function captureOldManagerBeforeUpdate($check, $object_id, $meta_key, $meta_value, $prev_value)
    {
        if ($meta_key === '_assigned_manager_id') {
            global $csp_old_manager_ids;
            $old_manager_id = get_user_meta((int)$object_id, '_assigned_manager_id', true);
            if ($old_manager_id) {
                $csp_old_manager_ids[(int)$object_id] = (int) $old_manager_id;
            }
        }
        return $check;
    }

    public function captureOldManagerBeforeDelete($check, $object_id, $meta_key, $meta_value, $delete_all)
    {
        if ($meta_key === '_assigned_manager_id') {
            global $csp_old_manager_ids;
            $old_manager_id = get_user_meta((int)$object_id, '_assigned_manager_id', true);
            if ($old_manager_id) {
                $csp_old_manager_ids[(int)$object_id] = (int) $old_manager_id;
            }
        }
        return $check;
    }
}
