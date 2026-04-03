<?php

declare(strict_types=1);

namespace CSP\Services;

use GFAPI;

class GravityFormsService
{
    /**
     * Retrieves and transforms the Gravity Forms schema for frontend usage.
     * Does NOT create/submit entries.
     */
    public function getFormSchema(int $form_id): ?array
    {
        if (!class_exists('GFAPI')) {
            return null; // GF not active
        }

        $form = GFAPI::get_form($form_id);
        if (!$form || empty($form['fields'])) {
            return null;
        }

        $schema = [
            'form_id'              => (int) $form['id'],
            'form_title'           => $form['title'],
            'total_steps'          => 1,
            'steps'                => [],
            'non_data_field_types' => ['page', 'section', 'html'],
            'grid_columns'         => 12,
        ];

        $pageNames = $form['pagination']['pages'] ?? [];

        $current_stepNum = 1;
        $current_step = [
            'step_number' => $current_stepNum,
            'label'       => !empty($pageNames[0]) ? $pageNames[0] : 'Step 1',
            'fields'      => [],
        ];

        foreach ($form['fields'] as $field) {
            if ($field->type === 'page') {
                $schema['steps'][] = $current_step;
                $current_stepNum++;
                $current_step = [
                    'step_number' => $current_stepNum,
                    'label'       => !empty($pageNames[$current_stepNum - 1]) ? $pageNames[$current_stepNum - 1] : (!empty($field->label) ? $field->label : ('Step ' . $current_stepNum)),
                    'fields'      => [],
                ];
                continue;
            }

            $clean_field = [
                'id'                => $field->id,
                'type'              => $field->type,
                'inputType'         => $field->inputType ?? '',
                'label'             => $field->label,
                'is_required'       => (bool) $field->isRequired,
                'is_hidden'         => (bool) ($field->visibility === 'hidden'),
                'visibility'        => $field->visibility ?? 'visible',
                'size'              => $field->size ?? 'large',
                'placeholder'       => $field->placeholder ?? '',
                'css_class'         => $field->cssClass ?? '',
                'choices'           => $field->choices ?? null,
                'conditional_logic' => $field->conditionalLogic ?? null,
                'validation'        => [
                    'isRequired'    => (bool) $field->isRequired,
                    'maxLength'     => $field->maxLength ?? '',
                    'rangeMin'      => $field->rangeMin ?? '',
                    'rangeMax'      => $field->rangeMax ?? '',
                    'customMessage' => $field->errorMessage ?? '',
                    'numberFormat'  => $field->numberFormat ?? 'decimal_dot',
                ],
                'numberFormat'      => $field->numberFormat ?? 'decimal_dot',
                'adminLabel'        => $field->adminLabel ?? '',
                'description'       => $field->description ?? '',
                'defaultValue'      => $field->defaultValue ?? '',
                'content'           => $field->content ?? '',
                'layout'            => $this->extractLayoutData($field),
            ];

            if (!empty($field->enableCalculation) && !empty($field->calculationFormula)) {
                $formula = $field->calculationFormula;
                preg_match_all('/(?:\{[^:}]*:|\{)(\d+(?:\.\d+)?)\}/', $formula, $matches);
                $referencedFields = !empty($matches[1]) ? array_values(array_unique($matches[1])) : [];

                $clean_field['enableCalculation'] = true;
                $clean_field['calculationFormula'] = $formula;
                $clean_field['calculation'] = [
                    'formula'           => $formula,
                    'rounding'          => $field->calculationRounding ?? '',
                    'enableCalculation' => true,
                    'referencedFields'  => $referencedFields,
                ];
            } elseif ($field->type === 'number') {
                 $clean_field['enableCalculation'] = false;
            }

            if ($field->type === 'checkbox' || $field->type === 'radio' || $field->type === 'select') {
               $clean_field['choices'] = array_map(function($choice) {
                   return [
                       'text' => $choice['text'],
                       'value' => $choice['value']
                   ];
               }, $field->choices ?? []);
            }
            if ($field->type === 'fileupload') {
                $clean_field['multipleFiles'] = (bool) $field->multipleFiles;
                $clean_field['maxFiles'] = $field->maxFiles ?? 0;
                $clean_field['maxFileSize'] = $field->maxFileSize ?? 0;
                $clean_field['allowedExtensions'] = $field->allowedExtensions ?? '';
            }

            // Expose inputs natively (e.g. 17.1, 17.2 for checkboxes)
            if (is_array($field->inputs) && !empty($field->inputs)) {
                $clean_field['inputs'] = array_map(function($input) {
                    return [
                        'id' => $input['id'],
                        'label' => $input['label']
                    ];
                }, $field->inputs);
            }

            $current_step['fields'][] = $clean_field;
        }
        
        $schema['steps'][] = $current_step;
        $schema['total_steps'] = count($schema['steps']);

        return $schema;
    }

    /**
     * Extracts grid layout data from a GF field.
     *
     * GF stores layout in a 12-column grid system.
     * layoutGridColumnSpan      – how many of 12 columns this field occupies.
     * layoutSpacerGridColumnSpan – empty spacer columns appended after the field.
     *
     * Example mappings (span → percentage):
     *   12 → 100%   6 → 50%   4 → 33%   3 → 25%   2 → 16.67%
     */
    private function extractLayoutData(object $field): array
    {
        $columnSpan        = isset($field->layoutGridColumnSpan)
            ? (int) $field->layoutGridColumnSpan
            : 12; // default: full width

        $spacerColumnSpan  = isset($field->layoutSpacerGridColumnSpan)
            ? (int) $field->layoutSpacerGridColumnSpan
            : 0;

        // Derive a human-readable percentage width
        $widthPercent = $columnSpan > 0
            ? round(($columnSpan / 12) * 100, 4)
            : 100;

        // Spacer percentage (empty space after field, before next field)
        $spacerPercent = $spacerColumnSpan > 0
            ? round(($spacerColumnSpan / 12) * 100, 4)
            : 0;

        return [
            'columnSpan'        => $columnSpan,
            'spacerColumnSpan'  => $spacerColumnSpan,
            'widthPercent'      => $widthPercent,
            'spacerPercent'     => $spacerPercent,
            'totalColumnsUsed'  => $columnSpan + $spacerColumnSpan,
            'isFullWidth'       => ($columnSpan === 12),
            'cssGridSpan'       => 'col-span-' . $columnSpan,
            'cssWidth'          => $widthPercent . '%',
        ];
    }
}
