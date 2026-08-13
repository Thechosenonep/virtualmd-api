<?php
/**
 * Plugin Name: VirtualMD Partner Booking API
 * Description: API REST segura para que integraciones autorizadas consulten el catálogo y creen citas en Amelia sin procesar pagos.
 * Version: 1.1.0
 * Author: VirtualMD
 * Requires PHP: 7.4
 * Requires at least: 6.2
 */

namespace VirtualMD\PartnerAPI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'VMDPAPI_VERSION', '1.1.0' );
define( 'VMDPAPI_PLUGIN_FILE', __FILE__ );
define( 'VMDPAPI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VMDPAPI_REST_NAMESPACE', 'virtualmd-partners/v1' );

require_once VMDPAPI_PLUGIN_DIR . 'includes/class-schema.php';
require_once VMDPAPI_PLUGIN_DIR . 'includes/class-auth.php';
require_once VMDPAPI_PLUGIN_DIR . 'includes/class-repository.php';
require_once VMDPAPI_PLUGIN_DIR . 'includes/class-amelia-gateway.php';
require_once VMDPAPI_PLUGIN_DIR . 'includes/class-rest-controller.php';
require_once VMDPAPI_PLUGIN_DIR . 'includes/class-admin.php';
require_once VMDPAPI_PLUGIN_DIR . 'includes/class-cli.php';

register_activation_hook( __FILE__, [ __NAMESPACE__ . '\\Schema', 'activate' ] );
register_deactivation_hook( __FILE__, [ __NAMESPACE__ . '\\Schema', 'deactivate' ] );

/**
 * Load after all plugins so the booking widget's availability helpers exist.
 */
function bootstrap() {
    Schema::maybe_upgrade();
    $schema     = new Schema();
    $repository = new Repository( $schema );
    $auth       = new Auth( $repository );
    $amelia     = new Amelia_Gateway( $repository );
    $controller = new Rest_Controller( $repository, $auth, $amelia );

    add_action( 'rest_api_init', [ $controller, 'register_routes' ] );
    add_action( 'vmdpapi_daily_cleanup', [ $schema, 'cleanup' ] );

    if ( is_admin() ) {
        ( new Admin( $repository ) )->register();
    }

    CLI::register( $repository );
}
add_action( 'plugins_loaded', __NAMESPACE__ . '\\bootstrap', 20 );
