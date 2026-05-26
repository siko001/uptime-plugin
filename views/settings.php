<?php
/**
 * Settings page template.
 *
 * @var string $url
 * @var string $secret
 * @var bool   $saved
 * @var string $nonceSave
 * @var string $nonceTest
 * @var string $nonceCheck
 * @var string $saveAction
 * @var string $testAction
 * @var string $checkAction
 */

defined('ABSPATH') || exit;
?>
<div class="wrap">
    <h1>Uptime Monitor</h1>

    <?php if ($saved) : ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>Settings saved.</strong></p>
        </div>
    <?php endif; ?>

    <p>Paste the monitor domain and the Monitor's <code>Update Webhook Secret</code> from the Uptime Monitor app.</p>
    <p class="description">GitHub release updates are enabled for this plugin.</p>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="<?php echo esc_attr($saveAction); ?>" />
        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonceSave); ?>" />

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="panza-um-url">Monitor Domain</label></th>
                <td>
                    <input
                        type="text"
                        id="panza-um-url"
                        name="url"
                        value="<?php echo esc_attr($url); ?>"
                        class="regular-text code"
                        placeholder="https://whitesmoke-camel-166125.hostingersite.com"
                    />
                    <p class="description">Only the monitor domain is required. Endpoint paths are configured by the plugin.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="panza-um-secret">Webhook Secret</label></th>
                <td>
                    <input
                        type="password"
                        id="panza-um-secret"
                        name="secret"
                        value="<?php echo esc_attr($secret); ?>"
                        class="regular-text code"
                        autocomplete="off"
                    />
                    <p class="description">Used to HMAC-sign each request. Must match the Monitor's <code>update_webhook_secret</code>.</p>
                </td>
            </tr>
        </table>

        <p>
            <button type="submit" class="button button-primary">Save Settings</button>
            <button type="button" class="button" id="panza-um-test">Test Connection</button>
            <button type="button" class="button" id="panza-um-check-updates">Check for Updates</button>
            <span id="panza-um-test-result" style="margin-left:8px;"></span>
        </p>
    </form>

    <script>
    (function () {
        const btn = document.getElementById('panza-um-test');
        const checkBtn = document.getElementById('panza-um-check-updates');
        const out = document.getElementById('panza-um-test-result');

        if (btn) btn.addEventListener('click', async () => {
            out.innerHTML = '<em>Testing…</em>';
            btn.disabled = true;

            const body = new URLSearchParams({
                action: <?php echo wp_json_encode($testAction); ?>,
                _ajax_nonce: <?php echo wp_json_encode($nonceTest); ?>,
            });

            try {
                const res  = await fetch(ajaxurl, { method: 'POST', body });
                const data = await res.json();

                if (data.ok) {
                    const info = data.data || {};
                    out.innerHTML = '<strong style="color:#00a32a;">✓ Connected</strong> — Monitor: '
                        + (info.monitor_title || info.monitor_id || 'matched');
                } else {
                    const msg = data.error || (data.raw ? data.raw : 'HTTP ' + (data.status || '?'));
                    out.innerHTML = '<strong style="color:#d63638;">✗ Failed</strong> — ' + msg;
                }
            } catch (e) {
                out.innerHTML = '<strong style="color:#d63638;">✗ Error</strong> — ' + e.message;
            } finally {
                btn.disabled = false;
            }
        });

        if (checkBtn) checkBtn.addEventListener('click', async () => {
            out.innerHTML = '<em>Checking for updates…</em>';
            checkBtn.disabled = true;

            const body = new URLSearchParams({
                action: <?php echo wp_json_encode($checkAction); ?>,
                _ajax_nonce: <?php echo wp_json_encode($nonceCheck); ?>,
            });

            try {
                const res  = await fetch(ajaxurl, { method: 'POST', body });
                const data = await res.json();

                if (data.ok) {
                    const core = data.core ? 'yes' : 'no';
                    out.innerHTML = '<strong style="color:#00a32a;">✓ Updates checked</strong> — Plugins: '
                        + data.plugins + ', Themes: ' + data.themes + ', Core: ' + core;
                } else {
                    out.innerHTML = '<strong style="color:#d63638;">✗ Failed</strong> — '
                        + (data.error || 'Could not check updates.');
                }
            } catch (e) {
                out.innerHTML = '<strong style="color:#d63638;">✗ Error</strong> — ' + e.message;
            } finally {
                checkBtn.disabled = false;
            }
        });
    })();
    </script>
</div>
