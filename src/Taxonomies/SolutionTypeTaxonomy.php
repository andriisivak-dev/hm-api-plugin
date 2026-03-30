<?php

declare(strict_types=1);

namespace CSP\Taxonomies;

class SolutionTypeTaxonomy extends AbstractTaxonomy
{
    public function get_taxonomy(): string
    {
        return 'hm_solution_type';
    }

    public function get_singular_label(): string
    {
        return __('Solution Type', 'csp');
    }

    public function get_plural_label(): string
    {
        return __('Solution Types', 'csp');
    }
}
