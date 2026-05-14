<?php
defined( 'ABSPATH' ) || exit;

class PSW_Shortcode {

    public static function init() {
        add_shortcode( 'pulsetic_speed',  [ __CLASS__, 'render_speed' ] );
        add_shortcode( 'pulsetic_uptime', [ __CLASS__, 'render_uptime' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'maybe_enqueue' ] );
    }

    public static function maybe_enqueue() {
        global $post;
        if ( is_a( $post, 'WP_Post' )
            && ( has_shortcode( $post->post_content, 'pulsetic_speed' )
              || has_shortcode( $post->post_content, 'pulsetic_uptime' ) )
        ) {
            self::enqueue_assets();
        }
    }

    public static function enqueue_assets() {
        wp_enqueue_style( 'psw-widget', PSW_URL . 'assets/css/psw-widget.css', [], PSW_VERSION );
        // strategy=defer is intentionally NOT set — our script must run after DOM is ready
        // and must not be combined/deferred by cache plugins (PSW_Cache handles those exclusions)
        wp_enqueue_script( 'psw-widget', PSW_URL . 'assets/js/psw-widget.js', [], PSW_VERSION, true );

        $settings = get_option( 'psw_settings', [] );
        wp_localize_script( 'psw-widget', 'pswConfig', [
            'speedUrl'        => rest_url( 'psw/v1/speed' ),
            'uptimeUrl'       => rest_url( 'psw/v1/uptime' ),
            'historyUrl'      => rest_url( 'psw/v1/speed-history' ),
            'refreshInterval' => (int) ( $settings['refresh_interval'] ?? 60 ),
            'nonce'           => wp_create_nonce( 'wp_rest' ),
        ] );
    }

    /**
     * [pulsetic_speed]
     *
     * monitor_id  — Pulsetic monitor ID (overrides admin default)
     * title       — Card heading (default: "Global Load Times")
     * preview     — Location rows shown in-card (default: 4)
     * theme       — "light" or "dark"
     * show_uptime — Show stats row below rows: yes/no
     * show_modal  — Show "View all" modal trigger: yes/no
     * minutes     — Lookback window in minutes (max 120)
     * note        — Footnote disclaimer e.g. "* Stats for nahnumedia.com"
     */
    public static function render_speed( $atts ) {
        $atts = shortcode_atts( [
            'monitor_id'  => '',
            'title'       => 'Global Load Times',
            'preview'     => 4,
            'theme'       => 'light',
            'show_uptime' => 'yes',
            'show_modal'  => 'yes',
            'minutes'     => 30,
            'note'        => '',
        ], $atts, 'pulsetic_speed' );

        self::enqueue_assets();

        $settings   = get_option( 'psw_settings', [] );
        $monitor_id = ! empty( $atts['monitor_id'] ) ? $atts['monitor_id'] : ( $settings['default_monitor'] ?? '' );
        $preview    = max( 1, (int) $atts['preview'] );
        $modal_id   = 'psw-modal-' . wp_unique_id();
        $theme_cls  = 'dark' === $atts['theme'] ? 'psw-theme-dark' : 'psw-theme-light';

        $data_attrs = implode( ' ', [
            'data-monitor="'     . esc_attr( $monitor_id ) . '"',
            'data-minutes="'     . (int) $atts['minutes'] . '"',
            'data-preview="'     . $preview . '"',
            'data-show-uptime="' . esc_attr( $atts['show_uptime'] ) . '"',
            'data-modal-id="'    . esc_attr( $modal_id ) . '"',
        ] );

        ob_start();
        ?>
        <div class="psw-widget psw-speed <?php echo esc_attr( $theme_cls ); ?>" <?php echo $data_attrs; ?>>

            <div class="psw-card-head">
                <div class="psw-title-group">
                    <span class="psw-title"><?php echo esc_html( $atts['title'] ); ?></span>
                    <span class="psw-title-sub">Updates every 60s</span>
                </div>
                <span class="psw-live-badge">
                    <span class="psw-live-dot"></span>
                    <span class="psw-live-label">Live</span>
                </span>
            </div>

            <div class="psw-regions">
                <?php for ( $i = 0; $i < $preview; $i++ ) : ?>
                <div class="psw-region psw-skeleton"><div class="psw-skeleton-line"></div></div>
                <?php endfor; ?>
            </div>

            <?php if ( 'yes' === $atts['show_modal'] ) : ?>
            <div class="psw-modal-trigger-row">
                <button class="psw-all-btn" data-modal="<?php echo esc_attr( $modal_id ); ?>" disabled>
                    <span class="psw-all-btn-label">View all locations</span>
                    <svg width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                </button>
            </div>
            <?php endif; ?>

            <?php if ( 'yes' === $atts['show_uptime'] ) : ?>
            <div class="psw-stats-row">
                <div class="psw-stat"><div class="psw-stat-num" data-stat="nodes">—</div><div class="psw-stat-label">Locations</div></div>
                <div class="psw-stat"><div class="psw-stat-num" data-stat="status">—</div><div class="psw-stat-label">Status</div></div>
                <div class="psw-stat"><div class="psw-stat-num" data-stat="uptime">—</div><div class="psw-stat-label">Uptime SLA</div></div>
            </div>
            <?php endif; ?>

            <div class="psw-footer">
                <span class="psw-updated">Fetching data…</span>
                <span class="psw-powered">via Pulsetic</span>
            </div>

            <?php if ( ! empty( $atts['note'] ) ) : ?>
            <div class="psw-note"><?php echo esc_html( $atts['note'] ); ?></div>
            <?php endif; ?>

        </div>

        <?php if ( 'yes' === $atts['show_modal'] ) : ?>
        <div class="psw-modal-overlay" id="<?php echo esc_attr( $modal_id ); ?>" aria-hidden="true" role="dialog" aria-modal="true" aria-label="All monitoring locations"
             data-monitor="<?php echo esc_attr( $monitor_id ); ?>"
             data-recent-minutes="<?php echo (int) $atts['minutes']; ?>">
            <div class="psw-modal <?php echo esc_attr( $theme_cls ); ?>">
                <div class="psw-modal-head">
                    <div>
                        <div class="psw-modal-title">All Monitoring Locations</div>
                        <div class="psw-modal-sub" data-modal-sub>Loading…</div>
                    </div>
                    <button class="psw-modal-close" aria-label="Close">
                        <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="psw-modal-toggle-row">
                    <button class="psw-toggle-btn psw-toggle-active" data-view="recent">Recent (<?php echo (int) $atts['minutes']; ?> min)</button>
                    <button class="psw-toggle-btn" data-view="monthly">7-Day Average</button>
                </div>
                <div class="psw-modal-body">
                    <div class="psw-modal-regions" data-modal-regions>
                        <div class="psw-modal-loading">Loading locations…</div>
                    </div>
                </div>
                <?php if ( ! empty( $atts['note'] ) ) : ?>
                <div class="psw-modal-note"><?php echo esc_html( $atts['note'] ); ?></div>
                <?php endif; ?>
                <div class="psw-modal-foot">
                    <span class="psw-modal-updated" data-modal-updated></span>
                    <span class="psw-powered">via Pulsetic</span>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    /**
     * [pulsetic_uptime]
     *
     * monitor_id — Pulsetic monitor ID (overrides admin default)
     * days       — Lookback window in days (max 90, default 30)
     * theme      — "light" or "dark"
     * label      — Heading text (default: "Uptime")
     * style      — "card" or "inline"
     * note       — Footnote disclaimer
     */
    public static function render_uptime( $atts ) {
        $atts = shortcode_atts( [
            'monitor_id' => '',
            'days'       => 30,
            'theme'      => 'light',
            'label'      => 'Uptime',
            'style'      => 'card',
            'note'       => '',
        ], $atts, 'pulsetic_uptime' );

        self::enqueue_assets();

        $settings   = get_option( 'psw_settings', [] );
        $monitor_id = ! empty( $atts['monitor_id'] ) ? $atts['monitor_id'] : ( $settings['default_monitor'] ?? '' );
        $theme_cls  = 'dark' === $atts['theme'] ? 'psw-theme-dark' : 'psw-theme-light';

        $data_attrs = implode( ' ', [
            'data-monitor="' . esc_attr( $monitor_id ) . '"',
            'data-days="'    . (int) $atts['days'] . '"',
        ] );

        if ( 'inline' === $atts['style'] ) {
            ob_start(); ?>
            <span class="psw-uptime-inline <?php echo esc_attr( $theme_cls ); ?>" <?php echo $data_attrs; ?>>
                <span class="psw-uptime-dot" data-uptime-status></span>
                <span class="psw-uptime-pct" data-uptime-pct>—</span>
                <span class="psw-uptime-label"><?php echo esc_html( $atts['label'] ); ?></span>
            </span>
            <?php return ob_get_clean();
        }

        ob_start();
        ?>
        <div class="psw-widget psw-uptime-card <?php echo esc_attr( $theme_cls ); ?>" <?php echo $data_attrs; ?>>

            <div class="psw-card-head">
                <span class="psw-title"><?php echo esc_html( $atts['label'] ); ?></span>
                <span class="psw-live-badge">
                    <span class="psw-live-dot"></span>
                    <span class="psw-live-label">Live</span>
                </span>
            </div>

            <div class="psw-uptime-body">
                <div class="psw-uptime-pct-big" data-uptime-pct>—</div>
                <div class="psw-uptime-meta">
                    <span class="psw-uptime-status-badge" data-uptime-status-badge>Checking…</span>
                    <span class="psw-uptime-period">Last <?php echo (int) $atts['days']; ?> days</span>
                </div>
                <div class="psw-uptime-bar-wrap">
                    <div class="psw-uptime-bar" data-uptime-bar style="width:0%"></div>
                </div>
                <div class="psw-uptime-detail" data-uptime-detail></div>
            </div>

            <div class="psw-footer">
                <span class="psw-updated" data-uptime-updated>Fetching data…</span>
                <span class="psw-powered">via Pulsetic</span>
            </div>

            <?php if ( ! empty( $atts['note'] ) ) : ?>
            <div class="psw-note"><?php echo esc_html( $atts['note'] ); ?></div>
            <?php endif; ?>

        </div>
        <?php
        return ob_get_clean();
    }
}
