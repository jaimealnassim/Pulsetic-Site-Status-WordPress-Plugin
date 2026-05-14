<?php
defined( 'ABSPATH' ) || exit;

class PSW_Admin {

    public static function init() {
        add_action( 'admin_menu',            [ __CLASS__, 'add_menu' ] );
        add_action( 'admin_init',            [ __CLASS__, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
    }

    public static function add_menu() {
        add_options_page( __( 'Pulsetic Speed Widget', 'pulsetic-speed-widget' ), __( 'Pulsetic Speed', 'pulsetic-speed-widget' ), 'manage_options', PSW_SLUG, [ __CLASS__, 'render_page' ] );
    }

    public static function register_settings() {
        register_setting( PSW_SLUG, 'psw_settings', [ 'sanitize_callback' => [ __CLASS__, 'sanitize' ] ] );
    }

    public static function sanitize( $input ) {
        return [
            'api_token'        => sanitize_text_field( $input['api_token'] ?? '' ),
            'default_monitor'  => sanitize_text_field( $input['default_monitor'] ?? '' ),
            'cache_seconds'    => max( 10, (int) ( $input['cache_seconds'] ?? 60 ) ),
            'refresh_interval' => max( 15, (int) ( $input['refresh_interval'] ?? 60 ) ),
        ];
    }

    public static function enqueue( $hook ) {
        if ( 'settings_page_' . PSW_SLUG !== $hook ) return;
        wp_enqueue_script( 'psw-admin', PSW_URL . 'assets/js/psw-admin.js', [ 'jquery' ], PSW_VERSION, true );
        wp_localize_script( 'psw-admin', 'pswAdmin', [
            'monitorsUrl' => rest_url( 'psw/v1/monitors' ),
            'clearUrl'    => rest_url( 'psw/v1/clear-cache' ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
        ] );
    }

    public static function render_page() {
        $s = get_option( 'psw_settings', [] );
        // Detect active cache plugins
        $cache_plugins = self::detect_cache_plugins();
        ?>
        <div class="wrap">
            <h1>🐰 <?php esc_html_e( 'Pulsetic Speed Widget', 'pulsetic-speed-widget' ); ?> <span style="font-size:13px;font-weight:400;color:#999">v<?php echo PSW_VERSION; ?></span></h1>
            <p class="description" style="font-size:14px;margin-bottom:20px"><?php esc_html_e( 'API token stored server-side. Frontend only calls your WP REST endpoint — the token is never sent to the browser.', 'pulsetic-speed-widget' ); ?></p>

            <?php if ( isset( $_GET['settings-updated'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'pulsetic-speed-widget' ); ?></p></div>
            <?php endif; ?>

            <?php if ( ! empty( $cache_plugins ) ) : ?>
            <div class="notice notice-info is-dismissible">
                <p><strong>Cache plugin detected:</strong> <?php echo esc_html( implode( ', ', $cache_plugins ) ); ?>.
                The PSW REST endpoints (<code>/wp-json/psw/v1/</code>) are automatically excluded from page caching and JS optimisation. No manual configuration needed.</p>
            </div>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:1fr 360px;gap:32px;align-items:start">
                <div>
                    <form method="post" action="options.php">
                        <?php settings_fields( PSW_SLUG ); ?>
                        <table class="form-table">
                            <tr>
                                <th><label for="psw_token"><?php esc_html_e( 'Pulsetic API Token', 'pulsetic-speed-widget' ); ?></label></th>
                                <td>
                                    <input type="password" id="psw_token" name="psw_settings[api_token]" value="<?php echo esc_attr( $s['api_token'] ?? '' ); ?>" class="regular-text" autocomplete="new-password" placeholder="Your Pulsetic API token" />
                                    <p class="description"><?php printf( wp_kses( __( 'Get your token from <a href="%s" target="_blank">app.pulsetic.com/account/api</a>.', 'pulsetic-speed-widget' ), [ 'a' => [ 'href'=>[], 'target'=>[] ] ] ), 'https://app.pulsetic.com/account/api' ); ?></p>
                                    <?php if ( ! empty( $s['api_token'] ) ) : ?>
                                    <button type="button" id="psw-test" class="button" style="margin-top:8px"><?php esc_html_e( 'Test Connection', 'pulsetic-speed-widget' ); ?></button>
                                    <span id="psw-test-result" style="margin-left:10px;font-size:13px"></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="psw_monitor"><?php esc_html_e( 'Default Monitor', 'pulsetic-speed-widget' ); ?></label></th>
                                <td>
                                    <select id="psw_monitor" name="psw_settings[default_monitor]" class="regular-text">
                                        <option value=""><?php esc_html_e( '— Select a monitor —', 'pulsetic-speed-widget' ); ?></option>
                                        <?php if ( ! empty( $s['default_monitor'] ) ) : ?>
                                        <option value="<?php echo esc_attr( $s['default_monitor'] ); ?>" selected><?php echo esc_html( $s['default_monitor'] ); ?> (saved)</option>
                                        <?php endif; ?>
                                    </select>
                                    <?php if ( ! empty( $s['api_token'] ) ) : ?>
                                    <button type="button" id="psw-load-monitors" class="button" style="margin-left:8px"><?php esc_html_e( 'Load Monitors', 'pulsetic-speed-widget' ); ?></button>
                                    <?php endif; ?>
                                    <p class="description"><?php esc_html_e( 'The site/monitor to pull from when no monitor_id is set in the shortcode. Each shortcode can override this with monitor_id="123".', 'pulsetic-speed-widget' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="psw_cache"><?php esc_html_e( 'Server Cache (seconds)', 'pulsetic-speed-widget' ); ?></label></th>
                                <td>
                                    <input type="number" id="psw_cache" name="psw_settings[cache_seconds]" value="<?php echo esc_attr( $s['cache_seconds'] ?? 60 ); ?>" min="10" max="3600" class="small-text" />
                                    <p class="description"><?php esc_html_e( 'WP transient cache for Pulsetic API responses. Min 10s. Default 60. Does not affect frontend refresh.', 'pulsetic-speed-widget' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="psw_refresh"><?php esc_html_e( 'Widget Refresh (seconds)', 'pulsetic-speed-widget' ); ?></label></th>
                                <td>
                                    <input type="number" id="psw_refresh" name="psw_settings[refresh_interval]" value="<?php echo esc_attr( $s['refresh_interval'] ?? 60 ); ?>" min="15" max="3600" class="small-text" />
                                    <p class="description"><?php esc_html_e( 'How often the browser polls for new data. Min 15s. Default 60.', 'pulsetic-speed-widget' ); ?></p>
                                </td>
                            </tr>
                        </table>
                        <?php submit_button(); ?>
                        <?php if ( ! empty( $s['api_token'] ) ) : ?>
                        <p><button type="button" id="psw-clear-cache" class="button-link button-link-delete"><?php esc_html_e( 'Clear All Cached Data', 'pulsetic-speed-widget' ); ?></button> <span id="psw-clear-result" style="margin-left:10px;font-size:13px"></span></p>
                        <?php endif; ?>
                    </form>
                </div>

                <div style="display:flex;flex-direction:column;gap:20px">

                    <div class="postbox" style="padding:20px">
                        <h3 style="margin-top:0">⚡ Speed Shortcode</h3>
                        <code style="display:block;background:#f0f0f0;padding:8px;border-radius:4px;margin-bottom:12px">[pulsetic_speed]</code>
                        <table style="font-size:12px;width:100%;border-collapse:collapse">
                            <?php foreach(['monitor_id'=>'Monitor ID override','title'=>'Card heading','preview'=>'Rows in card (default 4)','theme'=>'light or dark','show_uptime'=>'Stats row yes/no','show_modal'=>'Modal trigger yes/no','minutes'=>'Lookback window (max 120)','note'=>'* Footnote disclaimer'] as $k=>$v): ?>
                            <tr style="border-bottom:1px solid #f0f0f0"><td style="padding:4px 0"><code><?php echo esc_html($k);?></code></td><td style="padding:4px 0 4px 8px;color:#666"><?php echo esc_html($v);?></td></tr>
                            <?php endforeach; ?>
                        </table>
                    </div>

                    <div class="postbox" style="padding:20px">
                        <h3 style="margin-top:0">📈 Uptime Shortcode</h3>
                        <code style="display:block;background:#f0f0f0;padding:8px;border-radius:4px;margin-bottom:12px">[pulsetic_uptime]</code>
                        <table style="font-size:12px;width:100%;border-collapse:collapse">
                            <?php foreach(['monitor_id'=>'Monitor ID override','days'=>'Lookback days (max 90)','theme'=>'light or dark','label'=>'Heading text','style'=>'card or inline','note'=>'* Footnote disclaimer'] as $k=>$v): ?>
                            <tr style="border-bottom:1px solid #f0f0f0"><td style="padding:4px 0"><code><?php echo esc_html($k);?></code></td><td style="padding:4px 0 4px 8px;color:#666"><?php echo esc_html($v);?></td></tr>
                            <?php endforeach; ?>
                        </table>
                    </div>

                    <div class="postbox" style="padding:20px;background:#f0fff4;border-color:#bbf7d0">
                        <h3 style="margin-top:0;color:#15803d">Cache Compatibility</h3>
                        <p style="font-size:13px;color:#166534;margin-bottom:10px">Real integration via each plugin's own API:</p>
                        <ul style="font-size:12px;color:#166534;padding-left:16px">
                            <li><strong>WP Super Page Cache Pro</strong> — <code>swcfpc_cache_bypass</code> filter for REST bypass + <code>swcfpc_purge_cache</code> action on settings change</li>
                            <li><strong>WP Rocket</strong> — JS asset exclusions from combine &amp; delay</li>
                            <li><strong>LiteSpeed Cache</strong> — JS defer exclusions</li>
                            <li><strong>Autoptimize</strong> — JS combine/defer exclusions</li>
                            <li><strong>All CDNs</strong> — Bunny-Cache-Control, CDN-Cache-Control, X-Accel-Expires no-cache headers on REST responses</li>
                        </ul>
                        <p style="font-size:11px;color:#166534;margin-top:10px;font-style:italic">REST endpoints are already bypassed by SWCFPC at disk-cache level (advanced-cache.php). PSW uses the <code>swcfpc_cache_bypass</code> filter as an explicit integration point.</p>
                        <?php if ( ! empty( $cache_plugins ) ) : ?>
                        <p style="font-size:12px;margin-top:10px;color:#15803d"><strong>Detected active:</strong> <?php echo esc_html( implode( ', ', $cache_plugins ) ); ?></p>
                        <?php endif; ?>
                    </div>

                </div>
            </div>

            <?php if ( ! empty( $s['api_token'] ) && ! empty( $s['default_monitor'] ) ) : ?>
            <hr style="margin:32px 0">
            <h2>🔍 API Debug</h2>
            <p class="description">Shows the raw JSON Pulsetic returns for your monitor. Use this to verify field names if locations or status are not loading correctly.</p>
            <p><button type="button" id="psw-debug-btn" class="button button-secondary">Load Raw API Response</button></p>
            <pre id="psw-debug-out" style="background:#1e1e1e;color:#d4d4d4;padding:20px;border-radius:6px;overflow:auto;max-height:600px;font-size:12px;display:none"></pre>
            <script>
            document.getElementById('psw-debug-btn').addEventListener('click', function() {
                var btn = this;
                btn.textContent = 'Loading...';
                btn.disabled = true;
                fetch('<?php echo esc_url( rest_url( 'psw/v1/debug' ) ); ?>', {
                    headers: { 'X-WP-Nonce': '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>' }
                })
                .then(r => r.json())
                .then(data => {
                    var out = document.getElementById('psw-debug-out');
                    out.textContent = JSON.stringify(data, null, 2);
                    out.style.display = 'block';
                    btn.textContent = 'Reload';
                    btn.disabled = false;
                })
                .catch(e => { btn.textContent = 'Error — check console'; btn.disabled = false; });
            });
            </script>
            <?php endif; ?>

        </div>
        <?php
    }

    /** Detect active cache plugins by checking for known constants/classes/functions */
    private static function detect_cache_plugins() {
        $found = [];
        if ( defined( 'WP_ROCKET_VERSION' ) )          $found[] = 'WP Rocket';
        if ( defined( 'LSWCP_TAG' ) || class_exists( 'LiteSpeed_Cache' ) ) $found[] = 'LiteSpeed Cache';
        if ( defined( 'W3TC' ) )                        $found[] = 'W3 Total Cache';
        if ( function_exists( 'wp_cache_phase1' ) )     $found[] = 'WP Super Cache';
        if ( class_exists( 'WpFastestCache' ) )         $found[] = 'WP Fastest Cache';
        if ( class_exists( 'autoptimizeBase' ) )        $found[] = 'Autoptimize';
        if ( class_exists( 'comet_cache' ) )            $found[] = 'Comet Cache';
        return $found;
    }
}
