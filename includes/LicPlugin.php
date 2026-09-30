<?php

use Automattic\WooCommerce\Utilities\FeaturesUtil;

/**
 * main Plugin class
 */

final class LicPlugin {
	private static ?LicPlugin $instance = null;
    public ?array $plugin_data;
    public string $version;
    public string $key;
    public string $locale;
    public string $name;
    public string $file;
    public string $dir;

    private function __construct() {
		$this->file = wp_normalize_path(WCPUS);
        $this->dir = wp_normalize_path(plugin_dir_path($this->file));
		$this->get_plugin_data();
		$this->includes();
		$this->load_textdomain();
	}

	/**
	 * Setup plugin data
	 */
	public function get_plugin_data() {
		$this->plugin_data = get_file_data( $this->file, [
			'version'     => 'Version',
			'author'      => 'Author',
			'name'        => 'Plugin Name',
			'locale'      => 'Text Domain',
			'description' => 'Description',
			'plugin_url'  => 'Plugin URI',
		] );
		$this->version     = $this->plugin_data["version"];
		$this->key         = $this->plugin_data["locale"];
		$this->locale      = $this->plugin_data["locale"];
		$this->name        = $this->plugin_data["name"];

		return $this->plugin_data;
	}

	/**
	 * Include necessary files
	 */
	private function includes() {
		( new LicDbMigrations() )->add_actions();

		// Get out if WC is not active
		if ( ! function_exists( 'WC' ) || ! class_exists( 'Anyape\UpdatePulse\Server\API\License_API' ) ) {
			return add_action( 'admin_notices', fn() => include_once $this->dir . 'templates/notice-wc-not-found.php' );
		}

		( new LicProduct() )->add_actions(); // No deps
        ( new LicOrder() )->add_actions();
		( new LicOrderMetaBox() )->add_actions();
        ( new LicHelper() )->add_actions();

		LicProlongation::I()->add_actions();
	}

	/**
	 * Internationalization
	 */
	public function load_textdomain() {
		// Load the default language files
		load_plugin_textdomain( 'wc-pus', false, dirname( plugin_basename( $this->file ) ) . '/languages/' );
	}

	/**
	 * @return LicPlugin
	 */
	public static function getInstance(): LicPlugin {
		if ( static::$instance === null ) {
			static::$instance = new self();
		}

		return static::$instance;
	}

	public static function activation() {
		LicDbMigrations::migrate();
	}

	public static function uninstall() {
		// nothing
	}
}
