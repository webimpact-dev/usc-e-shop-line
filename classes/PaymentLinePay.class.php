<?php
/**
 * LINE Pay Settlement module
 *
 * @version 1.0.0
 * @author webimpact
 */

/**
 * LINE Pay Settlement class
 */
class LINEPAY_SETTLEMENT
{
    protected static $instance = null;

    protected $payment_id;
    protected $pay_method;
    protected $error_mes;

    /**
     * 入金通知URL(デバッグ用)
     * falseを指定で home_url(/?acting=linepay') でサイトトップページが指定される
     */
    // const CONFIRM_URL = 'https://example.jp/?acting=linepay';
    const CONFIRM_URL = false;

    /**
     * LINE PayサーバIP制限リスト
     * false指定でチェック無効
     */
    // const ACL = false;
    const ACL = array(
         // Sandbox
         '182.162.196.200',
         // Real
         '211.249.40.1',  '211.249.40.2',  '211.249.40.3',  '211.249.40.4',  '211.249.40.5',  '211.249.40.6',  '211.249.40.7',  '211.249.40.8',  '211.249.40.9',  '211.249.40.10',
         '211.249.40.11', '211.249.40.12', '211.249.40.13', '211.249.40.14', '211.249.40.15', '211.249.40.16', '211.249.40.17', '211.249.40.18', '211.249.40.19', '211.249.40.20',
         '211.249.40.21', '211.249.40.22', '211.249.40.23', '211.249.40.24', '211.249.40.25', '211.249.40.26', '211.249.40.27', '211.249.40.28', '211.249.40.29', '211.249.40.30',
    );

    /** QRコードイメージサイズ */
    const QR_IMG = array(
        'width' => 130,
        'height' => 130,
    );

    /** 表示する商品名の文字数 */
    const SHOW_ITEM_NAME_STR = 32;

    public function __construct()
    {
        $this->paymod_id = 'linepay';
        $this->pay_method = array(
            'acting_linepay',
        );

        $this->initialize_data();

        if (is_admin()) {
            add_action('usces_action_settlement_tab_title', array($this, 'settlement_tab_title'));
            add_action('usces_action_settlement_tab_body', array($this, 'settlement_tab_body'));
            add_action('usces_action_admin_settlement_update', array($this, 'admin_settlement_update'));
        }
        if ($this->is_activate()) {
            add_action('usces_after_cart_instant', array($this, 'acting_transaction'), 11);
            add_action('usces_filter_completion_settlement_message', array($this, 'completion_settlement_message'), 10, 2);
            if (is_admin()) {
                add_filter('usces_filter_settle_info_field_keys', array($this, 'settle_info_field_keys'));
            } else {
                add_filter('usces_filter_confirm_inform', array($this, 'confirm_inform'), 10, 5);
                add_action('usces_action_acting_processing', array($this, 'acting_processing'), 10, 2);
                add_filter('usces_filter_check_acting_return_results', array($this, 'acting_return'));
                add_action('usces_action_reg_orderdata', array($this, 'reg_orderdata'), 10, 2);
            }
        }
    }

    /**
     * instance取得
     * @return void
     */
    public static function get_instance()
    {
        if (null == self::$instance) {
            self::$instance = new self;
        }
        return self::$instance;
    }

    /**
     * 支払方法が有効かチェック
     * @return boolean
     */
    public function is_activate()
    {
        $acting_opts = $this->get_acting_settings();
        if (isset($acting_opts['activate']) && 'on' == $acting_opts['activate']) {
            return true;
        }
        return false;
    }

    /**
     * データの初期化
     * @return void
     */
    private function initialize_data()
    {
        // オプションの初期化
        $options = get_option('usces');
        if (!in_array('linepay', $options['acting_settings'])) {
            $options['acting_settings']['linepay']['activate'] = (isset($options['acting_settings']['linepay']['activate'])) ? $options['acting_settings']['linepay']['activate'] : 'off';
            $options['acting_settings']['linepay']['channel_id'] = (isset($options['acting_settings']['linepay']['channel_id'])) ? $options['acting_settings']['linepay']['channel_id'] : '';
            $options['acting_settings']['linepay']['secret_key'] = (isset($options['acting_settings']['linepay']['secret_key'])) ? $options['acting_settings']['linepay']['secret_key'] : '';
            $options['acting_settings']['linepay']['ope'] = (isset($options['acting_settings']['linepay']['ope'])) ? $options['acting_settings']['linepay']['ope'] : 'test';
            update_option('usces', $options);
        }

        $available_settlement = get_option('usces_available_settlement');
        if (!in_array('linepay', $available_settlement)) {
            // 決済設定画面での表示名を設定
            $available_settlement['linepay'] = 'LINE Pay';
            update_option('usces_available_settlement', $available_settlement);
        }

        $noreceipt_status = get_option('usces_noreceipt_status');
        if (!in_array('acting_linepay', $noreceipt_status)) {
            $noreceipt_status[] = 'acting_linepay';
            update_option('usces_noreceipt_status', $noreceipt_status);
        }
    }

    /**
     * usces_action_settlement_tab_title
     * 決済設定画面タブ
     * @return void
     */
    public function settlement_tab_title()
    {
        $settlement_selected = get_option('usces_settlement_selected');
        if (in_array('linepay', (array) $settlement_selected)) {
            echo '<li><a href="#uscestabs_linepay">LINE Pay</a></li>';
        }
    }

    /**
     * usces_action_settlement_tab_body
     * 決済設定画面フォーム
     * @return void
     */
    public function settlement_tab_body()
    {
        global $usces;
        $opts = $usces->options['acting_settings'];
        $settlement_selected = get_option('usces_settlement_selected');
        if (in_array('linepay', (array) $settlement_selected)):
            ?>
            <div id="uscestabs_linepay">
                <div class="settlement_service"><span class="service_title">LINE Pay</span></div>
            <?php if (isset($_POST['acting']) && 'linepay' == $_POST['acting']) { ?>
                    <div class="error_message"><?php echo $this->error_mes; ?></div>
            <?php } else if (isset($opts['linepay']['activate']) && 'on' == $opts['linepay']['activate']) { ?>
                    <div class="message">十分にテストを行ってから運用してください。</div>
            <?php } ?>
                <form action="" method="post" name="linepay_form" id="linepay_form">
                    <table class="settle_table">
                        <tr>
                            <th>LINE Pay 決済</th>
                                 <td><input name="activate" type="radio" id="activate_1" value="on"<?php if (isset($opts['linepay']['activate']) && $opts['linepay']['activate'] == 'on') {echo ' checked="checked"';}?> /><label for="activate_1">利用する</label></td>
				<td><input name="activate" type="radio" id="activate_2" value="off"<?php if (isset($opts['linepay']['activate']) && $opts['linepay']['activate'] == 'off') {echo ' checked="checked"';}?> /><label for="activate_2">利用しない</label></td>
                            <td></td>
                        </tr>
                        <tr>
                            <th><a style="cursor:pointer;" onclick="toggleVisibility('ex_channel_id');">Channel ID</a></th>
                            <td colspan="2"><input name="channel_id" type="text" id="channel_id" value="<?php echo esc_html(isset($opts['linepay']['channel_id']) ? $opts['linepay']['channel_id'] : ''); ?>" size="20" /></td>
                            <td><div id="ex_channel_id" class="explanation">LINE Pay から発行される Channel ID（半角数字）</div></td>
                        </tr>
                        <tr>
                            <th><a style="cursor:pointer;" onclick="toggleVisibility('ex_secret_key');">Secret Key</a></th>
                            <td colspan="2"><input name="secret_key" type="text" id="secret_key" value="<?php echo esc_html(isset($opts['linepay']['secret_key']) ? $opts['linepay']['secret_key'] : ''); ?>" size="20" /></td>
                            <td><div id="ex_secret_key" class="explanation">LINE Pay から発行される Secret Key（半角英数字）</div></td>
                        </tr>
                        <tr>
                            <th><a style="cursor:pointer;" onclick="toggleVisibility('ex_ope');">稼動環境</a></th>
				<td><input name="ope" type="radio" id="ope_1" value="test"<?php if (isset($opts['linepay']['ope']) && $opts['linepay']['ope'] == 'test') {echo ' checked="checked"';}?> /><label for="ope_1">テスト</label></td>
				<td><input name="ope" type="radio" id="ope_2" value="public"<?php if (isset($opts['linepay']['ope']) && $opts['linepay']['ope'] == 'public') {echo ' checked="checked"';}?> /><label for="ope_2">本番</label></td>
                            <td><div id="ex_ope" class="explanation">動作環境を切り替えます。</div></td>
                        </tr>
                    </table>
                    <input name="acting" type="hidden" value="linepay" />
                    <input name="usces_option_update" type="submit" class="button button-primary" value="LINE Payの設定を更新する" />
		<?php wp_nonce_field('admin_settlement', 'wc_nonce');?>
                </form>
            </div>
            <?php
        endif;
    }

    /**
     * usces_action_admin_settlement_update
     * 決済オプション登録・更新
     * @return void
     */
    public function admin_settlement_update()
    {
        global $usces;

        if ($this->paymod_id != $_POST['acting']) {
            return;
        }

        $this->error_mes = '';
        $options = get_option('usces');

        unset($options['acting_settings']['linepay']);
        $options['acting_settings']['linepay']['activate'] = isset($_POST['activate']) ? $_POST['activate'] : 'off';
        $options['acting_settings']['linepay']['channel_id'] = isset($_POST['channel_id']) ? $_POST['channel_id'] : '';
        $options['acting_settings']['linepay']['secret_key'] = isset($_POST['secret_key']) ? $_POST['secret_key'] : '';
        $options['acting_settings']['linepay']['ope'] = isset($_POST['ope']) ? $_POST['ope'] : 'test';

        // Validate
        if (WCUtils::is_blank($_POST['activate'])) {
            $this->error_mes .= '※利用の有無を選択して下さい<br />';
        }
        if (WCUtils::is_blank($_POST['channel_id'])) {
            $this->error_mes .= '※Channel ID を入力して下さい<br />';
        }
        if (WCUtils::is_blank($_POST['secret_key'])) {
            $this->error_mes .= '※Secret Key を入力して下さい<br />';
        }
        if (WCUtils::is_blank($_POST['ope'])) {
            $this->error_mes .= '※動作環境を選択して下さい<br />';
        }

        if (WCUtils::is_blank($this->error_mes)) {
            $usces->action_status = 'success';
            $usces->action_message = __('options are updated', 'usces');
            if ('on' == $options['acting_settings']['linepay']['activate']) {
                $usces->payment_structure['acting_linepay'] = 'LINE Pay 決済';
            } else {
                unset($usces->payment_structure['acting_linepay']);
            }
        } else {
            $usces->action_status = 'error';
            $usces->action_message = __('Data have deficiency.', 'usces');
            $options['acting_settings']['linepay']['activate'] = 'off';
            unset($usces->payment_structure['acting_linepay']);
        }

        // 更新
        ksort($usces->payment_structure);
        update_option('usces', $options);
        update_option('usces_payment_structure', $usces->payment_structure);
    }

    /**
     * usces_filter_confirm_inform
     * 内容確認ページ Purchase Button
     * @param string $html
     * @param array $payments
     * @param string $acting_flg
     * @param string $rand
     * @param bool $purchase_disabled
     * @return void
     */
    public function confirm_inform($html, $payments, $acting_flg, $rand, $purchase_disabled)
    {
        if (in_array($acting_flg, $this->pay_method)) {
            $html = '<form id="purchase_form" action="' . USCES_CART_URL . '" method="post" onKeyDown="if(event.keyCode == 13){return false;}">
                <div class="send">
                <input name="backDelivery" type="submit" id="back_button" class="back_to_delivery_button" value="' . __('Back', 'usces') . '"' . apply_filters('usces_filter_confirm_prebutton', null) . ' />
                <input name="purchase" type="submit" id="purchase_button" class="checkout_button" value="' . __('Checkout', 'usces') . '"' . $purchase_disabled . ' />
                </div>
				<input type="hidden" name="rand" value="' . $rand . '">
				<input type="hidden" name="_nonce" value="' . wp_create_nonce($acting_flg) . '">' . "\n";
        }
        return $html;
    }

    /**
     * usces_filter_completion_settlement_message
     * 決済完了メッセージ
     * @param type $html
     * @param type $usces_entries
     * @return void
     */
    public function completion_settlement_message($html, $usces_entries)
    {
        if (isset($_REQUEST['acting']) && ('linepay' == $_REQUEST['acting'])) {
            $mailaddress1 = esc_html($usces_entries['customer']['mailaddress1']);
            $paymentUrlWeb = $_REQUEST['paymentUrlWeb'];
            $paymentUrlApp = $_REQUEST['paymentUrlApp'];
            $width = self::QR_IMG['width'];
            $height = self::QR_IMG['height'];;
            $html .= <<<EOT
<div id="status_table">
    <h5>LINE Pay 決済</h5>
    <p>本ページは決済完了まで閉じないでください。</p>
    <table>
        <tr>
            <th nowrap="nowrap">リンク</th>
            <td><a href="{$paymentUrlWeb}" target=”_blank”><img src="wp-content/plugins/usc-e-shop-line/images/LINE-Pay(h)_W119_n.png"></a></td>
        </tr>
        <tr>
            <th nowrap="nowrap">QR (ブラウザ)</th>
            <td><img src="http://chart.apis.google.com/chart?cht=qr&chs={$width}x{$height}&chl={$paymentUrlWeb}" width="{$width}" height="{$height}"></td>
        </tr>
        <tr>
            <th nowrap="nowrap">QR (アプリ)</th>
            <td><img src="http://chart.apis.google.com/chart?cht=qr&chs={$width}x{$height}&chl={$paymentUrlApp}" width="{$width}" height="{$height}"></td>
        </tr>
    </table>
</div>
EOT;
        }
        return $html;
    }

    /**
     * usces_filter_settle_info_field_keys
     * 受注編集画面に表示する決済情報のキー
     * @param array $keys
     * @return array
     */
    public function settle_info_field_keys($keys)
    {
        return array_merge($keys, array('transactionId'));
    }

    /**
     * usces_after_cart_instant
     * 結果通知処理
     * @return void
     */
    public function acting_transaction()
    {
        global $usces;
        if (isset($_GET['acting']) && 'linepay' == $_GET['acting'] && isset($_GET['orderId']) && isset($_GET['transactionId'])) {

            $param = $this->get_request_data();
            usces_log('LINE Pay cgi data : ' . print_r($param, true), 'acting_transaction.log');
            $rand = $param['orderId'];
            $transactionId = $param['transactionId'];
            if (is_array(self::ACL) && !in_array($_SERVER['REMOTE_ADDR'], self::ACL)) {
                usces_save_order_acting_error(array('acting' => 'linepay', 'key' => $rand, 'result' => 'ConfirmURL IP Address INVALID', 'IPAddress' => $_SERVER['REMOTE_ADDR']));
                usces_log('LINE Pay Error:' . print_r($param, true), 'acting_transaction.log');
                header("HTTP/1.0 200 OK");
                die('error0');
            }
            $order_id = $this->get_order_id($rand);
            if (is_null($order_id)) {
                usces_save_order_acting_error(array('acting' => 'linepay', 'key' => $rand, 'result' => 'ORDER ID NOT FOUND'));
                usces_log('LINE Pay Error:' . print_r($param, true), 'acting_transaction.log');
                header("HTTP/1.0 200 OK");
                die('error1');
            }
            $data = usces_unserialize($usces->get_order_meta_value('acting_' . $rand, $order_id));
            if ($data['transactionId'] != $transactionId) {
                usces_save_order_acting_error(array('acting' => 'linepay', 'key' => $rand, 'result' => 'TRANSACTION ID INVALID', 'data' => $data));
                usces_log('LINE Pay Error:' . print_r($param, true) . '\n' . print_r($data, true), 'acting_transaction.log');
                header("HTTP/1.0 200 OK");
                die('error2');
            }
            if (usces_change_order_receipt($order_id, 'receipted') === false) {
                usces_save_order_acting_error(array('acting' => 'linepay', 'key' => $rand, 'result' => 'CHANGE ORDER RECIPT ERROR', 'data' => $data));
                usces_log('LINE Pay Error:' . print_r($param, true) . '\n' . print_r($data, true), 'acting_transaction.log');
                header("HTTP/1.0 200 OK");
                die('error3');
            }

            usces_action_acting_getpoint($order_id);
            do_action('usces_action_linepay_payment_completion', $order_id);

            usces_log('LINE Pay transaction : orderId=' . $rand . '&transactionId=' . $transactionId, 'acting_transaction.log');
            header("HTTP/1.0 200 OK");
            die('LINE Payでの決済が完了しました。');
        }
    }

    /**
     * リクエストパラメータ取得
     * @return array
     */
    protected function get_request_data()
    {
        $data = array();
        foreach ($_REQUEST as $key => $value) {
            if ('uscesid' == $key) {
                ;
            } else if ('username' == $key) {
                $data[$key] = mb_convert_encoding($value, 'UTF-8', 'SJIS');
            } else {
                $data[$key] = $value;
            }
        }
        return $data;
    }

    /**
     * order_id 取得
     * @param string $key
     * @return string
     */
    protected function get_order_id($key)
    {
        global $wpdb;
        $order_meta_table_name = $wpdb->prefix . 'usces_order_meta';
        $query = $wpdb->prepare("SELECT order_id FROM $order_meta_table_name WHERE meta_key = %s", 'acting_' . $key);
        return $wpdb->get_var($query);
    }

    /**
     * usces_action_acting_processing
     * 決済処理
     * @return void
     */
    public function acting_processing($acting_flg, $post_query)
    {
        if (!in_array($acting_flg, $this->pay_method)) {
            return;
        }

        if (!wp_verify_nonce($_REQUEST['_nonce'], $acting_flg)) {
            wp_redirect(USCES_CART_URL);
        }

        global $usces;
        $entry = $usces->cart->get_entry();
        $cart = $usces->cart->get_cart();
        if (!$entry || !$cart) {
            wp_redirect(USCES_CART_URL);
        }

        $delim = apply_filters('usces_filter_delim', $usces->delim);
        $acting_opts = $this->get_acting_settings();

        $rand = $_REQUEST['rand'];
        usces_save_order_acting_data($rand);

        $item_name = $usces->getItemName($cart[0]['post_id']) . ((1 < count($cart)) ? ' ' . __('Others', 'usces') : '');
        $item_name = (LINEPAY_SETTLEMENT::SHOW_ITEM_NAME_STR < mb_strlen($item_name, 'UTF-8')) ? mb_strimwidth($item_name, 0, LINEPAY_SETTLEMENT::SHOW_ITEM_NAME_STR - 4, '...', 'UTF-8') : $item_name;

        $uri = (isset($acting_opts['ope']) && 'public' == $acting_opts['ope']) ? 'https://api-pay.line.me' : 'https://sandbox-api-pay.line.me';
        $uri .= '/v2/payments/request';

        $channelId = $acting_opts['channel_id'];
        $secretKey = $acting_opts['secret_key'];
        $productName = $item_name;
        $amount = $entry['order']['total_full_price'];
        $currency = $usces->get_currency_code();
        $confirmUrl = self::CONFIRM_URL ? self::CONFIRM_URL : home_url('/?acting=linepay');
        $confirmUrlType = 'SERVER';
        $orderId = $rand;

        $header = array(
            'Content-Type: application/json; charset=UTF-8',
            'X-LINE-ChannelId: ' . $channelId,
            'X-LINE-ChannelSecret: ' . $secretKey,
        );
        $postData = array(
            'productName' => $productName,
            'amount' => $amount,
            'currency' => $currency,
            'confirmUrl' => $confirmUrl,
            'orderId' => $orderId,
            'confirmUrlType' => $confirmUrlType,
        );

        $ch = curl_init($uri);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        $rs = json_decode(curl_exec($ch), true);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if (isset($rs['returnCode']) && $rs['returnCode'] === '0000') {
            usces_log('line pay entry data (acting_processing) : ' . print_r($entry, true), 'acting_transaction.log');
            header('Location: ' . USCES_CART_URL . $delim . 'acting=linepay&acting_return=1&rand=' . $rand . '&transactionId=' . $rs['info']['transactionId'] . '&paymentUrlWeb=' . urlencode($rs['info']['paymentUrl']['web']) . '&paymentUrlApp=' . urlencode($rs['info']['paymentUrl']['app']));
        } else {
            usces_log('line pay Error' . print_r($rs, true), 'acting_transaction.log');
            usces_save_order_acting_error(array('acting' => 'linepay', 'key' => $rand, 'returnCode' => $rs['returnCode'], 'returnMessage' => $rs['returnMessage'], 'data' => $rs, 'curl_error' => $curl_error));
            header('Location: ' . USCES_CART_URL . $delim . 'acting=linepay&acting_return=0&returnCode=' . $rs['returnCode'] . '&returnMessage=' . $rs['returnMessage']);
        }
        exit;
    }

    /**
     * usces_filter_check_acting_return_results
     * 決済完了ページ制御
     * @param type $results
     * @return void
     */
    public function acting_return($results)
    {
        $results = $_GET;
        if ($_REQUEST['acting_return']) {
            $results[0] = 1;
        } else {
            $results[0] = 0;
        }
        $results['reg_order'] = true;
        return $results;
    }

    /**
     * usces_post_reg_orderdata
     * 受注データ登録
     *
     * @param type $args array($cart, $entry, $order_id, $member_id, $payments, $charging_type, $result)
     * @return void
     */
    public function reg_orderdata($args)
    {
        global $usces;
        extract($args);
        error_log($payments['settlement']);
        $acting_flg = $payments['settlement'];
        if (!in_array($acting_flg, $this->pay_method)) {
            return;
        }
        error_log(print_r($entry, true));
        if (!$entry['order']['total_full_price']) {
            return;
        }
        error_log(print_r($results, true));
        if ($payments['settlement'] == 'acting_linepay' && !empty($results['rand'])) {
            $data = array();
            $data['acting'] = $results['acting'];
            $data['transactionId'] = $results['transactionId'];
            $data['paymentUrlWeb'] = $results['paymentUrlWeb'];
            $data['paymentUrlApp'] = $results['paymentUrlApp'];
            $data['orderId'] = $results['rand'];
            $usces->set_order_meta_value('acting_' . $results['rand'], usces_serialize($data), $order_id);
        }
    }

    /**
     * 決済オプション取得
     * @return array
     */
    protected function get_acting_settings()
    {
        global $usces;
        return isset($usces->options['acting_settings']['linepay']) ? $usces->options['acting_settings']['linepay'] : array();
    }

}
