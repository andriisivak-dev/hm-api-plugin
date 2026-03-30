<?php

declare(strict_types=1);

namespace CSP\Services;

class NotificationService
{
    /**
     * Notify user via DB and Email.
     */
    public function notify(string $type, int $case_id, int $recipient_id, string $message): void
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'csp_notifications';

        // 1. Save to DB
        $wpdb->insert(
            $table_name,
            [
                'user_id'    => $recipient_id,
                'type'       => $type,
                'case_id'    => $case_id,
                'message'    => $message,
                'is_read'    => 0,
                'created_at' => current_time('mysql', 1)
            ],
            ['%d', '%s', '%d', '%s', '%d', '%s']
        );

        // 2. Send Email
        $user = get_userdata($recipient_id);
        if ($user && is_email($user->user_email)) {
            $case_title = get_the_title($case_id);
            $subject = sprintf("[%s] Notification: %s", get_bloginfo('name'), $case_title);
            wp_mail($user->user_email, $subject, $message);
        }
    }

    public function onCaseSubmitted(int $case_id, int $reviewer_id): void
    {
        if ($reviewer_id > 0) {
            $message = "A case study has been submitted and awaits your review.";
            $this->notify('case_submitted', $case_id, $reviewer_id, $message);
        }
    }

    public function onCaseApproved(int $case_id, int $author_id): void
    {
        // Notify Author
        $this->notify('case_approved', $case_id, $author_id, "Your case study has been approved.");

        // Notify Admins
        $admins = get_users(['role__in' => ['administrator', 'hm_administrator'], 'fields' => 'ID']);
        foreach ($admins as $admin_id) {
            if ((int)$admin_id !== $author_id) {
                $this->notify('case_approved_global', $case_id, (int)$admin_id, "A case study has been approved.");
            }
        }
    }

    public function onCaseRejected(int $case_id, int $author_id, string $reason): void
    {
        // Notify Author
        $this->notify('case_rejected', $case_id, $author_id, "Your case study was rejected. Reason: " . $reason);

        // Notify Admins
        $admins = get_users(['role__in' => ['administrator', 'hm_administrator'], 'fields' => 'ID']);
        foreach ($admins as $admin_id) {
            if ((int)$admin_id !== $author_id) {
                $this->notify('case_rejected_global', $case_id, (int)$admin_id, "A case study has been rejected.");
            }
        }
    }

    public function onCaseReturned(int $case_id, int $author_id, string $reason): void
    {
        $this->notify('case_returned', $case_id, $author_id, "Your case study was returned for revision. Reason: " . $reason);
    }
}
