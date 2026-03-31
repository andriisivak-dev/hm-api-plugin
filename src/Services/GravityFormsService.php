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
        ];

        $current_stepNum = 1;
        $current_step = [
            'step_number' => $current_stepNum,
            'label'       => '',
            'fields'      => [],
        ];

        foreach ($form['fields'] as $field) {
            if ($field->type === 'page') {
                $schema['steps'][] = $current_step;
                $current_stepNum++;
                $current_step = [
                    'step_number' => $current_stepNum,
                    'label'       => '', // Page field itself doesn't always have a strict label, but we can look for it if needed
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
                    'is_required' => (bool) $field->isRequired,
                    'max_length'  => $field->maxLength ?? '',
                ],
                'numberFormat'      => $field->numberFormat ?? 'decimal_dot',
                'adminLabel'        => $field->adminLabel ?? '',
                'description'       => $field->description ?? '',
                'defaultValue'      => $field->defaultValue ?? '',
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
}
