<?php
/** Local release metadata; no remote requests or automatic updater. */
defined('ABSPATH') || exit;

final class YUZ_Release {
    public static function render(): void {
        $file = defined('YUZ_TRA_PLUGIN_FILE') ? YUZ_TRA_PLUGIN_FILE : dirname(__DIR__) . '/yuz-tra.php';
        $header = get_file_data($file, ['version' => 'Version', 'description' => 'Description'], 'plugin');
        $path = dirname($file) . '/version.json';
        $manifest = is_readable($path) ? json_decode(file_get_contents($path), true) : null;
        $coherent = is_array($manifest) && isset($manifest['version'], $manifest['repository'])
            && $manifest['version'] === $header['version']
            && preg_match('/^\d+\.\d+\.\d+$/D', $header['version'])
            && $manifest['repository'] === 'https://github.com/Youzurz/yuz-tra-wp';
        $repo = 'https://github.com/Youzurz/yuz-tra-wp';
        $release = $coherent ? $repo . '/releases/tag/v' . $header['version'] : $repo . '/releases';
        $download = $coherent ? $repo . '/releases/download/v' . $header['version'] . '/yuz-tra-' . $header['version'] . '.zip' : $repo . '/releases';
        echo '<section class="yuz-release-card" aria-label="' . esc_attr__('Version and downloads', 'yuz-tra') . '" style="margin:16px 0;padding:20px;border:1px solid #c3d4ee;border-radius:12px;background:#fff;color:#17243d">';
        echo '<h2 style="margin-top:0">YUZ-TRA · ' . esc_html__('Installed version', 'yuz-tra') . ' <strong data-yuz-installed-version>' . esc_html($header['version']) . '</strong></h2>';
        echo '<p>' . esc_html__('Visual translation and WordPress string catalog: edit, review and publish.', 'yuz-tra') . '</p>';
        echo '<p>' . esc_html__('Open-source release. Back up and test on staging before updating.', 'yuz-tra') . '</p>';
        if (!$coherent) {
            echo '<p role="alert"><strong>' . esc_html__('Version metadata mismatch: download links use the release list. This installation must be checked.', 'yuz-tra') . '</strong></p>';
        }
        $provenancePath = dirname($file) . '/build-provenance.json';
        $build = is_readable($provenancePath) ? json_decode(file_get_contents($provenancePath), true) : null;
        if ($coherent && is_array($build) && ($build['version'] ?? '') === $header['version'] && preg_match('/^[a-f0-9]{40}$/D', $build['source_commit'] ?? '')) {
            echo '<p>' . esc_html__('Declared source commit', 'yuz-tra') . ': <a href="' . esc_url($repo . '/commit/' . $build['source_commit']) . '"><code>' . esc_html(substr($build['source_commit'], 0, 12)) . '</code></a>. ' . esc_html__('This identifies the build; it is not a runtime file-integrity scan.', 'yuz-tra') . '</p>';
        }
        echo '<nav aria-label="' . esc_attr__('YUZ-TRA resources', 'yuz-tra') . '" style="display:flex;flex-wrap:wrap;gap:8px">';
        $links = [
            [$download, $coherent ? __('Download this version (ZIP)', 'yuz-tra') : __('Published downloads', 'yuz-tra')],
            [$release, __('Release notes', 'yuz-tra')],
            ['https://youzurz.com/yuz-tra/documentation/', __('Installation and guide', 'yuz-tra')],
            ['https://youzurz.com/yuz-tra/support/', __('Bugs, features and help', 'yuz-tra')],
            ['https://youzurz.com/yuz-tra/pricing/', __('Free features and costs', 'yuz-tra')],
            ['https://youzurz.com/yuz-tra/privacy/', __('Privacy', 'yuz-tra')],
        ];
        foreach ($links as $index => [$url, $label]) {
            echo '<a class="button ' . ($index === 0 ? 'button-primary' : 'button-secondary') . '" target="_blank" rel="noopener noreferrer" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav><p class="description">' . esc_html__('No paid activation or Pro plan is currently sold with this package. Provider and hosting charges are separate.', 'yuz-tra') . '</p></section>';
    }
}
