<?php

declare(strict_types=1);

namespace CSP\Services;

class NotificationService
{
    // -------------------------------------------------------------------------
    // Core
    // -------------------------------------------------------------------------

    /**
     * Notify user via DB (plain text) and Email (HTML).
     *
     * @param string      $type         Notification type slug.
     * @param int         $case_id      Related case post ID.
     * @param int         $recipient_id WP user ID of the recipient.
     * @param string      $db_message   Short plain-text message stored in DB.
     * @param string|null $email_body   Full HTML email body. Falls back to escaped $db_message if null.
     */

    public function notify(
        string  $type,
        int     $case_id,
        int     $recipient_id,
        string  $db_message,
        ?string $email_body = null
    ): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'csp_notifications';

        // 1. Save plain-text message to DB
        $wpdb->insert(
            $table_name,
            [
                'user_id'    => $recipient_id,
                'type'       => $type,
                'case_id'    => $case_id,
                'message'    => $db_message,
                'is_read'    => 0,
                'created_at' => current_time('mysql', 1),
            ],
            ['%d', '%s', '%d', '%s', '%d', '%s']
        );

        // 2. Send HTML email
        $user = get_userdata($recipient_id);
        if (! $user || ! is_email($user->user_email)) {
            return;
        }

        $subject = sprintf('[%s] Case Study Notification', get_bloginfo('name'));
        $body    = $email_body ?? '<p>' . nl2br(esc_html($db_message)) . '</p>';

        add_filter('wp_mail_content_type', static fn() => 'text/html');
        wp_mail($user->user_email, $subject, $body);
        remove_filter('wp_mail_content_type', static fn() => 'text/html');
    }

    // -------------------------------------------------------------------------
    // Domain events
    // -------------------------------------------------------------------------

    public function onCaseSubmitted(int $case_id, int $reviewer_id): void
    {
        if ($reviewer_id <= 0) {
            return;
        }

        $ctx = $this->buildCaseContext($case_id);

        $db_message = sprintf(
            'Case study "%s" (#%d) has been submitted and awaits your review. Submitter: %s',
            $ctx['title'],
            $case_id,
            $ctx['author_name']
        );

        $email_body = $this->renderEmail(
            '📋 New Case Study Awaiting Review',
            sprintf(
                '<p>A new case study has been submitted and requires your attention.</p>
                 <table>
                   <tr>
                     <td><strong>Case:</strong></td>
                     <td><a href="%1$s">%2$s</a> <em>(#%3$d)</em></td>
                   </tr>
                   <tr>
                     <td><strong>Submitted by:</strong></td>
                     <td>%4$s</td>
                   </tr>
                 </table>
                 <p><a class="btn" href="%1$s">Review Case →</a></p>',
                esc_url($ctx['url']),
                esc_html($ctx['title']),
                $case_id,
                esc_html($ctx['author_name'])
            )
        );

        $this->notify('case_submitted', $case_id, $reviewer_id, $db_message, $email_body);
    }

    public function onCaseApproved(int $case_id, int $author_id): void
    {
        $ctx = $this->buildCaseContext($case_id);

        // Determine the author's role to tailor the approval message.
        $author       = get_userdata($author_id);
        $author_roles = $author ? (array) $author->roles : [];
        $is_field_agent = in_array('hm_field_agent', $author_roles, true);

        if ($is_field_agent) {
            // Field agent: case was reviewed and approved by a supervisor/admin.
            $db_message = sprintf(
                'Your case study "%s" (#%d) has been approved and is now published.',
                $ctx['title'],
                $case_id
            );

            $email_body = $this->renderEmail(
                '✅ Your Case Study Has Been Approved',
                sprintf(
                    '<p>Congratulations! Your case study has been reviewed and approved.</p>
                     <table>
                       <tr>
                         <td><strong>Case:</strong></td>
                         <td><a href="%1$s">%2$s</a> <em>(#%3$d)</em></td>
                       </tr>
                     </table>
                     <p><a class="btn" href="%1$s">View Published Case →</a></p>',
                    esc_url($ctx['url']),
                    esc_html($ctx['title']),
                    $case_id
                )
            );
        } else {
            // Manager / superadmin: case was auto-published to the Case Library on submit.
            $db_message = sprintf(
                'Your case study "%s" (#%d) has been published in the Case Library.',
                $ctx['title'],
                $case_id
            );

            $email_body = $this->renderEmail(
                '✅ Your Case Study Has Been Published',
                sprintf(
                    '<p>Your case study has been published in the Case Library.</p>
                     <table>
                       <tr>
                         <td><strong>Case:</strong></td>
                         <td><a href="%1$s">%2$s</a> <em>(#%3$d)</em></td>
                       </tr>
                     </table>
                     <p><a class="btn" href="%1$s">View Published Case →</a></p>',
                    esc_url($ctx['url']),
                    esc_html($ctx['title']),
                    $case_id
                )
            );
        }

        $this->notify('case_approved', $case_id, $author_id, $db_message, $email_body);
    }

    public function onCaseRejected(int $case_id, int $author_id, string $reason): void
    {
        $ctx = $this->buildCaseContext($case_id);

        $db_message = sprintf(
            'Your case study "%s" (#%d) was rejected. Reason: %s',
            $ctx['title'],
            $case_id,
            $reason
        );

        $email_body = $this->renderEmail(
            '❌ Your Case Study Was Rejected',
            sprintf(
                '<p>Unfortunately, your case study has not been approved at this time.</p>
                 <table>
                   <tr>
                     <td><strong>Case:</strong></td>
                     <td><a href="%1$s">%2$s</a> <em>(#%3$d)</em></td>
                   </tr>
                   <tr>
                     <td><strong>Reason:</strong></td>
                     <td>%4$s</td>
                   </tr>
                 </table>
                 <p>Please review the feedback and consider submitting a revised version.</p>
                 <p><a class="btn" href="%1$s">View Case →</a></p>',
                esc_url($ctx['url']),
                esc_html($ctx['title']),
                $case_id,
                esc_html($reason)
            )
        );

        $this->notify('case_rejected', $case_id, $author_id, $db_message, $email_body);
    }

    public function onCaseReturned(int $case_id, int $author_id, string $reason): void
    {
        $ctx = $this->buildCaseContext($case_id);

        $db_message = sprintf(
            'Your case study "%s" (#%d) was returned for revision. Reason: %s',
            $ctx['title'],
            $case_id,
            $reason
        );

        $email_body = $this->renderEmail(
            '🔄 Your Case Study Needs Revision',
            sprintf(
                '<p>Your case study has been reviewed and returned for revision.</p>
                 <table>
                   <tr>
                     <td><strong>Case:</strong></td>
                     <td><a href="%1$s">%2$s</a> <em>(#%3$d)</em></td>
                   </tr>
                   <tr>
                     <td><strong>Revision notes:</strong></td>
                     <td>%3$s</td>
                   </tr>
                 </table>
                 <p>Please make the necessary changes and resubmit when ready.</p>
                 <p><a class="btn" href="%1$s">Edit Case →</a></p>',
                esc_url($ctx['url']),
                esc_html($ctx['title']),
                $case_id,
                esc_html($reason)
            )
        );

        $this->notify('case_returned', $case_id, $author_id, $db_message, $email_body);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Collect reusable case metadata in one place.
     *
     * Uses the custom frontend URL /case-study/?cid={id} instead of get_permalink(),
     * because the CPT is not publicly queryable on its own slug.
     *
     * @param int $case_id
     * @return array{title: string, url: string, author_name: string}
     */
    private function buildCaseContext(int $case_id): array
    {
        $title = get_the_title($case_id);
        $url   = home_url('/case-study/?cid=' . $case_id);

        $post        = get_post($case_id);
        $author_id   = (int)$post?->post_author;
        $author_name = $author_id
            ? trim(get_the_author_meta('display_name', $author_id))
            : 'Unknown';

        return compact('title', 'url', 'author_name');
    }

    /**
     * Wraps content in a minimal inline-CSS HTML email shell.
     *
     * Inline styles are required for compatibility with Gmail, Outlook, etc.
     *
     * @param string $heading
     * @param string $content
     * @return string
     */
    private function renderEmail(string $heading, string $content): string
    {
        $site_name = esc_html((string) get_bloginfo('name'));
        $site_url  = esc_url(home_url('/'));
        $year      = gmdate('Y');

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
          <meta charset="UTF-8">
          <meta name="viewport" content="width=device-width, initial-scale=1.0">
        </head>
        <body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif;">
          <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:40px 0;">
            <tr>
              <td align="center">
                <table width="600" cellpadding="0" cellspacing="0"
                       style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.1);">

                  <!-- Header -->
                  <tr>
                    <td style="background:#2563eb;padding:24px 32px;color:#ffffff;font-size:20px;font-weight:bold;">
                      {$heading}
                    </td>
                  </tr>

                  <!-- Body -->
                  <tr>
                    <td style="padding:32px;color:#333333;font-size:15px;line-height:1.6;">
                      {$content}
                    </td>
                  </tr>

                  <!-- Footer -->
                  <tr>
                    <td style="background:#f9f9f9;padding:16px 32px;font-size:12px;color:#999999;
                                border-top:1px solid #eeeeee;text-align:center;">
                      You are receiving this email as a member of
                      <a href="{$site_url}" style="color:#2563eb;">{$site_name}</a>.
                      &copy; {$year} {$site_name}
                    </td>
                  </tr>

                </table>
              </td>
            </tr>
          </table>
        </body>
        </html>
        HTML;
    }
}
