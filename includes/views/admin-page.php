<?php
/**
 * Admin settings page view.
 *
 * Available vars (set by Pulsetic_Admin::render_page):
 *   $token, $colors, $defaults, $groups, $monitors, $updated, $mon_js
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div id="pw">
    <h1><span class="hd"></span> Pulsetic Status</h1>
    <p class="pw-sub">Create groups of monitors, set a custom label per site, then use <code>[pulsetic_status]</code>, <code>[pulsetic_cards]</code>, or <code>[pulsetic_bar]</code> with your group slug.</p>

    <?php if ( $updated ) : ?>
    <div class="pnote">✓ Settings saved.</div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'pulsetic_save_settings' ); ?>
        <input type="hidden" name="action" value="pulsetic_save_settings">

        <!-- ① Token -->
        <div class="pc">
            <div class="pch2">① API Token &amp; Scan Interval</div>
            <div class="pf">
                <label for="pat">API Token</label>
                <input type="password" id="pat" name="pulsetic_api_token"
                    value="<?php echo esc_attr( $token ); ?>"
                    placeholder="Paste your Pulsetic API token…"
                    autocomplete="off"/>
                <p class="hint">Get yours at <a href="https://app.pulsetic.com/account/api" target="_blank">app.pulsetic.com/account/api</a>.</p>
            </div>
            <div class="pf">
                <label for="psi">How often to check site status</label>
                <div class="si-wrap">
                    <select id="psi" name="pulsetic_scan_interval" class="si-select">
                        <?php foreach ( $interval_options as $seconds => $label ) : ?>
                        <option value="<?php echo (int) $seconds; ?>" <?php selected( $scan_interval, $seconds ); ?>>
                            <?php echo esc_html( $label ); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="si-hint">
                        <?php
                        $next_refresh = get_option( '_transient_timeout_' . 'pulsetic_monitors_cache', 0 );
                        if ( $next_refresh > time() ) {
                            echo 'Next refresh in <strong>' . esc_html( human_time_diff( time(), $next_refresh ) ) . '</strong>';
                        } else {
                            echo 'Cache will refresh on next page load';
                        }
                        ?>
                    </span>
                </div>
                <p class="hint">How long monitor statuses are cached before the plugin re-checks the Pulsetic API. Shorter = more current data, more API calls. Longer = fewer API calls, slightly staler data.</p>
            </div>
            <?php if ( ! empty( $token ) && is_wp_error( $monitors ) ) : ?>
            <div class="dbg-box"><strong>API error:</strong> <?php echo esc_html( $monitors->get_error_message() ); ?></div>
            <?php endif; ?>
        </div>

        <!-- ② Groups -->
        <div class="pc">
            <div class="pch2">
                ② Monitor Groups
                <?php if ( ! empty( $token ) ) : ?>
                <button type="button" id="prfbtn">↺ Refresh</button>
                <span id="pmstat"></span>
                <?php endif; ?>
            </div>
            <p class="psub">Each group = one shortcode. Check a monitor to include it, then set the label and optional link.</p>

            <?php if ( empty( $token ) ) : ?>
                <p class="mld">Save your API token above first.</p>
            <?php elseif ( is_wp_error( $monitors ) ) : ?>
                <p class="merr">⚠ <?php echo esc_html( $monitors->get_error_message() ); ?></p>
            <?php else : ?>

            <div id="groups-list">
                <?php foreach ( $groups as $gi => $g ) :
                    $g_labels = $g['labels'] ?? [];
                    $g_links  = $g['links']  ?? [];
                ?>
                <div class="group-item" data-index="<?php echo (int) $gi; ?>">
                    <div class="group-header">
                        <span class="group-chevron">▶</span>
                        <input type="text" class="group-name-input"
                            name="pulsetic_groups[<?php echo (int) $gi; ?>][name]"
                            value="<?php echo esc_attr( $g['name'] ); ?>"
                            placeholder="Group name"/>
                        <input type="hidden"
                            name="pulsetic_groups[<?php echo (int) $gi; ?>][id]"
                            class="group-id-field"
                            value="<?php echo esc_attr( $g['id'] ); ?>"/>
                        <span class="group-slug-wrap">
                            <span class="group-slug-label">slug</span>
                            <span class="group-slug-pill"><?php echo esc_html( $g['id'] ); ?></span>
                        </span>
                        <button type="button" class="group-del">✕ Remove</button>
                    </div>
                    <div class="group-body">
                        <div class="group-sc-tabs">
                            <button type="button" class="gst-tab active" data-style="list">List</button>
                            <button type="button" class="gst-tab" data-style="cards">Cards</button>
                            <button type="button" class="gst-tab" data-style="bar">Bar</button>
                        </div>
                        <div class="group-sc" data-slug="<?php echo esc_attr( $g['id'] ); ?>">
                            <span class="gsc-list">[pulsetic_status <span class="at">group</span>=<span class="vl">"<?php echo esc_attr( $g['id'] ); ?>"</span>]</span>
                            <span class="gsc-cards" style="display:none">[pulsetic_cards <span class="at">group</span>=<span class="vl">"<?php echo esc_attr( $g['id'] ); ?>"</span>]</span>
                            <span class="gsc-bar" style="display:none">[pulsetic_bar <span class="at">group</span>=<span class="vl">"<?php echo esc_attr( $g['id'] ); ?>"</span>]</span>
                        </div>
                        <div class="sctr">
                            <button type="button" class="sa" data-a="all">Select all</button>
                            <button type="button" class="sa" data-a="none">Deselect all</button>
                        </div>
                        <div class="mon-list">
                            <?php foreach ( $mon_js as $m ) :
                                $mid    = $m['id'];
                                $ck     = empty( $g['monitors'] ) || in_array( $mid, $g['monitors'], true );
                                $clabel = $g_labels[$mid] ?? '';
                                $clink  = $g_links[$mid]  ?? '';
                            ?>
                            <div class="mon-row <?php echo $ck ? 'ck' : ''; ?>">
                                <label class="mon-check-wrap">
                                    <input type="checkbox"
                                        name="pulsetic_groups[<?php echo (int) $gi; ?>][monitors][]"
                                        value="<?php echo esc_attr( $mid ); ?>"
                                        <?php checked( $ck ); ?>/>
                                    <span class="mdot <?php echo esc_attr( $m['status'] ); ?>"></span>
                                    <span class="mon-info">
                                        <span class="mn"><?php echo esc_html( $m['name'] ); ?></span>
                                        <span class="mu"><?php echo esc_html( $m['url'] ); ?></span>
                                    </span>
                                </label>
                                <div class="mon-label-wrap">
                                    <span>Label:</span>
                                    <input type="text" class="mon-label-input"
                                        name="pulsetic_groups[<?php echo (int) $gi; ?>][labels][<?php echo esc_attr( $mid ); ?>]"
                                        value="<?php echo esc_attr( $clabel ); ?>"
                                        placeholder="<?php echo esc_attr( $m['name'] ); ?>"/>
                                </div>
                                <div class="mon-label-wrap mon-link-wrap">
                                    <span>Link:</span>
                                    <input type="text" class="mon-label-input"
                                        name="pulsetic_groups[<?php echo (int) $gi; ?>][links][<?php echo esc_attr( $mid ); ?>]"
                                        value="<?php echo esc_attr( $clink ); ?>"
                                        placeholder="https://"/>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <br>
            <button type="button" id="add-group-btn">＋ Add Another Group</button>
            <?php endif; ?>
        </div>

        <!-- ③ Colors -->
        <div class="pc">
            <div class="pch2">③ Status Colors <button type="button" class="rcl" id="rcl">Reset to defaults</button></div>
            <p class="psub">Accepts any CSS value: <code>#hex</code>, <code>var(--color-primary)</code>, <code>rgba()</code>, <code>hsl()</code>, named colors, ACSS tokens, etc.</p>
            <div class="cg">
            <?php
            $cgroups = [
                'Online'  => [ 'online_dot' => 'Dot', 'online_badge_bg' => 'Badge background', 'online_badge_text' => 'Badge text' ],
                'Offline' => [ 'offline_dot' => 'Dot', 'offline_badge_bg' => 'Badge background', 'offline_badge_text' => 'Badge text' ],
                'Paused'  => [ 'paused_dot' => 'Dot', 'paused_badge_bg' => 'Badge background', 'paused_badge_text' => 'Badge text' ],
            ];
            foreach ( $cgroups as $cl => $cf ) : ?>
                <div>
                    <h3><?php echo esc_html( $cl ); ?></h3>
                    <?php foreach ( $cf as $k => $fl ) : ?>
                    <div class="cr">
                        <label><?php echo esc_html( $fl ); ?></label>
                        <input class="chx chx-wide" type="text"
                            name="pulsetic_color[<?php echo esc_attr( $k ); ?>]"
                            id="c_<?php echo esc_attr( $k ); ?>"
                            value="<?php echo esc_attr( $colors[$k] ); ?>"
                            data-def="<?php echo esc_attr( $defaults[$k] ); ?>"
                            placeholder="#hex or var(--token)"/>
                        <span class="csw" id="sw_<?php echo esc_attr( $k ); ?>"
                            style="background:<?php echo esc_attr( $colors[$k] ); ?>;"></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            </div>

            <h3 class="preview-label">Live Preview</h3>
            <div class="spv">
                <?php foreach ( [ 'online' => 'app.domain.com', 'offline' => 'api.domain.com', 'paused' => 'cdn.domain.com' ] as $st => $sn ) : ?>
                <div class="spi">
                    <span class="spd" id="pd_<?php echo $st; ?>" style="background:<?php echo esc_attr( $colors[$st . '_dot'] ); ?>;"></span>
                    <span class="spn"><?php echo esc_html( $sn ); ?></span>
                    <span class="spb" id="pb_<?php echo $st; ?>"
                        style="background:<?php echo esc_attr( $colors[$st . '_badge_bg'] ); ?>;color:<?php echo esc_attr( $colors[$st . '_badge_text'] ); ?>;">
                        <?php echo ucfirst( $st ); ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ④ Sizes -->
        <div class="pc">
            <div class="pch2">④ Sizes <button type="button" class="rcl" id="rclsz">Reset to defaults</button></div>
            <p class="psub">Accepts any CSS size value: <code>10px</code>, <code>0.8em</code>, <code>var(--space-xs)</code>, <code>var(--text-xs)</code>, etc.</p>
            <div class="sz-grid">
                <?php
                $size_labels = [
                    'dot_size'        => [ 'Dot size',        'Width/height of the status dot', '10px' ],
                    'item_font_size'   => [ 'Item font size',  'Font size of each row/card/pill', '.94em' ],
                    'badge_font_size'  => [ 'Badge font size', 'Font size of the status badge',  '.73em' ],
                ];
                foreach ( $size_labels as $k => [ $label, $desc, $placeholder ] ) : ?>
                <div class="sz-row">
                    <div class="sz-info">
                        <span class="sz-label"><?php echo esc_html( $label ); ?></span>
                        <span class="sz-desc"><?php echo esc_html( $desc ); ?></span>
                    </div>
                    <input class="chx chx-wide" type="text"
                        name="pulsetic_sizes[<?php echo esc_attr( $k ); ?>]"
                        id="sz_<?php echo esc_attr( $k ); ?>"
                        value="<?php echo esc_attr( $sizes[$k] ); ?>"
                        data-def="<?php echo esc_attr( $size_defaults[$k] ); ?>"
                        placeholder="<?php echo esc_attr( $placeholder ); ?>"/>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- ⑤ Shortcode Reference -->
        <div class="pc">
            <div class="pch2">⑤ Shortcode Reference</div>
            <p class="psub">Three shortcodes, one group slug. Pick the design that fits your layout.</p>

            <div class="sc-ref-grid">

                <div class="sc-ref-block">
                    <div class="sc-ref-tag">List</div>
                    <div class="scbx">[pulsetic_status <span class="at">group</span>=<span class="vl">"my-group"</span>]</div>
                    <p class="sc-ref-desc">Inline list — items sit next to each other, wraps on small screens. Best for sidebars and footers.</p>
                    <table class="widefat striped sc-ref-table">
                        <thead><tr><th>Attribute</th><th>Default</th><th>Notes</th></tr></thead>
                        <tbody>
                            <tr><td><code>group</code></td><td><em>default</em></td><td>Group slug</td></tr>
                            <tr><td><code>refresh_interval</code></td><td><em>60</em></td><td>Seconds between AJAX polls. <code>0</code> = off</td></tr>
                            <tr><td><code>label_online / offline / paused</code></td><td><em>Online…</em></td><td>Override badge text</td></tr>
                            <tr><td><code>show_name</code></td><td><em>true</em></td><td>Show site label</td></tr>
                            <tr><td><code>show_url</code></td><td><em>false</em></td><td>Show raw URL</td></tr>
                            <tr><td><code>show_refresh</code></td><td><em>false</em></td><td>Show cache countdown</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="sc-ref-block">
                    <div class="sc-ref-tag sc-ref-tag--cards">Cards</div>
                    <div class="scbx">[pulsetic_cards <span class="at">group</span>=<span class="vl">"my-group"</span>]</div>
                    <p class="sc-ref-desc">Card grid — each monitor gets a card with a coloured left border. Best for dedicated status pages.</p>
                    <table class="widefat striped sc-ref-table">
                        <thead><tr><th>Attribute</th><th>Default</th><th>Notes</th></tr></thead>
                        <tbody>
                            <tr><td><code>group</code></td><td><em>default</em></td><td>Group slug</td></tr>
                            <tr><td><code>refresh_interval</code></td><td><em>60</em></td><td>Seconds between AJAX polls. <code>0</code> = off</td></tr>
                            <tr><td><code>label_online / offline / paused</code></td><td><em>Online…</em></td><td>Override badge text</td></tr>
                            <tr><td><code>show_name</code></td><td><em>true</em></td><td>Show site label</td></tr>
                            <tr><td><code>show_url</code></td><td><em>false</em></td><td>Show raw URL below name</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="sc-ref-block">
                    <div class="sc-ref-tag sc-ref-tag--bar">Bar</div>
                    <div class="scbx">[pulsetic_bar <span class="at">group</span>=<span class="vl">"my-group"</span>]</div>
                    <p class="sc-ref-desc">Pill row — compact horizontal chips. Best for headers, footers, or inline "all systems go" indicators.</p>
                    <table class="widefat striped sc-ref-table">
                        <thead><tr><th>Attribute</th><th>Default</th><th>Notes</th></tr></thead>
                        <tbody>
                            <tr><td><code>group</code></td><td><em>default</em></td><td>Group slug</td></tr>
                            <tr><td><code>refresh_interval</code></td><td><em>60</em></td><td>Seconds between AJAX polls. <code>0</code> = off</td></tr>
                            <tr><td><code>label_online / offline / paused</code></td><td><em>Online…</em></td><td>Override status text</td></tr>
                            <tr><td><code>show_name</code></td><td><em>true</em></td><td>Show site label inside pill</td></tr>
                            <tr><td><code>show_status</code></td><td><em>false</em></td><td>Show "· Online" text inside pill</td></tr>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>

        <button type="submit" class="psv">Save Settings</button>
    </form>
</div>
