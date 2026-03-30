<?php

declare(strict_types=1);

namespace CSP\Taxonomies;

use CSP\PostTypes\CasePostType;

abstract class AbstractTaxonomy
{
    abstract public function get_taxonomy(): string;
    abstract public function get_singular_label(): string;
    abstract public function get_plural_label(): string;

    public function register(): void
    {
        register_taxonomy(
            $this->get_taxonomy(),
            [CasePostType::POST_TYPE],
            [
                'label'             => $this->get_singular_label(),
                'labels'            => [
                    'name'          => $this->get_plural_label(),
                    'singular_name' => $this->get_singular_label(),
                ],
                'public'            => false,
                'show_ui'           => true,
                'show_in_rest'      => true,
                'show_admin_column' => true,
                'hierarchical'      => true,
                'rewrite'           => false,
            ]
        );
    }
}
