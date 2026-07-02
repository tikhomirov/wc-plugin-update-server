<?php

use Anyape\UpdatePulse\Server\Manager\Data_Manager;

/**
 * Get plugin info by slug for showing in any site part.
 *
 * How to use: [plugin_info slug="plugin_slug"]
 */
class LicHelper
{

    /**
     * Shortcode for showing plugin info to product page or somewhere...
     */
    public function add_actions()
    {
        add_shortcode('plugin_info', [$this, 'render_html']);
    }

    public function render_html($atts)
    {

        $atts = shortcode_atts([
            'slug' => '',
            'data' => 'change_log'
        ], $atts, 'plugin_info');

        ob_start();
        $info = self::get_package($atts['slug']);
        echo $info[$atts['data']] ?? '<pre> - </pre>';
        return ob_get_clean();
    }


    /**
     * Getting all package or package info by slug. Data gonna from file cache and will be db cache for 3 hours.
     *
     * @param  string|null  $slug  package slug
     * @return array|null
     */
    public static function get_package(string $slug = null): ?array
    {
        WP_Filesystem();

        global $wp_filesystem;

        if (!$wp_filesystem || !isset($slug)) {
            return null;
        }

        $meta = self::get_package_by_path($slug);

        $packages[$slug] = [
            'name'               => $meta['name'] ?? '',
            'version'            => $meta['version'] ?? '',
            'type'               => $meta['type'] ?? '',
            'last_updated'       => $meta['last_updated'] ?? '',
            'file_name'          => $meta['file_name'] ?? '',
            'file_size'          => isset($meta['file_size']) ? number_format($meta['file_size'] / (1024 * 1024), 2, '.', '').' MB' : '',
            'file_last_modified' => isset($meta['file_last_modified']) ? date_i18n('d F Y H:i:s', $meta['file_last_modified']) : '',
            'slug'               => $meta['slug'] ?? $slug,
            'tested'             => $meta['tested'] ?? '',
            'change_log'         => $meta['sections']['changelog'] ?? '',
            'description'        => $meta['sections']['description'] ?? '',
        ];

        if (!empty($slug) && key_exists($slug, $packages)) {
            return $packages[$slug];
        }

        return !empty($packages) ? $packages : null;
    }


    public static function get_package_by_path(string $slug): array
    {
        $package = new \Anyape\UpdatePulse\Server\API\Package_API();
        return (array) $package->read($slug, 'plugin');
    }

    /**
     * Get type
     * @return array
     */
    public static function get_types($package_type = null)
    {
        $types = [
            'plugin' => esc_html__('Plugin', 'wc-pus'),
            'theme'  => esc_html__('Theme', 'wc-pus'),
        ];
        return !empty($package_type) ? $types[$package_type] : $package_type;
    }
}