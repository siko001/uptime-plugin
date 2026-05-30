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

    <p>Paste the monitor domain and the Monitor's <code>Webhook Secret</code> from the Uptime Monitor app.</p>
    <p class="description">GitHub release updates are enabled for this plugin.</p>

    <form method="post" action="<?= esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="<?= esc_attr($saveAction); ?>" />
        <input type="hidden" name="_wpnonce" value="<?= esc_attr($nonceSave); ?>" />

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="atx-um-url">Monitor Domain</label></th>
                <td>
                    <input
                        type="text"
                        id="atx-um-url"
                        name="url"
                        value="<?= esc_attr($url); ?>"
                        class="regular-text code"
                        placeholder="https://whitesmoke-camel-166125.hostingersite.com"
                    />
                    <p class="description">Enter the monitor domain.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="atx-um-secret">Webhook Secret</label></th>
                <td>
                    <input
                        type="password"
                        id="atx-um-secret"
                        name="secret"
                        value="<?= esc_attr($secret); ?>"
                        class="regular-text code"
                        autocomplete="off"
                    />
                    <p class="description">Copy the Webhook Secret from the Monitor -> Integrations tab.</p>
                </td>
            </tr>
        </table>

        <p>
            <button type="submit" class="button button-primary">Save Settings</button>
            <button type="button" class="button" id="atx-um-test">Test Connection</button>
            <button type="button" class="button" id="atx-um-check-updates">Check for Updates</button>
            <span id="atx-um-test-result" style="margin-left:8px;"></span>
        </p>
    </form>

    <script>
    (function () {
        const btn = document.getElementById('atx-um-test');
        const checkBtn = document.getElementById('atx-um-check-updates');
        const out = document.getElementById('atx-um-test-result');

        if (btn) btn.addEventListener('click', async () => {
            out.innerHTML = '<em>Testing…</em>';
            btn.disabled = true;

            const body = new URLSearchParams({
                action: <?= wp_json_encode($testAction); ?>,
                _ajax_nonce: <?= wp_json_encode($nonceTest); ?>,
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
                action: <?= wp_json_encode($checkAction); ?>,
                _ajax_nonce: <?= wp_json_encode($nonceCheck); ?>,
            });

            try {
                const res  = await fetch(ajaxurl, { method: 'POST', body });
                const data = await res.json();

                if (data.ok) {
                    const message = data.update_available
                        ? 'Update available: ' + data.installed_version + ' → ' + data.latest_version
                        : 'Already up to date at version ' + data.installed_version;
                    out.innerHTML = '<strong style="color:#00a32a;">✓ Plugin checked</strong> — ' + message;
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
