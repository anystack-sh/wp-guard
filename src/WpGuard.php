<?php

namespace Anystack\WpGuard\VDEV;

use Anystack\WPGuard\VDEV\Anystack\Sdk\AnystackApi;
use Anystack\WPGuard\VDEV\Anystack\Sdk\Exceptions\ValidationException;
use InvalidArgumentException;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

require_once __DIR__ . '/../vendor-prefixed/autoload.php';

class WpGuard
{
    protected $baseFile;

    protected array $config = [];

    public function __construct($baseFile, $config = [])
    {
        $this->baseFile = $baseFile;
        $this->config = array_replace_recursive([
            'basename' => basename(plugin_dir_path($baseFile)),
            'pages' => [
                'activate' => __DIR__.'./../pages/activate.php',
            ],
            'license' => [
                'require_email' => false,
            ],
            'updater' => [
                'enabled' => false,
                'api_url' => 'https://dist.anystack.sh/v1',
            ],
        ], $config);

        $this->validateConfig();

        add_action('init', [$this, 'register']);

        $this->registerLicenseCheckCronJob();
    }

    public function getConfig($key, $default = null)
    {
        $array = $this->config;

        if (is_null($key)) {
            return $array;
        }

        if (isset($array[$key])) {
            return $array[$key];
        }

        if (! str_contains($key, '.')) {
            return $array[$key] ?? $default;
        }

        foreach (explode('.', $key) as $segment) {
            if (is_array($array) && array_key_exists($segment, $array)) {
                $array = $array[$segment];
            } else {
                return $default;
            }
        }

        return $array;
    }

    private function validateConfig()
    {
        foreach (['product_name', 'api_key', 'product_id'] as $requiredConfig) {
            if (! isset($this->config[$requiredConfig])) {
                throw new InvalidArgumentException("Missing config: [$requiredConfig]");
            }
        }
    }

    public function isLicenseValid()
    {
        return get_option(sprintf('%s_activated', $this->getConfig('basename')));
    }

    private function activate($key, $email = null, $fingerprint = null)
    {
        update_option(sprintf('%s_activated', $this->getConfig('basename')), true);

        foreach (['key', 'email', 'fingerprint'] as $option) {
            if ($$option) {
                update_option(sprintf('%s_license_%s', $this->getConfig('basename'), $option), $$option);
            }
        }
    }

    private function deactivate()
    {
        delete_option(sprintf('%s_activated', $this->getConfig('basename')));

        foreach (['key', 'email', 'fingerprint'] as $option) {
            delete_option(sprintf('%s_license_%s', $this->getConfig('basename'), $option));
        }
    }

    public function register()
    {
        add_action(sprintf('%s_verify_license_hook', $this->getConfig('basename')), [$this, 'licenseValidationHook']);

        if (! is_admin()) {
            return;
        }

        if ($this->isLicenseValid()) {
            if ($this->getConfig('updater.enabled')) {
                $this->registerUpdater();
            }

            return;
        }

        $this->registerPluginActivationPage();
        $this->registerPluginActivationLink();
        $this->registerLicenseActivationPostHandler();
    }

    public function licenseValidationHook()
    {
        if (! function_exists('get_plugin_data')) {
            require_once ABSPATH.'wp-admin/includes/plugin.php';
        }

        $api = new AnystackApi($this->getConfig('api_key'));

        $key = get_option(sprintf('%s_license_key', $this->getConfig('basename')));

        $scope = [
            'fingerprint' => get_option(sprintf('%s_license_fingerprint', $this->getConfig('basename'))),
            'release' => [
                'tag' => get_plugin_data($this->baseFile)['Version'],
            ],
        ];

        if ($this->getConfig('license.require_email')) {
            $scope['contact']['email'] = get_option(sprintf('%s_license_email', $this->getConfig('basename')));
        }

        $validateResponse = $api->product($this->getConfig('product_id'))
            ->licenses()
            ->validate([
                'key' => $key,
                'scope' => $scope,
            ]);

        if ($validateResponse->ok() && $validateResponse->json('meta.valid') === false) {
            $this->deactivate();
        }
    }

    private function registerUpdater()
    {
        require __DIR__.'/../plugin-update-checker/plugin-update-checker.php';

        $data = array_filter([
            'key' => get_option(sprintf('%s_license_key', $this->getConfig('basename'))),
            'email' => get_option(sprintf('%s_license_email', $this->getConfig('basename'))),
            'fingerprint' => get_option(sprintf('%s_license_fingerprint', $this->getConfig('basename'))),
        ]);

        if (empty($data) === true) {
            return;
        }

        PucFactory::buildUpdateChecker(
            sprintf(
                '%s/php/wordpress-plugin/%s/update-check.json?%s',
                $this->getConfig('updater.api_url'),
                $this->getConfig('product_id'),
                http_build_query($data)
            ),
            $this->baseFile
        );
    }

    public function handleLicenseActivation()
    {
        $nonce = sprintf('activate_%s_nonce', $this->getConfig('basename'));

        if (! isset($_POST[$nonce]) || ! wp_verify_nonce($_POST[$nonce], $nonce)) {
            wp_die('Invalid nonce');
        }

        $key = sanitize_text_field($_POST['license-key'] ?? null);
        $email = sanitize_text_field($_POST['email'] ?? null);
        $site = get_site_url();
        $host = parse_url($site, PHP_URL_HOST);
        $fingerprint = preg_replace('/^www\./', '', $host);

        $api = new AnystackApi($this->getConfig('api_key'));

        try {
            $scope = [
                'fingerprint' => $fingerprint,
                'release' => [
                    'tag' => get_plugin_data($this->baseFile)['Version'],
                ],
            ];

            if ($this->getConfig('license.require_email')) {
                $scope['contact']['email'] = $email;
            }

            $validateResponse = $api->product($this->getConfig('product_id'))
                        ->licenses()
                        ->validate([
                            'key' => $key,
                            'scope' => $scope,
                        ]);

            if ($validateResponse->json('meta.valid') === true || $validateResponse->json('meta.status') === 'RESTRICTED') {
                $this->activate($key, $email, $fingerprint);
                $this->throwSuccessMessage(__('Your license has been restored.'));
            } elseif ($validateResponse->json('meta.status') === 'FINGERPRINT_INVALID') {
                $response = $api->product($this->getConfig('product_id'))
                        ->licenses()
                        ->activate(array_filter([
                            'key' => $key,
                            'fingerprint' => $fingerprint,
                            'ip' => $_SERVER['SERVER_ADDR'],
                        ]));

                if ($response->successful()) {
                    $this->activate($key, $email, $fingerprint);
                    $this->throwSuccessMessage(__('Your license has been activated.'));
                }
            } else {
                $errorMessages = [
                    'NOT_FOUND' => __('Your license information did not match our records.'),
                    'SUSPENDED' => __('Your license has been suspended.'),
                    'EXPIRED' => __('Your license has been expired.'),
                    'MAX_USAGE_REACHED' => __('Your license has reached its activation limit.'),
                    'RELEASE_CONSTRAINT' => __('Your license has no access to this version.'),
                ];

                $errorMessage = isset($errorMessages[$validateResponse->json('meta.status')]) ? $errorMessages[$validateResponse->json('meta.status')] : __('Something went wrong: ').$validateResponse->json('meta.status');

                $this->throwErrorMessage($errorMessage);
            }
        } catch (ValidationException $e) {
            $list = implode(' ', array_map(function ($value, $key) {
                $errors = implode(', ', $value);

                return "<li>$key: $errors</li>";
            }, $e->getErrors(), array_keys($e->getErrors())));

            $message = __("Unable to activate your license: <ul>$list</ul>");

            $this->throwErrorMessage($message);
        }

        wp_redirect(admin_url(sprintf('admin.php?page=%s-activate', $this->getConfig('basename'))));

        exit;
    }

    public function validCallback($closure)
    {
        if ($this->isLicenseValid()) {
            return $closure();
        }

        return false;
    }

    public function displayLicenseActivationPage()
    {
        include $this->getConfig('pages.activate');
    }

    private function throwSuccessMessage($message, $title = 'License activated')
    {
        wp_die("<h3>$title</h3>".$message, $title, [
            'link_url' => admin_url('plugins.php'),
            'link_text' => sprintf(__('Start using %s'), $this->getConfig('product_name')),
        ]);
    }

    private function throwErrorMessage($message, $title = 'Activation failed')
    {
        wp_die("<h3>$title</h3>".$message, $title, [
            'link_url' => admin_url(sprintf('admin.php?page=%s-activate', $this->getConfig('basename'))),
            'link_text' => __('Click here to try again.'),
        ]);
    }

    private function registerPluginActivationPage(): void
    {
        add_action('admin_menu', function () {
            add_plugins_page(
                sprintf('Activate %s', $this->getConfig('product_name')),
                sprintf('Activate %s', $this->getConfig('product_name')),
                'manage_options',
                sprintf('%s-activate', $this->getConfig('basename')),
                [$this, 'displayLicenseActivationPage']
            );
        });
    }

    private function registerPluginActivationLink(): void
    {
        add_filter('plugin_action_links_'.plugin_basename($this->baseFile), function ($links) {
            $url = admin_url(sprintf('admin.php?page=%s-activate', $this->getConfig('basename')));
            $settings_link = "<a href='$url'>".__('Activate license').'</a>';

            array_push(
                $links,
                $settings_link
            );

            return $links;
        });
    }

    private function registerLicenseActivationPostHandler(): void
    {
        add_action(sprintf('admin_post_activate_license_%s', $this->getConfig('basename')),
            [$this, 'handleLicenseActivation']);
    }

    private function registerLicenseCheckCronJob(): void
    {
        register_activation_hook($this->baseFile, function () {
            $hook = sprintf('%s_verify_license_hook', $this->getConfig('basename'));
            if (! wp_next_scheduled($hook)) {
                wp_schedule_event(time(), 'hourly', $hook);
            }
        });

        register_deactivation_hook($this->baseFile, function () {
            $hook = sprintf('%s_verify_license_hook', $this->getConfig('basename'));

            wp_clear_scheduled_hook($hook);
        });
    }
}
