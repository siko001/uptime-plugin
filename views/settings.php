<?php
/**
 * Settings page template.
 *
 * @var string $url
 * @var string $secret
 * @var bool   $saved
 * @var string $nonceSave
 * @var string $nonceTest
 * @var string $saveAction
 * @var string $testAction
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

    <p>Paste the webhook URL and the Monitor's <code>Update Webhook Secret</code> from the Uptime Monitor app.</p>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="<?php echo esc_attr($saveAction); ?>" />
        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonceSave); ?>" />

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="panza-um-url">Webhook URL</label></th>
                <td>
                    <input
                        type="url"
                        id="panza-um-url"
                        name="url"
                        value="<?php echo esc_attr($url); ?>"
                        class="regular-text code"
                        placeholder="https://uptime.example.com/api/webhooks/wp-update"
                    />
                    <p class="description">Full URL of the webhook endpoint for this monitor.</p>
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
            <span id="panza-um-test-result" style="margin-left:8px;"></span>
        </p>
    </form>

    <script>
    (function () {
        const btn = document.getElementById('panza-um-test');
        const out = document.getElementById('panza-um-test-result');
        if (!btn) return;

        btn.addEventListener('click', async () => {
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
    })();
    </script>
</div>
