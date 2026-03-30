<?php

declare(strict_types=1);

namespace CSP\Taxonomies;

class ToolBrandTaxonomy extends AbstractTaxonomy
{
    public function get_taxonomy(): string
    {
        return 'hm_tool_brand';
    }

    public function get_singular_label(): string
    {
        return __('Tool Brand', 'csp');
    }

    public function get_plural_label(): string
    {
        return __('Tool Brands', 'csp');
    }
}
