<?php

declare(strict_types=1);

namespace CSP\Services;

use WP_Error;

class CaseFormDataService
{
    private CaseService $caseService;
    private array $formFieldMap;

    public function __construct(CaseService $caseService)
    {
        $this->caseService = $caseService;
        $this->formFieldMap = require __DIR__ . '/../Config/FormFieldMap.php';
    }

    /**
     * Partially or fully save form data to the case without changing status.
     * 
     * @param int $case_id
     * @param array $fields E.g., ['100' => 'Some Name', '2' => 'City']
     * @param int $current_step The step the user is currently on or has just completed
     * @return array|WP_Error
     */
    public function saveFormData(int $case_id, array $fields, int $current_step)
    {
        $case = $this->caseService->getCase($case_id);
        if (!$case) {
            return new WP_Error('csp_not_found', __('Case not found.', 'csp'), ['status' => 404]);
        }

        // 1. Merge the new fields into existing JSON
        $existing_data = $case['hm_form_data'];
        
        foreach ($fields as $field_id => $value) {
            $existing_data[(string)$field_id] = $value;
        }

        update_post_meta($case_id, 'hm_form_data', wp_json_encode($existing_data));

        // 2. Update current_step
        update_post_meta($case_id, 'current_step', $current_step);

        // 3. Process fields marked as sync_on => 'always'
        $title_updated = false;
        foreach ($this->formFieldMap as $map_entry) {
            if (isset($map_entry['sync_on']) && $map_entry['sync_on'] === 'always') {
                $f_id = (string) $map_entry['field_id'];
                
                if (isset($existing_data[$f_id])) {
                    $val = $existing_data[$f_id];
                    
                    // Specific logic for Customer Name field (ID 100) to update the post title
                    if ($f_id === '100' && !empty($val)) {
                        wp_update_post([
                            'ID'         => $case_id,
                            'post_title' => sanitize_text_field($val) . ' #' . $case_id,
                        ]);
                        $title_updated = true;
                    }

                    // Also sync the meta_key if defined
                    if (!empty($map_entry['storage']['meta_key'])) {
                        update_post_meta($case_id, $map_entry['storage']['meta_key'], sanitize_text_field((string)$val));
                    }
                }
            }
        }

        // Refetch case to get updated title and data
        $updated_case = $this->caseService->getCase($case_id);
        $progress = $this->calculateProgress($current_step, $updated_case['total_steps']);

        return [
            'id'           => $updated_case['id'],
            'title'        => $updated_case['title'],
            'current_step' => $updated_case['current_step'],
            'progress'     => $progress,
        ];
    }

    /**
     * Get form data for rendering frontend form state.
     */
    public function getFormData(int $case_id): array|WP_Error
    {
        $case = $this->caseService->getCase($case_id);
        if (!$case) {
            return new WP_Error('csp_not_found', __('Case not found.', 'csp'), ['status' => 404]);
        }

        return [
            'current_step' => $case['current_step'],
            'total_steps'  => $case['total_steps'],
            'progress'     => $this->calculateProgress($case['current_step'], $case['total_steps']),
            'fields'       => $case['hm_form_data'],
        ];
    }

    /**
     * Calculates percentage progress based on current step and total steps.
     */
    private function calculateProgress(int $current_step, int $total_steps): int
    {
        if ($total_steps <= 0) {
            return 0;
        }
        $percentage = ($current_step / $total_steps) * 100;
        return (int) min(100, max(0, round($percentage)));
    }
}
