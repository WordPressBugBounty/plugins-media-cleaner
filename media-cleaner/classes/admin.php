<?php

class Meow_WPMC_Admin extends MeowKit_WPMC_Admin {

  private $core = null;

  public function __construct( $core ) {
    $this->core = $core;
    parent::__construct( WPMC_PREFIX, WPMC_ENTRY, WPMC_DOMAIN, class_exists( 'MeowPro_WPMC_Core' ) );
    add_action( 'admin_menu', array( $this, 'app_menu' ) );

    // Load the scripts only if they are needed by the current screen
    $page = isset( $_GET["page"] ) ? sanitize_text_field( $_GET["page"] ) : '';
    $is_wpmc_screen = in_array( $page, [ 'wpmc_dashboard', 'wpmc_settings', 'wpmc_storage' ] );
    $is_meowapps_dashboard = $page === 'meowapps-main-menu';
    if ( $is_meowapps_dashboard || $is_wpmc_screen ) {
      add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
    }
  }

  function admin_enqueue_scripts() {

    // Load the scripts
    // Both bundles share one version, so it follows whichever changed last: a change that only
    // touches the vendor bundle (NekoUI) would otherwise keep its old copy in the browser cache.
    $bundles = array_filter( array( WPMC_PATH . '/app/index.js', WPMC_PATH . '/app/vendor.js' ), 'file_exists' );
    $cache_buster = $bundles ? max( array_map( 'filemtime', $bundles ) ) : WPMC_VERSION;
    wp_register_script( 'wpmc_media_cleaner-vendor', WPMC_URL . 'app/vendor.js',
      ['wp-element', 'wp-i18n'], $cache_buster
    );
    wp_register_script( 'wpmc_media_cleaner', WPMC_URL . 'app/index.js',
      ['wpmc_media_cleaner-vendor', 'wp-i18n'], $cache_buster
    );
    if ( function_exists( 'wp_set_script_translations' ) ) {
      wp_set_script_translations( 'wpmc_media_cleaner', 'media-cleaner' );
    }
    wp_enqueue_script('wpmc_media_cleaner' );

    // Load the fonts
    wp_register_style( 'meow-neko-ui-lato-font', '//fonts.googleapis.com/css2?family=Lato:wght@100;300;400;700;900&display=swap');
    wp_enqueue_style( 'meow-neko-ui-lato-font' );

    // Options
    $options = array_merge( $this->core->get_all_options(), [
      'incompatible_plugins' => Meow_WPMC_Support::get_issues(),
      'native_plugins'       => Meow_WPMC_Support::get_natives(),
      'scan_progress'        => $this->core->get_progress(),
    ] );

    // Localize and options
    wp_localize_script( 'wpmc_media_cleaner', 'wpmc_media_cleaner', [
      'api_url' => rest_url( 'media-cleaner/v1' ),
      'rest_url' => rest_url(),
      'admin_url' => admin_url(),
      'plugin_url' => WPMC_URL,
      'prefix' => WPMC_PREFIX,
      'domain' => WPMC_DOMAIN,
      'is_pro' => class_exists( 'MeowPro_WPMC_Core' ),
      'is_registered' => !!$this->is_registered(),
      // A newer Pro version this inactive license cannot download, set by common/premium/renewal.php.
      'renewal' => apply_filters( 'wpmc_meowapps_renewal', null ),
      // Installed vs latest version for the header line, also from common/premium/renewal.php.
      'version_info' => apply_filters( 'wpmc_meowapps_version_info', null ),
      'rest_nonce' => wp_create_nonce( 'wp_rest' ),
      'cleanup_allowed' => $this->core->runs ? $this->core->runs->cleanup_allowed() : false,
      'results_method' => $this->core->runs ? $this->core->runs->results_method() : null,
			'schema_ready' => $this->core->runs ? $this->core->runs->tables_exist() : false,
      // Decides whether the scan needs its translation-resolving step at all.
      'is_multilingual' => $this->core->is_multilingual(),
      'options' => $options
    ] );
  }

  function app_menu() {
    if ( !$this->core->can_access_settings() ) {
      return;
    }
    if ( !$this->core->can_access_features() ) {
      add_submenu_page( 'meowapps-main-menu', 'Media Cleaner', 'Media Cleaner', 'read', 'wpmc_settings',
        array( $this, 'admin_settings' )
      );
      return;
    }
    // Under Meow Apps, Media Cleaner opens its Dashboard, the main screen where scanning happens:
    // people landing on Settings could not find the Scan button. Settings becomes a hidden page
    // (no parent) so admin.php?page=wpmc_settings keeps working for every link to it. Unlisting a
    // submenu entry instead, or moving it under Media, makes WordPress refuse that URL.
    $hook = add_submenu_page( '', 'Media Cleaner', 'Media Cleaner', 'read', 'wpmc_settings', array( $this, 'admin_settings' ) );
    add_submenu_page( 'meowapps-main-menu', 'Media Cleaner', 'Media Cleaner', 'read', 'upload.php?page=wpmc_dashboard' );
    // WordPress finds no title for a hidden page, which leaves the browser tab without one.
    add_action( 'load-' . $hook, function () {
      $GLOBALS['title'] = 'Media Cleaner';
    } );
  }

  public function admin_settings() {
    if ( !$this->core->can_access_settings() ) {
      return;
    }
    echo '<div id="wpmc-admin-settings"></div>';
  }

	function is_pro_user() {
		return class_exists( 'MeowPro_WPMC_Core' ) && !!$this->is_registered();
	}

}

?>
