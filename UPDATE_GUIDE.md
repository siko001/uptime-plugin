# Panza Uptime Monitor Plugin Updates

This plugin can receive normal-looking WordPress updates without being listed on wordpress.org. The custom updater is wired to this public GitHub repo:

```text
https://github.com/siko001/uptime-plugin
```

The updater checks the latest GitHub Release and looks for an attached ZIP asset named:

```text
panza-uptime-monitor.zip
```

## Recommended Release Setup

1. Use the dedicated GitHub repository:
   `siko001/uptime-plugin`
2. Keep the plugin folder name stable:
   `panza-uptime-monitor`
3. Keep the main plugin file stable:
   `panza-uptime-monitor.php`
4. Create a Git tag for the version:

   ```bash
   git tag v1.0.1
   git push origin v1.0.1
   ```

5. The GitHub Actions workflow creates/updates the GitHub Release and uploads:

   ```text
   panza-uptime-monitor.zip
   ```

   The ZIP must contain the plugin folder at its root:

   ```text
   panza-uptime-monitor/
     panza-uptime-monitor.php
     src/
     views/
   ```

## How WordPress Plugin Updates Work

WordPress periodically builds an update list using the `site_transient_update_plugins` transient. Plugins from wordpress.org are checked automatically. Private/custom plugins are not.

For this plugin, add a custom updater class that:

1. Reads the installed version from `panza-uptime-monitor.php`.
2. Calls the GitHub Releases API for the latest release.
3. Compares the latest release tag, for example `v1.0.1`, against the installed version.
4. If newer, injects update metadata into `site_transient_update_plugins`.
5. Provides the release ZIP URL as the package download.
6. Optionally fills plugin details in the “View version details” modal.

## Public vs Private Repository

### Public Repo

This is the current setup. The updater calls:

```text
https://api.github.com/repos/siko001/uptime-plugin/releases/latest
```

And download the public release ZIP asset.

### Private Repo

Possible, but the WordPress site needs a GitHub token with read access to the repo. Store that token in a WordPress option or constant, never hard-code it in the plugin.

Example constant:

```php
define('PANZA_UPTIME_MONITOR_GITHUB_TOKEN', 'github_pat_...');
```

The updater must send:

```text
Authorization: Bearer <token>
```

Private repo updates are more fragile because tokens can expire, be revoked, or leak if handled badly.

## Versioning Rule

Use semantic versions:

```text
1.0.0
1.0.1
1.1.0
2.0.0
```

Use Git tags with a `v` prefix:

```text
v1.0.0
v1.0.1
v1.1.0
```

The updater should strip the `v` before comparing versions.

The release workflow stamps the `Version:` header inside the ZIP from the tag name. That means `v1.0.1` produces a plugin ZIP whose header says `Version: 1.0.1`.

## Release Checklist

Before publishing a release:

1. Commit the plugin changes.
2. Create and push the matching Git tag.
3. Let `.github/workflows/release-panza-plugin.yml` build and upload the ZIP.
4. Test from a WordPress site by going to:
   `Dashboard -> Updates`

Example:

```bash
git add panza-uptime-monitor .github/workflows/release-panza-plugin.yml
git commit -m "Update Panza Uptime Monitor plugin"
git tag v1.0.1
git push origin main
git push origin v1.0.1
```

## Important Note

The custom updater is implemented in:

```text
src/Support/GitHubPluginUpdater.php
```

If you change the GitHub repo name, repo owner, plugin slug, or release ZIP filename, update the constants in that class.
