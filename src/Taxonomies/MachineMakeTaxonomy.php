<?php

declare(strict_types=1);

namespace CSP\Taxonomies;

class MachineMakeTaxonomy extends AbstractTaxonomy
{
    public function get_taxonomy(): string
    {
        return 'hm_machine_make';
    }

    public function get_singular_label(): string
    {
        return __('Machine Make', 'csp');
    }

    public function get_plural_label(): string
    {
        return __('Machine Makes', 'csp');
    }
}
