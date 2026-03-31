<?php

declare(strict_types=1);

namespace CSP\Config;

return [

    // -------------------------------------------------------------------------
    // TAXONOMY FIELDS — value drives a WP taxonomy term
    // -------------------------------------------------------------------------

    [
        'field_id'  => 7,
        'gf_type'   => 'radio',
        'label'     => 'Product Type',
        'storage'   => ['taxonomy' => 'hm_product_type', 'meta_key' => null],
        'display'   => ['in_list' => true, 'in_card' => true, 'in_filters' => true],
        'sync_on'   => 'submit',
    ],
    [
        'field_id'  => 8,
        'gf_type'   => 'select',
        'label'     => 'Industry Segment',
        'storage'   => ['taxonomy' => 'hm_industry_segment', 'meta_key' => null],
        'display'   => ['in_list' => true, 'in_card' => true, 'in_filters' => true],
        'sync_on'   => 'submit',
    ],
    [
        'field_id'  => 126,
        'gf_type'   => 'select',
        'label'     => 'Machine Type',
        'storage'   => ['taxonomy' => 'hm_machine_type', 'meta_key' => null],
        'display'   => ['in_list' => true, 'in_card' => true, 'in_filters' => true],
        'sync_on'   => 'submit',
    ],
    [
        'field_id'  => 227,
        'gf_type'   => 'text',
        'label'     => 'Machine Make',
        'storage'   => ['taxonomy' => 'hm_machine_make', 'meta_key' => null],
        'display'   => ['in_list' => true, 'in_card' => true, 'in_filters' => true],
        'sync_on'   => 'submit',
    ],
    [
        'field_id'  => 229,
        'gf_type'   => 'text',
        'label'     => 'Tool Brand',
        'storage'   => ['taxonomy' => 'hm_tool_brand', 'meta_key' => null],
        'display'   => ['in_list' => true, 'in_card' => true, 'in_filters' => true],
        'sync_on'   => 'submit',
    ],
    [
        'field_id'  => 20,
        'gf_type'   => 'radio',
        'label'     => 'Solution Type',
        'storage'   => ['taxonomy' => 'hm_solution_type', 'meta_key' => null],
        'display'   => ['in_list' => true, 'in_card' => true, 'in_filters' => true],
        'sync_on'   => 'submit',
    ],

    // -------------------------------------------------------------------------
    // META FILTER FIELDS — value written to dedicated post_meta key
    // -------------------------------------------------------------------------

    [
        'field_id'  => 100,
        'gf_type'   => 'text',
        'label'     => 'Customer Name',
        'storage'   => ['taxonomy' => null, 'meta_key' => '_case_customer_name'],
        'display'   => ['in_list' => false, 'in_card' => true, 'in_filters' => false],
        'sync_on'   => 'always',           // Update on every partial save (drives case title)
    ],
    [
        'field_id'  => 99,
        'gf_type'   => 'text',
        'label'     => 'Customer ID',
        'storage'   => ['taxonomy' => null, 'meta_key' => '_case_customer_id'],
        'display'   => ['in_list' => false, 'in_card' => false, 'in_filters' => false],
        'sync_on'   => 'always',
    ],
    [
        'field_id'  => 2,
        'gf_type'   => 'text',
        'label'     => 'City',
        'storage'   => ['taxonomy' => null, 'meta_key' => '_case_city'],
        'display'   => ['in_list' => false, 'in_card' => true, 'in_filters' => false],
        'sync_on'   => 'submit',
    ],
    [
        'field_id'  => 4,
        'gf_type'   => 'text',
        'label'     => 'State',
        'storage'   => ['taxonomy' => null, 'meta_key' => '_case_state'],
        'display'   => ['in_list' => false, 'in_card' => true, 'in_filters' => false],
        'sync_on'   => 'submit',
    ],

];
