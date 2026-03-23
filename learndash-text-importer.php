<?php
/**
 * Plugin Name: LearnDash Text Quiz Importer
 * Description: Crea quizzes de LearnDash a partir de texto estructurado pegado en el administrador.
 * Version: 0.1.0
 * Author: CO360
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: ld-text-importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LTI_PLUGIN_FILE', __FILE__ );
define( 'LTI_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'LTI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'LTI_PLUGIN_VERSION', '0.1.0' );

require_once LTI_PLUGIN_PATH . 'includes/class-lti-import-result.php';
require_once LTI_PLUGIN_PATH . 'includes/class-lti-parser.php';
require_once LTI_PLUGIN_PATH . 'includes/class-lti-validator.php';
require_once LTI_PLUGIN_PATH . 'includes/class-lti-learndash.php';
require_once LTI_PLUGIN_PATH . 'includes/class-lti-admin-page.php';

/**
 * Bootstrap del plugin.
 */
final class LTI_Plugin {
	/**
	 * Instancia singleton.
	 *
	 * @var LTI_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Obtiene la instancia principal.
	 *
	 * @return LTI_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor privado para singleton.
	 */
	private function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init' ) );
	}

	/**
	 * Inicializa servicios.
	 *
	 * @return void
	 */
	public function init() {
		$parser     = new LTI_Parser();
		$validator  = new LTI_Validator();
		$learndash  = new LTI_LearnDash_Service();

		new LTI_Admin_Page( $parser, $validator, $learndash );
	}
}

LTI_Plugin::instance();
