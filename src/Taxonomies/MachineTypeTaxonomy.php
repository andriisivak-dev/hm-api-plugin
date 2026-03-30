<?php

declare(strict_types=1);

namespace CSP\Taxonomies;

class MachineTypeTaxonomy extends AbstractTaxonomy
{
    public function get_taxonomy(): string
    {
        return 'hm_machine_type';
    }

    public function get_singular_label(): string
    {
        return __('Machine Type', 'csp');
    }

    public function get_plural_label(): string
    {
        return __('Machine Types', 'csp');
    }
}
