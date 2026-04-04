<?php

declare(strict_types=1);

namespace CSP\Roles;

class RoleManager
{
    public function register(): void
    {
        // Add custom roles if they don't exist

        // Field Agent
        if (null === get_role('hm_field_agent')) {
            add_role('hm_field_agent', __('HM Field Agent', 'csp'), [
                'read' => true,
            ]);
        }

        // Marketing
        if (null === get_role('hm_marketing')) {
            add_role('hm_marketing', __('HM Marketing', 'csp'), [
                'read' => true,
            ]);
        }

        // Add manager role if it somehow doesn't exist
        if (null === get_role('hm_manager')) {
            add_role('hm_manager', __('HM Manager', 'csp'), [
                'read' => true,
            ]);
        }

        $this->assign_capabilities();
    }

    private function assign_capabilities(): void
    {
        $administrator = get_role('administrator');
        if ($administrator) {
            $administrator->add_cap('edit_hm_case');
            $administrator->add_cap('read_hm_case');
            $administrator->add_cap('delete_hm_case');
            $administrator->add_cap('edit_hm_cases');
            $administrator->add_cap('edit_others_hm_cases');
            $administrator->add_cap('publish_hm_cases');
            $administrator->add_cap('read_private_hm_cases');
            $administrator->add_cap('create_hm_cases');
            $administrator->add_cap('delete_hm_cases');
            $administrator->add_cap('delete_private_hm_cases');
            $administrator->add_cap('delete_published_hm_cases');
            $administrator->add_cap('delete_others_hm_cases');
            $administrator->add_cap('edit_private_hm_cases');
            $administrator->add_cap('edit_published_hm_cases');
        }

        // Manager capabilities
        $manager = get_role('hm_manager');
        if ($manager) {
            $manager->add_cap('edit_hm_case');
            $manager->add_cap('read_hm_case');
            $manager->add_cap('delete_hm_case');
            $manager->add_cap('edit_hm_cases');
            $manager->add_cap('edit_others_hm_cases');
            $manager->add_cap('publish_hm_cases');
            $manager->add_cap('read_private_hm_cases');
            $manager->add_cap('create_hm_cases');
            $manager->add_cap('upload_files');
            // Managers might not delete but let's give basic ones, actual permissions handled by API middleware
        }

        // Field Agent capabilities
        $field_agent = get_role('hm_field_agent');
        if ($field_agent) {
            $field_agent->add_cap('edit_hm_case');
            $field_agent->add_cap('read_hm_case');
            $field_agent->add_cap('delete_hm_case');
            $field_agent->add_cap('edit_hm_cases');
            $field_agent->add_cap('create_hm_cases');
            $field_agent->add_cap('upload_files');
            // No edit_others_hm_cases
        }

        // Marketing capabilities
        $marketing = get_role('hm_marketing');
        if ($marketing) {
            $marketing->add_cap('read_hm_case');
            $marketing->add_cap('read_private_hm_cases');
            // strictly read-only
        }
    }
}
