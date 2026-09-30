<?php

use Anyape\UpdatePulse\Server\API\License_API;
use Anyape\UpdatePulse\Server\Manager\Package_Manager;

/**
 * Manager
 */
final class LicOrder
{
    public const key = '_wc_slm_payment_licenses';

    public function __construct()
    {
    }

    public function add_actions()
    {
        // 1 - create and save new keys
        add_action('woocommerce_order_status_changed', [$this, 'create_license_keys'], 20, 4);
        // 2 - add data to email
        add_action('woocommerce_email_before_order_table', [$this, 'email_content'], 10, 2);
        // 3 - call
        add_action('woocommerce_order_details_before_order_table', [$this, 'print_order_meta'], 10, 1);

        // download package
        if (isset($_GET['package_slug'], $_GET['uid'])) {
            LicOrder::download_package();
        }
    }

    /**
     * Generate licences and save to order data
     */
    public function create_license_keys(int $order_id, $old_status, $new_status, ?WC_Order $wc_order = null): void
    {
        if ($new_status !== "completed") {
            return;
        }

        $licenses = [];
        $order = $wc_order ?? wc_get_order($order_id);

        if (!$order instanceof WC_Order) {
            error_log(sprintf('WC PUS could not create licenses: order %d was not found.', $order_id));
            return;
        }

        if (!empty($order->get_meta(self::key))) {
            return;
        }

        $owner = $this->get_owner_data($order);

        if (empty($owner['email'])) {
            self::add_order_note($order_id,
                __('License could not be created: the order has no billing email.', 'wc-pus'));
            return;
        }

        // Generate license keys for all products in order
        foreach ($order->get_items() as $item => $values) {

            if (!$values instanceof WC_Order_Item_Product) {
                continue;
            }

            $lic_key = $values->get_meta('_wc_pus_license');
            $product_id = $values->get_product_id();

            if (false === LicProduct::get_licensing_enabled($product_id) && empty($lic_key)) {
                continue;
            }

            // Works with old license keys

            $old_lic = LicProlongation::I()->get_old_lic_data($lic_key);
            if (!empty($old_lic)) {
                if (LicProlongation::I()->update_old_lic($old_lic)) {
                    $message = esc_html__('License key successfully renewed', 'wc-pus');
                } else {
                    $message = esc_html__('An error occurred while updating the license', 'wc-pus');
                    trigger_error($message);
                }
                self::add_order_note($order_id, $message);
                return;
            }

            $download_quantity = absint($values->get_quantity());
            for ($i = 1; $i <= $download_quantity; $i++) {

                $renewal_period = LicProduct::get_renewal_period($product_id);
                if (0 === $renewal_period) {
                    $renewal_period = date('Y-m-d', strtotime('+99 years'));
                } else {
                    $renewal_period = date('Y-m-d', strtotime('+'.$renewal_period.' years'));
                }

                // Sites allowed
                $sites_allowed = LicProduct::get_sites_allowed($product_id);
                if ($sites_allowed <= 0) {
                    $sites_allowed_error = __('License could not be created: Invalid sites allowed number.', 'wc-pus');
                    $this->add_order_note($order_id, $sites_allowed_error);
                    break;
                }

                $product_slug = LicProduct::get_slug($product_id);
                if (empty($product_slug)) {
                    $this->add_order_note($order_id,
                        __('License could not be created: Invalid product slug.', 'wc-pus'));
                    break;
                }

                $product_type = LicProduct::get_type($product_id);
                if (empty($product_type)) {
                    $this->add_order_note($order_id,
                        __('License could not be created: Invalid product type.', 'wc-pus'));
                    break;
                }

                $product = wc_get_product($product_id);

                if (!$product instanceof WC_Product) {
                    self::add_order_note($order_id, __('License could not be created: product not found.', 'wc-pus'));
                    break;
                }

                $owner_name = trim($owner['first_name'].' '.$owner['last_name']);

                // Build parameters
                $api_params = [];
                $api_params['linknonce'] = wp_create_nonce('linknonce');
                $api_params['wppus_license_action'] = 'create';
                $api_params['page'] = 'wppus-page-licenses';

                $payload = [
                    // default data
                    'license_key'         => bin2hex(openssl_random_pseudo_bytes(16)),
                    'date_created'        => mysql2date('Y-m-d', current_time('mysql'), false),
                    'status'              => 'pending',
                    // setup
                    'max_allowed_domains' => $sites_allowed,
                    'email'               => $owner['email'],
                    'date_renewed'        => mysql2date('Y-m-d', current_time('mysql'), false),
                    'date_expiry'         => $renewal_period,
                    'package_slug'        => $product_slug,
                    'package_type'        => $product_type,
                    'owner_name'          => $owner_name,
                    'company_name'        => $owner['company'],
                    'txn_id'              => (string) $order_id,
                    // custom
                    'first_name'          => $owner['first_name'],
                    'last_name'           => $owner['last_name'],
                ];

                // @see upserv_build_nonce_api_signature()
                $lic_manager = new License_API(); //new WPPUS_License_Server();
                $result = $lic_manager->add($payload);
                $license = $lic_manager->read($payload);

                // Collect license keys
                if (!empty($result) && !empty($license->license_key) && !empty($license->id)) {
                    $licenses[] = [
                        'item'    => $product->get_title(),
                        'key'     => $license->license_key,
                        'expires' => $renewal_period,
                        'lic_id'  => $license->id
                    ];
                } else {
                    $message = __('License Key(s) could not be created.', 'wc-pus');
                    // License_API::add() always returns an object, never an array.
                    $error = $result->errors[0] ?? $result->message ?? '';
                    if (is_array($error)) {
                        $error = reset($error);
                    }
                    if ($error) {
                        $message .= ' ' . $error;
                    }
                    trigger_error($message);
                    self::add_order_note($order_id, $message);
                }
            }
        }


        if (count($licenses) > 0) {
            $wc_order = wc_get_order($order_id);
            $wc_order->add_meta_data(LicOrder::key, $licenses, true);
            $wc_order->update_meta_data(LicOrder::key, $licenses);

            $message = __('License Key(s) generated', 'wc-pus');
            foreach ($licenses as $license) {
                $message .= '<br />'.$license['item'].': '.$license['key'].PHP_EOL;
            }
            self::add_order_note($order_id, $message);
            $wc_order->save();
        }
    }

    /**
     * Billing data stored on the order is the purchase-time source of truth.
     * Customer metadata is only a fallback for incomplete legacy orders.
     *
     * @return array{first_name: string, last_name: string, email: string, company: string}
     */
    private function get_owner_data(WC_Order $order): array
    {
        $owner = [
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
            'email' => $order->get_billing_email(),
            'company' => $order->get_billing_company(),
        ];

        $user_id = $order->get_user_id();

        if (!$user_id) {
            return $owner;
        }

        foreach (array_keys($owner) as $field) {
            if ('' === $owner[$field]) {
                $owner[$field] = (string) get_user_meta($user_id, 'billing_'.$field, true);
            }
        }

        return $owner;
    }

    /**
     * Add note to order
     */
    public static function add_order_note(int $order_id, string $note)
    {
        $order = wc_get_order($order_id);

        if ($order instanceof WC_Order) {
            $order->add_order_note($note);
        }
    }

    /**
     * Add license details to user account details
     *
     * @param  WC_Order|bool|WC_Order_Refund  $order
     */
    public function print_order_meta($order, $show_title = true)
    {
        $wc_order = wc_get_order($order);
        $licenses = $wc_order->get_meta(LicOrder::key);
        $licence_enabled = !empty($licenses) && count($licenses) > 0;

        if (!$licence_enabled) {
            foreach ($order->get_items() as $item) {
                $licence_enabled = '1' === wc_get_product($item->get_product_id())->get_meta('_wc_slm_licensing_enabled');
                if ($licence_enabled) {
                    break;
                }
            }
        }

        if (!$licence_enabled) {
            return;
        }

        $output = '';
        if ($show_title) {
            $output .= '<h2>'.__('Your Licenses', 'wc-pus').':</h2>';
        }
        $output .= '<table class="shop_table shop_table_responsive"><tr>';
        $output .= '<th class="td">'.__('Expires', 'wc-pus').'</th>';
        $output .= '<th class="td">'.__('Key', 'wc-pus').'</th>';
        $output .= '<th class="td">'.__('Domain', 'wc-pus').'</th>';
        $output .= '<th class="td">'.__('Download', 'wc-pus').'</th></tr>';
        //$output = apply_filters('wc_pus_table_header', $output, $licenses);

        if (!empty($licenses)) {
            $output = $this->print_wc_license($output, $licenses,);
        } else {
            // meta not found
            $lic_manager = new License_API();
            $query = [
                'relationship' => 'AND', 'limit' => '0',
                'offset'       => '0',
                'order_by'     => 'date_created',
                'criteria'     => [
                    [
                        'field'    => 'email',
                        'value'    => wp_get_current_user()->user_email,
                        'operator' => '='
                    ],
                ]
            ];
            $licenses = $lic_manager->browse(json_encode($query));
            $output = $this->print_user_license($output, $licenses);
        }
        $output .= '</table>';

        echo apply_filters('wc_pus_table', $output, $licenses, $order);
    }

    public function print_user_license($output, $licenses)
    {

        foreach ((array) $licenses as $key => $lic) {
            if ('count' == $key) {
                continue;
            }
            /**
             * ["id"]=>
             * string(3) "220"
             * ["license_key"]=>
             * string(32) "478d3642d44a35831f948e9446b080b3"
             * ["max_allowed_domains"]=>
             * int(1)
             * ["allowed_domains"]=>
             * array(1) {
             * [0]=>
             * string(19) "dev.yabudupirogi.ru"
             * }
             * ["status"]=>
             * string(9) "activated"
             * ["owner_name"]=>
             * string(31) "Кантемир Татаров"
             * ["email"]=>
             * string(18) "kantemir@danifo.ru"
             * ["company_name"]=>
             * string(0) ""
             * ["txn_id"]=>
             * string(4) "5391"
             * ["date_created"]=>
             * string(10) "2024-12-15"
             * ["date_renewed"]=>
             * string(10) "2024-12-15"
             * ["date_expiry"]=>
             * string(10) "2025-12-15"
             * ["package_slug"]=>
             * string(8) "woo2iiko"
             * ["package_type"]=>
             * string(6) "plugin"
             * ["hmac_key"]=>
             * string(0) ""
             * ["crypto_key"]=>
             * string(0) ""
             * ["data"]=>
             * array(0) {
             * }
             */
            $lic = (array) $lic;

            $lic['download'] = $this->generate_download_link($lic);
            $domain_list = '';
            foreach ($lic['allowed_domains'] as $domain) {
                $domain_list .= '<a href="https://'.$domain.'" target="_blank" class="domain">'.$domain.'</a><br>';
            }

            $output .= '<tr>';
            $output .= '<td class="td">'.$lic['date_expiry'].'</td>';
            $output .= '<td class="td">'.$lic['license_key'].'</td>';
            $output .= '<td class="td">'.$domain_list.'</td>';

            if (strtotime($lic['date_expiry']) > time()) {

                $bs4['class'] = LicProlongation::I()->bs4 ? 'btn btn-primary' : 'button';
                $bs4['icon'] = LicProlongation::I()->bs4 ? '<i class="las la-cloud-download-alt"></i>' : '';

                $output .= '<td class="td"><a href="'.$lic['download'].'" 
					target="_blank" class="'.$bs4['class'].' download">'.$bs4['icon'].$lic['package_slug'].'</a></td>';
            } else {
                $output .= '<td class="td">'.__('Licence has expired', 'wc-pus').'</td>';
            }
            $output .= '</tr>';
            $output .= '<tr><td colspan="2">'.LicProlongation::I()->render_renewal_checkout_link($lic).'</td></tr>';
        }
        return $output;
    }

    public function print_wc_license($output, array $licenses)
    {
        $lic_manager = new License_API();
        foreach ($licenses as $license) {
            $lic = (array) $lic_manager->read(['id' => $license['lic_id'] ?? $license]);
            if (empty($lic)) {
                continue;
            }

            $lic['download'] = $this->generate_download_link($lic);
            $html = '';
            foreach ($lic['allowed_domains'] as $domain) {
                $html .= '<a href="https://'.$domain.'" target="_blank" class="domain">'.$domain.'</a><br>';
            }

            $output .= '<tr>';
            $output .= '<td class="td">'.$lic['date_expiry'].'</td>';
            $output .= '<td class="td">'.$lic['license_key'].'</td>';
            $output .= '<td class="td">'.$html.'</td>';

            if (strtotime($lic['date_expiry']) > time()) {

                $bs4['class'] = LicProlongation::I()->bs4 ? 'btn btn-primary' : 'button';
                $bs4['icon'] = LicProlongation::I()->bs4 ? '<i class="las la-cloud-download-alt"></i>' : '';

                $output .= '<td class="td"><a href="'.$lic['download'].'" 
					target="_blank" class="'.$bs4['class'].' download">'.$bs4['icon'].$lic['package_slug'].'</a></td>';
            } else {
                $output .= '<td class="td">'.__('Licence has expired', 'wc-pus').'</td>';
            }
            $output .= '</tr>';
            $output .= '<tr><td colspan="2">'.LicProlongation::I()->render_renewal_checkout_link($lic).'</td></tr>';
        }
        return $output;
    }

    /**
     * Email
     * @param  WC_Order  $order
     * @param  bool  $is_admin_email
     */
    public function email_content(WC_Order $order, $is_admin_email)
    {
        if ('wc-completed' === $order->get_status() || 'completed' === $order->get_status()) {
            $output = '';

            // Check if licenses were generated
            $licenses = $order->get_meta(LicOrder::key);

            if ($licenses && count($licenses) != 0) {
                $output = '<h3>'.__('Your Licenses', 'wc-pus').':</h3><table><tr>
				<th class="td">'.__('Item', 'wc-pus').'</th>
				<th class="td">'.__('License', 'wc-pus').'</th>
				<th class="td">'.__('Expiry', 'wc-pus').'</th>
				</tr>';
                foreach ($licenses as $license) {
                    $output .= '<tr>';
                    if (isset($license['item']) && isset($license['key'])) {
                        if ($output) {
                            $output .= '<br />';
                        }
                        $output .= '<td class="td">'.$license['item'].'</td>';
                        $output .= '<td class="td">'.$license['key'].'</td>';
                        $output .= '<td class="td">'.$license['expires'].'</td>';
                    } else {
                        // $output .= 'No item and key assigned';
                    }
                    $output .= '</tr>';
                }
                $output .= '</table>';
            } else {
                // $output .= 'No License Generated';
            }

            echo $output;
        }
    }


    /**
     * Render link to download file
     */
    private function generate_download_link(array $lic): string
    {
        $args = [
            'uid'          => get_current_user_id(),
            'package_slug' => $lic['package_slug'],
            'order_id'     => $lic['txn_id'],
            'lic_id'       => $lic['id']
        ];
        return get_home_url().'?'.http_build_query($args);
    }

    /**
     * Get file by link
     *
     * @return void
     */
    public static function download_package(): void
    {
        error_log('Download package initiated');
        error_log('GET params: ' . print_r($_GET, true));

        $user_id = absint($_GET['uid']);
        $package_slug = $_GET['package_slug'];
        $order_id = (absint($_GET['order_id']));
        $lic_id = (absint($_GET['lic_id']));

        if (empty($user_id) || get_current_user_id() !== $user_id ) {
            error_log('User ID mismatch: current='.get_current_user_id().' vs requested='.$user_id);
            self::download_error(__('Invalid download link. Or access denied.', 'woocommerce'));
        }

        if (!is_user_logged_in()) {
            self::download_error(__('You must be logged in to download files.',
                    'woocommerce').' <a href="'.esc_url(wp_login_url(wc_get_page_permalink('myaccount'))).'" class="wc-forward">'.__('Login',
                    'woocommerce').'</a>', __('Log in to Download Files', 'woocommerce'), 403);
        } elseif (get_current_user_id() !== $user_id) {
            self::download_error(__('This is not your download link.', 'woocommerce'), '', 403);
        }

        $lic_manager = new License_API();
        $lic = $lic_manager->read(['id' => $lic_id]);

        if (!$lic || empty($lic->package_slug)) {
            error_log('License not found or invalid: ' . $lic_id);
            self::download_error(__('Invalid license.'), '', 403);
        }

        if (strtotime('midnight', time()) > strtotime($lic->date_expiry)) {
            self::download_error(__('Sorry, this download has expired', 'woocommerce'), '', 403);
        }

        upserv_download_local_package($package_slug);
    }


    /**
     * Die with an error message if the download fails.
     *
     * @param  string  $message  Error message.
     * @param  string  $title  Error title.
     * @param  integer  $status  Error status.
     */
    private static function download_error($message, $title = '', $status = 404)
    {
        /*
         * Since we will now render a message instead of serving a download, we should unwind some of the previously set
         * headers.
         */
        if (headers_sent()) {
            wc_get_logger()->log('warning',
                __('Headers already sent when generating download error message.', 'woocommerce'));
        } else {
            header('Content-Type: '.get_option('html_type').'; charset='.get_option('blog_charset'));
            header_remove('Content-Description;');
            header_remove('Content-Disposition');
            header_remove('Content-Transfer-Encoding');
        }

        if (!strstr($message, '<a ')) {
            $message .= ' <a href="'.esc_url(wc_get_page_permalink('shop')).'" class="wc-forward">'.esc_html__('Go to shop',
                    'woocommerce').'</a>';
        }
        wp_die($message, $title, array('response' => $status)); // WPCS: XSS ok.
    }
}
