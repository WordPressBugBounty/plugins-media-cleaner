<?php

class MeowKit_WPMC_Rest {
  private $namespace = 'meow-common/v1';
  public static $instance = null;

  public static function init_once() {
    if ( !MeowKit_WPMC_Rest::$instance ) {
      MeowKit_WPMC_Rest::$instance = new self();
    }
  }

  private function __construct() {
    add_action( 'rest_api_init', [ $this, 'rest_api_init' ] );
  }

  /**
   * Capability gate for plugin admin endpoints. Defaults to manage_options but
   * honours a per-plugin filter so a site can grant access to other roles
   * without re-implementing every permission_callback. The filter name is
   * derived from the class name (`MeowKit_<PREFIX>_Rest` => `<prefix>_allow_setup`),
   * so each plugin gets its own filter automatically after Nekofy substitution.
   */
  private function can_setup() {
    static $filter = null;
    if ( $filter === null ) {
      $parts = explode( '_', __CLASS__ );
      $prefix = isset( $parts[1] ) ? strtolower( $parts[1] ) : '';
      $filter = $prefix !== '' ? $prefix . '_allow_setup' : '';
    }
    $default = current_user_can( 'manage_options' );
    return $filter !== '' ? apply_filters( $filter, $default ) : $default;
  }

  public function rest_api_init() {
    if ( !$this->can_setup() ) {
      return;
    }
    $permission = function () {
      return $this->can_setup();
    };
    register_rest_route( $this->namespace, '/empty_request/', [
      'methods' => 'POST',
      'permission_callback' => $permission,
      'callback' => [ $this, 'empty_request' ]
    ] );
    register_rest_route( $this->namespace, '/file_operation/', [
      'methods' => 'POST',
      'permission_callback' => $permission,
      'callback' => [ $this, 'file_operation' ]
    ] );
    register_rest_route( $this->namespace, '/sql_request/', [
      'methods' => 'POST',
      'permission_callback' => $permission,
      'callback' => [ $this, 'sql_request' ]
    ] );
    register_rest_route( $this->namespace, '/error_logs/', [
      'methods' => 'POST',
      'permission_callback' => $permission,
      'callback' => [ $this, 'rest_error_logs' ]
    ] );
    register_rest_route( $this->namespace, '/all_settings/', [
      'methods' => 'POST',
      'permission_callback' => $permission,
      'callback' => [ $this, 'rest_all_settings' ]
    ] );
    register_rest_route( $this->namespace, '/update_option/', [
      'methods' => 'POST',
      'permission_callback' => $permission,
      'callback' => [ $this, 'rest_update_option' ]
    ] );
    register_rest_route( $this->namespace, '/installed_plugins/', [
      'methods' => 'POST',
      'permission_callback' => $permission,
      'callback' => [ $this, 'rest_installed_plugins' ]
    ] );
    // The analysis needs PHP for one reason: $mwai is a PHP global, so the AI
    // call cannot be made from the dashboard's JavaScript. Everything it
    // analyses is gathered in the browser and posted here.
    register_rest_route( $this->namespace, '/analysis_status/', [
      'methods' => 'POST',
      'permission_callback' => $permission,
      'callback' => [ $this, 'rest_analysis_status' ]
    ] );
    register_rest_route( $this->namespace, '/analysis_run/', [
      'methods' => 'POST',
      'permission_callback' => $permission,
      'callback' => [ $this, 'rest_analysis_run' ]
    ] );
    register_rest_route( $this->namespace, '/analysis_forget/', [
      'methods' => 'POST',
      'permission_callback' => $permission,
      'callback' => [ $this, 'rest_analysis_forget' ]
    ] );
  }

  /**
   * Throw away the stored analysis and the consent that went with it.
   *
   * The analysis writes a verdict about this site into the options table and
   * keeps it. That is what makes it survive a reload, but it also means a
   * description of your server's weaknesses, written by a third-party model,
   * sits there indefinitely with nothing to remove it. Somebody should be able
   * to take it back, and clearing the consent means the panel explaining what
   * gets sent is shown again before anything is sent a second time.
   */
  public function rest_analysis_forget() {
    delete_option( 'meowapps_analysis_last' );
    delete_option( 'meowapps_analysis_consented' );
    return new WP_REST_Response( [ 'success' => true ], 200 );
  }

  /**
   * Whether an analysis can be run, and the last one if there is one.
   *
   * Three states matter and they are not the same: AI Engine absent, AI Engine
   * present but with no API key configured, and ready. Telling the second from
   * the first is what lets the dashboard say "finish setting it up" rather than
   * "install this", which would be wrong and mildly insulting.
   */
  public function rest_analysis_status() {
    global $mwai;
    $installed = !empty( $mwai );
    $ready = $installed && method_exists( $mwai, 'hasAI' ) && $mwai->hasAI();
    return new WP_REST_Response( [ 'success' => true, 'data' => [
      'installed' => $installed,
      'ready' => $ready,
      'consented' => (bool) get_option( 'meowapps_analysis_consented', false ),
      'last' => get_option( 'meowapps_analysis_last', null ),
    ] ], 200 );
  }

  public function rest_analysis_run( $request ) {
    global $mwai;
    if ( empty( $mwai ) || !method_exists( $mwai, 'simpleJsonQuery' ) ) {
      return new WP_REST_Response( [ 'success' => false,
        'message' => 'AI Engine is not available on this site.' ], 200 );
    }
    if ( !method_exists( $mwai, 'hasAI' ) || !$mwai->hasAI() ) {
      return new WP_REST_Response( [ 'success' => false,
        'message' => 'AI Engine has no AI environment configured yet.' ], 200 );
    }

    $params = $request->get_json_params();
    $facts = isset( $params['facts'] ) ? $params['facts'] : [];

    // Consent is recorded when a run is actually asked for, so the panel is
    // shown once rather than on every visit.
    update_option( 'meowapps_analysis_consented', '1' );

    try {
      $reply = $mwai->simpleJsonQuery( $this->analysis_prompt( $facts ) );
    }
    catch ( Exception $e ) {
      return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 200 );
    }

    $verdict = is_string( $reply ) ? json_decode( $reply, true ) : $reply;
    if ( empty( $verdict ) || !is_array( $verdict ) ) {
      return new WP_REST_Response( [ 'success' => false,
        'message' => 'The AI reply could not be read as JSON.' ], 200 );
    }

    $verdict['ranAt'] = current_time( 'mysql' );
    update_option( 'meowapps_analysis_last', $verdict );

    return new WP_REST_Response( [ 'success' => true, 'data' => $verdict ], 200 );
  }

  /**
   * Written for the person who owns the site, not for a developer: somebody who
   * installed a plugin, not somebody who knows what max_execution_time is.
   *
   * Meow Apps plugins may be named as a remedy, but only where one genuinely
   * fixes the finding. An analysis that recommends its own author's plugins for
   * everything is an advert, and would be read as one.
   */
  private function analysis_prompt( $facts ) {
    $json = wp_json_encode( $facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
    return "You are reviewing a WordPress site's health for the person who owns it.\n"
      . "They are not a developer: explain what something means and why it matters before saying "
      . "what to do, and never assume they know what a PHP directive is.\n\n"
      . "Here is what was measured on their site:\n\n$json\n\n"
      . "Reply with JSON in exactly this shape:\n"
      . "{\n"
      . '  "areas": [ { "area": "Speed|Environment|Errors", "rating": 1-5, '
      . '"label": "a two or three word verdict", "summary": "one plain sentence" } ],' . "\n"
      . '  "findings": [ { "area": "Speed|Environment|Errors", "severity": "high|medium|low", '
      . '"title": "the recommendation itself, plain, under 60 characters", '
      . '"detail": "at most two short sentences on what it means and why it matters", '
      . '"action": "one sentence saying what to do", '
      . '"plugin": "meow plugin slug or null" } ],' . "\n"
      . '  "conclusion": "two short sentences tying it together"' . "\n"
      . "}\n\n"
      . "Rules:\n"
      . "- Rate each of the three areas from 1 (bad) to 5 (good). Do not invent an overall score.\n"
      . "- Only report findings genuinely worth acting on. An empty findings list is a perfectly "
      . "good answer for a healthy site. Do not manufacture problems.\n"
      . "- Order findings by how much they matter, worst first.\n"
      . "- Be brief. The reader sees the titles first and opens the ones they care about, so a "
      . "title has to work on its own and the detail must not repeat it. No preamble, no restating "
      . "the question, no filler.\n"
      . "- Set \"plugin\" to one of these Meow Apps slugs ONLY when that plugin genuinely fixes the "
      . "finding, otherwise null: ai-engine, code-engine, contact-form-block, database-cleaner, "
      . "media-cleaner, media-file-renamer, meow-gallery, meow-lightbox, meow-mailer, seo-engine, "
      . "social-engine, wp-retina-2x, wplr-sync.\n"
      . "- If an error in the log comes from a specific plugin, say which one.\n"
      . "- Write in the language of this site: " . get_bloginfo( 'language' ) . ".";
  }

  public function file_rand( $filesize ) {
    // Write the benchmark file inside a dedicated subfolder of the uploads
    // directory (created on demand), then remove it. wp.org forbids writing to
    // the plugin folder or the uploads root, only a sanctioned subfolder.
    $upload = wp_upload_dir();
    if ( !empty( $upload['error'] ) || empty( $upload['basedir'] ) ) { return; }
    $dir = trailingslashit( $upload['basedir'] ) . 'meowapps';
    if ( !wp_mkdir_p( $dir ) ) { return; }
    $path = trailingslashit( $dir ) . 'speedtest-' . wp_generate_password( 12, false ) . '.tmp';
    $fh = @fopen( $path, 'wb' );
    if ( $fh === false ) { return; }
    fseek( $fh, $filesize - 1, SEEK_CUR );
    fwrite( $fh, 'a' );
    fclose( $fh );
    @unlink( $path );
  }

  public function empty_request() {
    return new WP_REST_Response( [ 'success' => true ], 200 );
  }

  public function file_operation() {
    $this->file_rand( 1024 * 10 );
    return new WP_REST_Response( [ 'success' => true ], 200 );
  }

  public function sql_request() {
    global $wpdb;
    $count = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" );
    return new WP_REST_Response( [ 'success' => true, 'data' => $count ], 200 );
  }

  // List all the options with their default values.
  public function list_options() {
    return [
      'meowapps_hide_meowapps' => false,
      'force_sslverify' => false
    ];
  }

  public function get_all_options() {
    $options = $this->list_options();
    $current_options = [];
    foreach ( $options as $option => $default ) {
      $current_options[$option] = get_option( $option, $default );
    }
    return $current_options;
  }

  public function rest_all_settings() {
    return new WP_REST_Response( [ 'success' => true, 'data' => $this->get_all_options() ], 200 );
  }

  public function rest_installed_plugins() {
    if ( !function_exists( 'get_plugins' ) ) {
      require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    $all_plugins = get_plugins();
    $result = [];
    foreach ( $all_plugins as $plugin_file => $plugin_data ) {
      // Plugin file looks like "ai-engine/ai-engine.php", the slug is the
      // first path segment. Some plugins live at the root (single file),
      // those we just skip; we only care about Meow Apps directories anyway.
      $parts = explode( '/', $plugin_file );
      if ( count( $parts ) < 2 ) {
        continue;
      }
      $slug = $parts[0];
      $result[ $slug ] = is_plugin_active( $plugin_file ) ? 'active' : 'inactive';
    }
    return new WP_REST_Response( [ 'success' => true, 'data' => $result ], 200 );
  }

  public function rest_update_option( $request ) {
    $params = $request->get_json_params();
    try {
      $name = $params['name'];
      $options = $this->list_options();
      if ( !array_key_exists( $name, $options ) ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => 'This option does not exist.' ], 200 );
      }
      $value = is_bool( $params['value'] ) ? ( $params['value'] ? '1' : '' ) : $params['value'];
      $success = update_option( $name, $value );
      if ( !$success ) {
        return new WP_REST_Response( [ 'success' => false, 'message' => 'Could not update option.' ], 200 );
      }
      return new WP_REST_Response( [ 'success' => true, 'data' => $value ], 200 );
    }
    catch ( Exception $e ) {
      return new WP_REST_Response( [ 'success' => false, 'message' => $e->getMessage() ], 500 );
    }
  }

  public function rest_error_logs( $request ) {
    return new WP_REST_Response( [ 'success' => true, 'data' => MeowKit_WPMC_Helpers::php_error_logs() ], 200 );
  }

}
