<?php
/**
 * CaseMediaHooks
 *
 * Hooks into the WP REST API media upload pipeline to:
 *  1. Route case-study attachments into a dedicated  wp-content/uploads/cases/YYYY/MM/
 *     sub-folder so they are separated from generic site media.
 *  2. Suppress the generation of all intermediate image sizes (thumbnails) for
 *     those attachments — only the original full-size file is kept on disk.
 *
 * Activation is gated on the custom ?hmctx=case_media query parameter that the
 * Vue front-end appends to the /wp/v2/media POST request.  When the parameter is
 * absent the hooks are not registered, so normal site uploads are unaffected.
 *
 * @package CSP\Hooks
 */

declare(strict_types=1);

namespace CSP\Hooks;

if (!defined('ABSPATH')) {
    exit;
}

class CaseMediaHooks
{
    /** Query-string key the front-end sends to activate the case-media pipeline. */
    private const CONTEXT_PARAM = 'hmctx';
    private const CONTEXT_VALUE = 'case_media';

    /** Sub-directory relative to the uploads base dir. */
    private const UPLOAD_SUBDIR = 'cases';

    /** Whether the current REST request targets the case-media pipeline. */
    private bool $isActive = false;

    /** Original upload_dir values to restore after the request. */
    private array $originalUploadDir = [];

    public function register(): void
    {
        // Detect the custom context as early as possible in the REST bootstrap
        add_action('rest_api_init', [$this, 'detectContext'], 5);
    }

    /**
     * Called on rest_api_init — inspect the current request and conditionally
     * activate the media hooks.
     *
     * Note: rest_api_init fires during the REST bootstrap, at which point
     * $_GET is already populated from the request URI.  We read the context
     * param directly from $_GET — no WP_REST_Server method needed.
     */
    public function detectContext(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $context = isset($_GET[self::CONTEXT_PARAM])
            ? sanitize_key(wp_unslash($_GET[self::CONTEXT_PARAM]))
            : '';

        if ($context !== self::CONTEXT_VALUE) {
            return;
        }

        $this->isActive = true;

        // 1. Redirect the upload directory
        add_filter('upload_dir', [$this, 'overrideUploadDir'], 10);

        // 2. Strip all intermediate image sizes before WP processes the attachment
        add_filter('intermediate_image_sizes_advanced', [$this, 'suppressThumbnails'], 10, 2);

        // 3. (optional) Remove big-image scale-down for case uploads so the
        //    original file is stored exactly as-uploaded (no "-scaled" variant).
        add_filter('big_image_size_threshold', '__return_false');
    }

    /**
     * Rewrites the upload directory to  cases/YYYY/MM  inside the normal
     * uploads base directory.  WP automatically appends the /YYYY/MM date
     * segments when `subdir` contains them, so we replicate that pattern.
     *
     * @param  array $dirs  Original upload_dir() result.
     * @return array        Modified dirs with cases sub-folder.
     */
    public function overrideUploadDir(array $dirs): array
    {
        // Build date-based sub-path, matching WP's own logic
        $datePath = '/' . gmdate('Y') . '/' . gmdate('m');
        $casesSubdir = '/' . self::UPLOAD_SUBDIR . $datePath;

        $dirs['subdir'] = $casesSubdir;
        $dirs['path']   = $dirs['basedir'] . $casesSubdir;
        $dirs['url']    = $dirs['baseurl'] . $casesSubdir;

        return $dirs;
    }

    /**
     * Returns an empty array so WordPress does not generate any thumbnail or
     * intermediate image size for case-media attachments.
     *
     * @param  array  $sizes        Map of size-name => dimensions.
     * @param  array  $imageMeta    EXIF/dimension metadata for the uploaded image.
     * @return array                Empty array — no intermediate sizes generated.
     */
    public function suppressThumbnails(array $sizes, array $imageMeta): array
    {
        if (!$this->isActive) {
            return $sizes;
        }

        return [];
    }
}
