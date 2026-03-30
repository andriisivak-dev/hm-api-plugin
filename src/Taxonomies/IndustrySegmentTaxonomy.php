<?php

declare(strict_types=1);

namespace CSP\Taxonomies;

class IndustrySegmentTaxonomy extends AbstractTaxonomy
{
    public function get_taxonomy(): string
    {
        return 'hm_industry_segment';
    }

    public function get_singular_label(): string
    {
        return __('Industry Segment', 'csp');
    }

    public function get_plural_label(): string
    {
        return __('Industry Segments', 'csp');
    }
}
