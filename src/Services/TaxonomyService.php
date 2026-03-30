<?php

declare(strict_types=1);

namespace CSP\Services;

class TaxonomyService
{
    private array $formFieldMap;

    public function __construct()
    {
        $this->formFieldMap = require __DIR__ . '/../Config/FormFieldMap.php';
    }

    /**
     * Extracts taxonomy values from form data, creates terms if they don't exist,
     * and assigns them to the case.
     */
    public function syncTaxonomies(int $case_id, array $form_data): void
    {
        foreach ($this->formFieldMap as $map_entry) {
            if (!empty($map_entry['storage']['taxonomy'])) {
                $f_id = (string) $map_entry['field_id'];
                $taxonomy = $map_entry['storage']['taxonomy'];

                if (!empty($form_data[$f_id])) {
                    $term_name = sanitize_text_field((string)$form_data[$f_id]);

                    // Auto-create term if it doesn't exist
                    $term = term_exists($term_name, $taxonomy);
                    if (!$term) {
                        $term = wp_insert_term($term_name, $taxonomy);
                    }

                    if (!is_wp_error($term)) {
                        $term_id = is_array($term) ? (int)$term['term_id'] : (int)$term;
                        wp_set_object_terms($case_id, [$term_id], $taxonomy, false); // False = replace existing
                    }
                } else {
                    // If field is empty, clear terms for this taxonomy
                    wp_set_object_terms($case_id, [], $taxonomy, false);
                }
            }
        }
    }

    /**
     * Removes all mapped taxonomies from a case (e.g., prior to soft delete).
     */
    public function removeTaxonomies(int $case_id): void
    {
        foreach ($this->formFieldMap as $map_entry) {
            if (!empty($map_entry['storage']['taxonomy'])) {
                $taxonomy = $map_entry['storage']['taxonomy'];
                wp_set_object_terms($case_id, [], $taxonomy, false);
            }
        }
    }
}
