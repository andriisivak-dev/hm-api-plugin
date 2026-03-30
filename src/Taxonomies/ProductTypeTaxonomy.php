<?php

declare(strict_types=1);

namespace CSP\Taxonomies;

class ProductTypeTaxonomy extends AbstractTaxonomy
{
    public function get_taxonomy(): string
    {
        return 'hm_product_type';
    }

    public function get_singular_label(): string
    {
        return __('Product Type', 'csp');
    }

    public function get_plural_label(): string
    {
        return __('Product Types', 'csp');
    }
}
