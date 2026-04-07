<?php

declare(strict_types=1);

namespace CSP\Admin\Customers;

use CSP\Repositories\CustomerRepository;

/**
 * Gravity Forms integration: autocomplete, location pre-fill, validation.
 *
 * Port of gravity-populate.php from hemant-core plugin.
 *
 * Field IDs and form ID are kept identical to the original implementation.
 *
 * @package CSP\Admin\Customers
 */
class CustomerGravityForms
{
    // GF configuration — mirrors original constants
    private const GF_TARGET_FORM_ID    = 4;   // Target Gravity Form ID
    private const GF_CLIENT_TEXT_FIELD = 100; // Visible text field (Customer Name)
    private const GF_CLIENT_ID_FIELD   = 99;  // Hidden field storing client DB id
    private const GF_CITY_FIELD        = 2;   // City
    private const GF_STATE_FIELD       = 4;   // State

    public function register(): void
    {
        add_action('gform_enqueue_scripts', [$this, 'enqueueAutocomplete'], 10, 2);
        add_action('wp_ajax_csp_search_clients', [$this, 'ajaxSearchClients']);
        add_action('wp_ajax_nopriv_csp_search_clients', [$this, 'ajaxSearchClients']);
        add_action('wp_ajax_csp_get_client_location', [$this, 'ajaxGetClientLocation']);
        add_action('wp_ajax_nopriv_csp_get_client_location', [$this, 'ajaxGetClientLocation']);
        add_filter('gform_validation', [$this, 'validateClientAutocomplete']);
        add_filter('gform_pre_submission_filter', [$this, 'enforceClientLocationOnSubmit']);
    }

    // -------------------------------------------------------------------------
    // Enqueue autocomplete script
    // -------------------------------------------------------------------------

    public function enqueueAutocomplete(array $form, bool $isAjax): void
    {
        $targetFormId = self::GF_TARGET_FORM_ID;
        if ($targetFormId > 0 && (int) $form['id'] !== $targetFormId) {
            return;
        }

        wp_enqueue_script('jquery-ui-autocomplete');
        wp_enqueue_style(
            'jquery-ui-autocomplete-css',
            'https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css',
            [],
            '1.13.2'
        );

        $ajaxUrl = admin_url('admin-ajax.php');
        $formId  = (int) $form['id'];
        $textId  = self::GF_CLIENT_TEXT_FIELD;
        $hidId   = self::GF_CLIENT_ID_FIELD;
        $cityId  = self::GF_CITY_FIELD;
        $stateId = self::GF_STATE_FIELD;

        ?>
        <script>
        window.addEventListener('DOMContentLoaded', function () {
            if (typeof jQuery === 'undefined') { return; }
            jQuery(function ($) {
                const formId       = <?php echo (int) $formId; ?>;
                const textFieldId  = <?php echo (int) $textId; ?>;
                const hiddenFieldId = <?php echo (int) $hidId; ?>;
                const cityFieldId  = <?php echo (int) $cityId; ?>;
                const stateFieldId = <?php echo (int) $stateId; ?>;
                const ajaxUrl      = <?php echo wp_json_encode($ajaxUrl); ?>;

                const $textInput   = $('#input_' + formId + '_' + textFieldId);
                const $hiddenInput = $('#input_' + formId + '_' + hiddenFieldId);
                const $cityInput   = $('#input_' + formId + '_' + cityFieldId);
                const $stateInput  = $('#input_' + formId + '_' + stateFieldId);

                if (!$textInput.length || !$hiddenInput.length || !$cityInput.length || !$stateInput.length || typeof $textInput.autocomplete !== 'function') {
                    return;
                }

                function setLocationReadOnly(readOnly) {
                    $cityInput.prop('readonly', readOnly);
                    $stateInput.prop('readonly', readOnly);
                    $cityInput.toggleClass('is-readonly', readOnly);
                    $stateInput.toggleClass('is-readonly', readOnly);
                }

                function applyClientLocation(city, state) {
                    const cityVal  = (city  || '').toString().trim();
                    const stateVal = (state || '').toString().trim();
                    const hasFullLocation = cityVal !== '' && stateVal !== '';
                    $cityInput.val(cityVal);
                    $stateInput.val(stateVal);
                    setLocationReadOnly(hasFullLocation);
                }

                function resetLocationToManual() {
                    setLocationReadOnly(false);
                    $cityInput.val('');
                    $stateInput.val('');
                }

                function clearHidden() { $hiddenInput.val(''); }

                $textInput.on('input', function () {
                    clearHidden();
                    resetLocationToManual();
                });

                $textInput.autocomplete({
                    minLength: 3,
                    delay: 300,
                    source: function (request, response) {
                        $.getJSON(ajaxUrl, { action: 'csp_search_clients', term: request.term })
                            .done(function (data) {
                                response($.map(data || [], function (item) {
                                    return {
                                        label: item.company_name,
                                        value: item.company_name,
                                        id:    item.id,
                                        city:  item.city  || '',
                                        state: item.state || ''
                                    };
                                }));
                            })
                            .fail(function () { response([]); });
                    },
                    select: function (event, ui) {
                        if (ui && ui.item && ui.item.id) {
                            $hiddenInput.val(ui.item.id);
                            applyClientLocation(ui.item.city, ui.item.state);
                        } else {
                            clearHidden();
                            resetLocationToManual();
                        }
                    },
                    change: function (event, ui) {
                        if (!ui || !ui.item) {
                            clearHidden();
                            resetLocationToManual();
                        }
                    }
                });

                // Pre-load location when editing a case with a pre-selected client
                (function preloadLocationForSelectedClient() {
                    const selectedId = parseInt(($hiddenInput.val() || '').toString(), 10);
                    if (!selectedId) { return; }

                    $.getJSON(ajaxUrl, { action: 'csp_get_client_location', id: selectedId })
                        .done(function (data) {
                            if (!data || data.success !== true || !data.data) { return; }
                            applyClientLocation(data.data.city || '', data.data.state || '');
                        });
                })();
            });
        });
        </script>
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX: search clients (used by autocomplete widget)
    // -------------------------------------------------------------------------

    public function ajaxSearchClients(): void
    {
        global $wpdb;

        $term = isset($_GET['term']) ? sanitize_text_field((string) $_GET['term']) : '';
        if (mb_strlen($term) < 2) {
            wp_send_json([]);
        }

        $table = $wpdb->prefix . 'csp_clients';

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, company_name, city, state
                 FROM {$table}
                 WHERE company_name LIKE %s
                 ORDER BY company_name ASC
                 LIMIT 20",
                '%' . $wpdb->esc_like($term) . '%'
            ),
            ARRAY_A
        );

        wp_send_json($results);
    }

    // -------------------------------------------------------------------------
    // AJAX: get client city/state for pre-fill
    // -------------------------------------------------------------------------

    public function ajaxGetClientLocation(): void
    {
        $id = isset($_GET['id']) ? absint($_GET['id']) : 0;
        if ($id <= 0) {
            wp_send_json_error(['message' => 'Invalid client id.'], 400);
        }

        $client = CustomerRepository::getById($id);
        if (!$client) {
            wp_send_json_error(['message' => 'Client not found.'], 404);
        }

        wp_send_json_success([
            'city'  => trim((string) ($client->city ?? '')),
            'state' => trim((string) ($client->state ?? '')),
        ]);
    }

    // -------------------------------------------------------------------------
    // Validation: require a client to be selected from the dropdown
    // -------------------------------------------------------------------------

    public function validateClientAutocomplete(array $result): array
    {
        $formId = isset($result['form']['id']) ? (int) $result['form']['id'] : 0;

        $targetFormId = self::GF_TARGET_FORM_ID;
        if ($targetFormId > 0 && $formId !== $targetFormId) {
            return $result;
        }

        $hiddenInputName = 'input_' . self::GF_CLIENT_ID_FIELD;

        if (empty($_POST[$hiddenInputName])) {
            $result['is_valid'] = false;

            foreach ($result['form']['fields'] as &$field) {
                if ((int) $field->id === self::GF_CLIENT_TEXT_FIELD) {
                    $field->failed_validation  = true;
                    $field->validation_message = __('Please select a client from the list.', 'hm-case-study-api');
                }
            }
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Pre-submission: overwrite city/state with authoritative values from DB
    // -------------------------------------------------------------------------

    public function enforceClientLocationOnSubmit(array $form): array
    {
        $formId = isset($form['id']) ? (int) $form['id'] : 0;
        $targetFormId = self::GF_TARGET_FORM_ID;

        if ($targetFormId > 0 && $formId !== $targetFormId) {
            return $form;
        }

        $clientSelector = isset($_POST['input_' . self::GF_CLIENT_ID_FIELD])
            ? trim((string) wp_unslash($_POST['input_' . self::GF_CLIENT_ID_FIELD]))
            : '';

        if ($clientSelector === '' || !ctype_digit($clientSelector)) {
            return $form;
        }

        $client = CustomerRepository::getById((int) $clientSelector);
        if (!$client) {
            return $form;
        }

        $city  = trim((string) ($client->city ?? ''));
        $state = trim((string) ($client->state ?? ''));

        // Lock fields to source-of-truth values only when both are present
        if ($city !== '' && $state !== '') {
            $_POST['input_' . self::GF_CITY_FIELD]  = $city;
            $_POST['input_' . self::GF_STATE_FIELD] = $state;
        }

        return $form;
    }
}
