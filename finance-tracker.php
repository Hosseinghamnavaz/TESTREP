<?php


/**
 * ۱. ایجاد جداول دیتابیس (تراکنش‌ها + اقساط) و تنظیم رمز عبور هش شده
 */
add_action('init', 'wp_finance_setup');
function wp_finance_setup() {
    global $wpdb;

    $db_version = '2';
    if (get_option('finance_db_version') !== $db_version) {
        $charset_collate = $wpdb->get_charset_collate();
        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        $tx_table = $wpdb->prefix . 'finance_transactions';
        $sql1 = "CREATE TABLE $tx_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            person varchar(20) NOT NULL DEFAULT 'hossein',
            tx_type varchar(20) NOT NULL,
            amount bigint(20) NOT NULL,
            tx_desc text NOT NULL,
            tx_date date NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        dbDelta($sql1);

        $inst_table = $wpdb->prefix . 'finance_installments';
        $sql2 = "CREATE TABLE $inst_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            person varchar(20) NOT NULL DEFAULT 'hossein',
            title text NOT NULL,
            amount bigint(20) NOT NULL,
            is_routine tinyint(1) NOT NULL DEFAULT 0,
            is_paid tinyint(1) NOT NULL DEFAULT 0,
            paid_month varchar(10) NOT NULL DEFAULT '',
            is_archived tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        dbDelta($sql2);

        update_option('finance_db_version', $db_version);
    }

    // انتقال یک‌باره: همه‌ی موارد فعلی به سارینا اختصاص داده می‌شوند (فقط یک بار اجرا می‌شود)
    if (!get_option('finance_migrated_to_sarina')) {
        $wpdb->query("UPDATE {$wpdb->prefix}finance_transactions SET person = 'sarina'");
        $wpdb->query("UPDATE {$wpdb->prefix}finance_installments SET person = 'sarina'");
        update_option('finance_migrated_to_sarina', '1');
    }

    if(!get_option('finance_app_secret')) {
        update_option('finance_app_secret', wp_hash_password('Ho85129'));
    }
}

/**
 * توابع کمکی
 */
function finance_check_auth() {
    $cookie_secret = hash('sha256', 'finance_auth_secret' . SECURE_AUTH_KEY);
    if(empty($_COOKIE['finance_auth']) || $_COOKIE['finance_auth'] !== $cookie_secret) {
        wp_send_json_error('دسترسی غیرمجاز');
    }
}

function finance_clean_person($p) {
    $p = sanitize_text_field($p);
    return in_array($p, ['hossein', 'sarina'], true) ? $p : 'hossein';
}

/**
 * ۱-ب. حالت اپلیکیشن (PWA): تمام‌صفحه با «افزودن به صفحه اصلی»
 */
function finance_pwa_tags() {
    static $done = false;
    if ($done) return;
    $done = true;

    $path = isset($_SERVER['REQUEST_URI']) ? strtok(wp_unslash($_SERVER['REQUEST_URI']), '?') : '/';
    $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'])) : '';
    $page = (is_ssl() ? 'https://' : 'http://') . $host . $path;
    $ajax = admin_url('admin-ajax.php');

    echo '<link rel="manifest" href="' . esc_url($ajax . '?action=finance_manifest&u=' . rawurlencode($page)) . '">' . "\n";
    echo '<link rel="apple-touch-icon" href="' . esc_url($ajax . '?action=finance_icon&size=180') . '">' . "\n";
    echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
    echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
    echo '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">' . "\n";
    echo '<meta name="apple-mobile-web-app-title" content="مالی">' . "\n";
    echo '<meta name="theme-color" content="#0f2027">' . "\n";
}

add_action('wp_head', 'finance_pwa_head');
function finance_pwa_head() {
    global $post;
    if (is_singular() && $post && has_shortcode($post->post_content, 'finance_tracker')) {
        finance_pwa_tags();
    }
}

add_action('wp_ajax_finance_manifest', 'finance_manifest_output');
add_action('wp_ajax_nopriv_finance_manifest', 'finance_manifest_output');
function finance_manifest_output() {
    $u = isset($_GET['u']) ? esc_url_raw(wp_unslash($_GET['u'])) : '';
    $start = wp_validate_redirect($u, home_url('/'));
    $ajax = admin_url('admin-ajax.php');

    $data = [
        'name' => 'مدیریت مالی',
        'short_name' => 'مالی',
        'lang' => 'fa',
        'dir' => 'rtl',
        'start_url' => $start,
        'scope' => home_url('/'),
        'display' => 'fullscreen',
        'display_override' => ['fullscreen', 'standalone'],
        'orientation' => 'portrait',
        'background_color' => '#0f2027',
        'theme_color' => '#0f2027',
        'icons' => [
            ['src' => $ajax . '?action=finance_icon&size=192', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => $ajax . '?action=finance_icon&size=512', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ],
    ];
    header('Content-Type: application/manifest+json; charset=utf-8');
    echo wp_json_encode($data);
    exit;
}

/**
 * سرویس‌ورکر: از مسیر خود صفحه سرو می‌شود (?finance_sw=1) تا scope درست باشد.
 * صفحه را کش می‌کند تا بدون اینترنت هم باز شود؛ درخواست‌های admin-ajax کش نمی‌شوند.
 */
add_action('template_redirect', 'finance_sw_output', 0);
function finance_sw_output() {
    if (!isset($_GET['finance_sw'])) return;

    header('Content-Type: application/javascript; charset=utf-8');
    header('Service-Worker-Allowed: /');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $v = 'fin-v1';
    ?>
const CACHE = '<?php echo esc_js($v); ?>';

self.addEventListener('install', e => { self.skipWaiting(); });

self.addEventListener('activate', e => {
    e.waitUntil(
        caches.keys()
            .then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', event => {
    const req = event.request;
    if (req.method !== 'GET') return;                       // ثبت/ویرایش/حذف هرگز کش نمی‌شود
    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;
    if (url.pathname.indexOf('/wp-admin/') === 0) return;   // admin-ajax و آیکون‌ها
    if (url.searchParams.has('finance_sw')) return;

    // صفحه: اول شبکه، اگر نبود آخرین نسخهٔ کش‌شده
    if (req.mode === 'navigate' || (req.headers.get('accept') || '').indexOf('text/html') >= 0) {
        event.respondWith(
            fetch(req)
                .then(res => {
                    const copy = res.clone();
                    caches.open(CACHE).then(c => c.put(req, copy)).catch(() => {});
                    return res;
                })
                .catch(() => caches.match(req).then(r => r || caches.match(self.location.pathname)))
        );
        return;
    }

    // بقیه (css/js/font): اول کش، بعد شبکه
    event.respondWith(
        caches.match(req).then(hit => hit || fetch(req).then(res => {
            if (res && res.status === 200 && res.type === 'basic') {
                const copy = res.clone();
                caches.open(CACHE).then(c => c.put(req, copy)).catch(() => {});
            }
            return res;
        }).catch(() => hit))
    );
});
    <?php
    exit;
}

add_action('wp_ajax_finance_icon', 'finance_icon_output');
add_action('wp_ajax_nopriv_finance_icon', 'finance_icon_output');
function finance_icon_output() {
    $s = isset($_GET['size']) ? max(48, min(512, intval($_GET['size']))) : 192;
    header('Cache-Control: public, max-age=604800');

    if (function_exists('imagecreatetruecolor')) {
        $im = imagecreatetruecolor($s, $s);
        $bg = imagecolorallocate($im, 32, 58, 67);
        $green = imagecolorallocate($im, 74, 222, 128);
        $white = imagecolorallocate($im, 255, 255, 255);
        imagefill($im, 0, 0, $bg);
        imagefilledellipse($im, (int)($s / 2), (int)($s / 2), (int)($s * 0.66), (int)($s * 0.66), $green);
        $half = $s / 2;
        $len = $s * 0.20;
        $th = max(3, $s * 0.06);
        imagefilledrectangle($im, (int)($half - $len), (int)($half - $th / 2), (int)($half + $len), (int)($half + $th / 2), $white);
        imagefilledrectangle($im, (int)($half - $th / 2), (int)($half - $len), (int)($half + $th / 2), (int)($half + $len), $white);
        header('Content-Type: image/png');
        imagepng($im);
        imagedestroy($im);
        exit;
    }

    header('Content-Type: image/svg+xml');
    echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect width="100" height="100" fill="#203a43"/><circle cx="50" cy="50" r="33" fill="#4ade80"/><path d="M30 50h40M50 30v40" stroke="#fff" stroke-width="6" stroke-linecap="round"/></svg>';
    exit;
}

/**
 * ۲. دریافت اطلاعات فرم (AJAX) برای ذخیره/ویرایش و حذف تراکنش
 */
add_action('wp_ajax_save_finance_transaction', 'save_finance_transaction');
add_action('wp_ajax_nopriv_save_finance_transaction', 'save_finance_transaction');
function save_finance_transaction() {
    finance_check_auth();

    global $wpdb;
    $table_name = $wpdb->prefix . 'finance_transactions';

    $edit_id = isset($_POST['edit_id']) ? intval($_POST['edit_id']) : 0;
    $type = (isset($_POST['tx_type']) && $_POST['tx_type'] === 'income') ? 'income' : 'expense';
    $amount = intval($_POST['amount']);
    $desc = sanitize_text_field($_POST['tx_desc']);
    $person = finance_clean_person(isset($_POST['person']) ? $_POST['person'] : 'hossein');

    if ($edit_id > 0) {
        $date = sanitize_text_field($_POST['tx_date']);
        $wpdb->update($table_name, [
            'person' => $person,
            'tx_type' => $type,
            'amount' => $amount,
            'tx_desc' => $desc
        ], ['id' => $edit_id]);
        wp_send_json_success(['id' => $edit_id, 'date' => $date]);
    } else {
        $date = current_time('Y-m-d');
        $wpdb->insert($table_name, [
            'person' => $person,
            'tx_type' => $type,
            'amount' => $amount,
            'tx_desc' => $desc,
            'tx_date' => $date
        ]);
        wp_send_json_success(['id' => $wpdb->insert_id, 'date' => $date]);
    }
}

add_action('wp_ajax_delete_finance_transaction', 'delete_finance_transaction');
add_action('wp_ajax_nopriv_delete_finance_transaction', 'delete_finance_transaction');
function delete_finance_transaction() {
    finance_check_auth();

    global $wpdb;
    $table_name = $wpdb->prefix . 'finance_transactions';
    $id = intval($_POST['id']);

    if($id > 0) {
        $wpdb->delete($table_name, ['id' => $id]);
        wp_send_json_success();
    }
    wp_send_json_error('شناسه نامعتبر');
}

/**
 * ۲-ب. عملیات اقساط (افزودن/ویرایش، تیک پرداخت، آرشیو، حذف)
 */
add_action('wp_ajax_finance_installment_action', 'finance_installment_action');
add_action('wp_ajax_nopriv_finance_installment_action', 'finance_installment_action');
function finance_installment_action() {
    finance_check_auth();

    global $wpdb;
    $table = $wpdb->prefix . 'finance_installments';
    $op = isset($_POST['op']) ? sanitize_text_field($_POST['op']) : '';
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

    if ($op === 'save') {
        $person = finance_clean_person(isset($_POST['person']) ? $_POST['person'] : 'hossein');
        $title = sanitize_text_field($_POST['title']);
        $amount = intval($_POST['amount']);
        $routine = !empty($_POST['routine']) ? 1 : 0;
        if ($title === '' || $amount <= 0) wp_send_json_error('اطلاعات ناقص');

        if ($id > 0) {
            $wpdb->update($table, [
                'title' => $title,
                'amount' => $amount,
                'is_routine' => $routine
            ], ['id' => $id]);
            wp_send_json_success(['id' => $id]);
        }
        $wpdb->insert($table, [
            'person' => $person,
            'title' => $title,
            'amount' => $amount,
            'is_routine' => $routine
        ]);
        wp_send_json_success(['id' => $wpdb->insert_id]);
    }

    if ($id <= 0) wp_send_json_error('شناسه نامعتبر');

    if ($op === 'toggle') {
        $checked = !empty($_POST['checked']) ? 1 : 0;
        $month = isset($_POST['month']) ? sanitize_text_field($_POST['month']) : '';
        if (!preg_match('/^\d{3,4}-\d{1,2}$/', $month)) $month = '';
        $wpdb->update($table, [
            'is_paid' => $checked,
            'paid_month' => $checked ? $month : ''
        ], ['id' => $id]);
        wp_send_json_success();
    }

    if ($op === 'archive') {
        $val = !empty($_POST['val']) ? 1 : 0;
        $wpdb->update($table, ['is_archived' => $val], ['id' => $id]);
        wp_send_json_success();
    }

    if ($op === 'delete') {
        $wpdb->delete($table, ['id' => $id]);
        wp_send_json_success();
    }

    wp_send_json_error('عملیات نامعتبر');
}

/**
 * ۳. رندر شورت‌کد (رابط کاربری)
 */
add_shortcode('finance_tracker', 'finance_tracker_shortcode_render');
function finance_tracker_shortcode_render() {
    ob_start();

    if(isset($_POST['finance_logout'])) {
        ?>
        <script>
            document.cookie = "finance_auth=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
            window.location.replace(window.location.href);
        </script>
        <?php
        return ob_get_clean();
    }

    $finance_login_error = '';
    if(isset($_POST['finance_login_submit']) && isset($_POST['finance_password'])) {
        $stored_hash = get_option('finance_app_secret');
        if(wp_check_password($_POST['finance_password'], $stored_hash)) {
            $cookie_secret = hash('sha256', 'finance_auth_secret' . SECURE_AUTH_KEY);
            ?>
            <script>
                let d = new Date();
                d.setTime(d.getTime() + (30*24*60*60*1000));
                document.cookie = "finance_auth=<?php echo $cookie_secret; ?>; expires=" + d.toUTCString() + "; path=/";
                window.location.replace(window.location.href);
            </script>
            <?php
            return ob_get_clean();
        } else {
            $finance_login_error = "رمز عبور اشتباه است!";
        }
    }

    $cookie_secret = hash('sha256', 'finance_auth_secret' . SECURE_AUTH_KEY);
    $is_logged_in = (isset($_COOKIE['finance_auth']) && $_COOKIE['finance_auth'] === $cookie_secret);

    finance_pwa_tags(); // اگر قبلاً در هدر چاپ نشده باشد (مثلاً صفحه‌ساز)
    ?>
    <style>
        .finance-app-wrapper {
            --glass-bg: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
            --income-color: #4ade80;
            --expense-color: #f87171;
            --text-main: #ffffff;

            font-family: 'Vazir', 'Vazirmatn', Tahoma, sans-serif;
            background: linear-gradient(135deg, #0f2027, #203a43, #2c5364);
            color: var(--text-main);
            padding: calc(18px + env(safe-area-inset-top, 0px)) calc(16px + env(safe-area-inset-right, 0px)) calc(18px + env(safe-area-inset-bottom, 0px)) calc(16px + env(safe-area-inset-left, 0px));
            display: flex;
            justify-content: center;
            border-radius: 0;
            box-sizing: border-box;
            direction: rtl;
            margin: 0;
            /* تمام‌صفحه: کل صفحه (هدر و فوتر قالب) را می‌پوشاند */
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            z-index: 99999;
            overflow-x: hidden;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }

        .finance-app-wrapper .app-container { width: 100%; max-width: 480px; display: flex; flex-direction: column; }

        /* منوی پایین */
        .finance-app-wrapper .nav-logout { color: #f87171; }
        .finance-app-wrapper.light .nav-btn.nav-logout { color: #dc2626; }
        .finance-app-wrapper .bottom-nav { margin-top: auto; position: sticky; bottom: 10px; z-index: 50; display: flex; gap: 4px; padding: 6px; border-radius: 20px; background: rgba(15, 32, 39, 0.85); backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); border: 1px solid var(--glass-border); box-shadow: 0 8px 28px rgba(0,0,0,0.45); }
        .finance-app-wrapper .nav-btn { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; padding: 8px 2px; border: none; border-radius: 14px; background: transparent; cursor: pointer; font-size: 0.72rem; font-weight: bold; margin: 0; opacity: 0.65; }
        .finance-app-wrapper .nav-btn svg { width: 22px; height: 22px; stroke-width: 2px; }
        .finance-app-wrapper .nav-btn.active { background: rgba(255,255,255,0.2); opacity: 1; }

        .finance-app-wrapper .glass-panel {
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.3);
        }

        .finance-app-wrapper h2, .finance-app-wrapper h3 { margin-top: 0; text-align: center; color: white; font-size: 1.2rem; margin-bottom: 15px; }
        .finance-app-wrapper .form-group { margin-bottom: 15px; }

        .finance-app-wrapper input, .finance-app-wrapper select, .finance-app-wrapper button {
            width: 100%; padding: 12px; border-radius: 12px; border: 1px solid var(--glass-border);
            background: rgba(255, 255, 255, 0.05); color: white; font-family: inherit;
            box-sizing: border-box; outline: none; transition: 0.3s; font-size: 1rem;
        }

        .finance-app-wrapper select option { background: #203a43; color: white; }

        .finance-app-wrapper input::placeholder { color: rgba(255,255,255,0.6); }
        .finance-app-wrapper input:focus, .finance-app-wrapper select:focus { background: rgba(255, 255, 255, 0.15); border-color: rgba(255,255,255,0.5); }
        .finance-app-wrapper input[name="finance_password"] { direction: ltr; text-align: center; letter-spacing: 3px; font-family: monospace; font-size: 1.2rem; }

        .finance-app-wrapper button[type="submit"], .finance-app-wrapper .primary-btn { background: rgba(255, 255, 255, 0.2); cursor: pointer; font-weight: bold; margin-top: 5px; }
        .finance-app-wrapper button[type="submit"]:active, .finance-app-wrapper .primary-btn:active { background: rgba(255, 255, 255, 0.3); }

        .finance-app-wrapper #cancel-edit-btn, .finance-app-wrapper #inst-cancel-btn { background: rgba(248, 113, 113, 0.15); color: #fca5a5; margin-top: 8px; font-size: 0.9rem; cursor: pointer; }
        .finance-app-wrapper .ghost-btn { background: rgba(0,0,0,0.2); cursor: pointer; margin-top: 12px; font-size: 0.9rem; }

        .finance-app-wrapper .logout-btn { background: rgba(248, 113, 113, 0.2); border: 1px solid rgba(248, 113, 113, 0.4); color: #f87171; padding: 10px 15px; border-radius: 12px; cursor: pointer; width: 100%; font-weight: bold; margin-top: 10px; }

        /* تب‌های اصلی بالا */
        .finance-app-wrapper .main-tabs { display: flex; gap: 8px; margin-bottom: 20px; background: rgba(0,0,0,0.25); padding: 6px; border-radius: 16px; border: 1px solid var(--glass-border); }
        .finance-app-wrapper .main-tab { flex: 1; padding: 11px 6px; border-radius: 12px; border: none; background: transparent; cursor: pointer; font-weight: bold; font-size: 0.95rem; margin: 0; }
        .finance-app-wrapper .main-tab.active { background: rgba(255,255,255,0.22); box-shadow: 0 2px 10px rgba(0,0,0,0.25); }

        .finance-app-wrapper .person-badge { display: inline-block; font-size: 0.72rem; padding: 2px 8px; border-radius: 10px; margin-left: 6px; font-weight: bold; white-space: nowrap; }
        .finance-app-wrapper .person-badge.hossein { background: rgba(96,165,250,0.25); color: #93c5fd; }
        .finance-app-wrapper .person-badge.sarina { background: rgba(244,114,182,0.25); color: #f9a8d4; }

        .finance-app-wrapper .person-row { display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: rgba(0,0,0,0.2); border-radius: 12px; margin-top: 8px; font-size: 0.9rem; }

        .finance-app-wrapper .balance-box { text-align: center; font-size: 1.3rem; font-weight: bold; margin-bottom: 15px;}
        .finance-app-wrapper .stats-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; text-align: center; font-size: 0.9rem; }
        .finance-app-wrapper .stat-item { padding: 10px; border-radius: 12px; background: rgba(0,0,0,0.2); display: flex; flex-direction: column; justify-content: center;}
        .finance-app-wrapper .text-green { color: var(--income-color); }
        .finance-app-wrapper .text-red { color: var(--expense-color); }
        .finance-app-wrapper .balance-sub { margin-top: 5px; padding-top: 5px; border-top: 1px solid rgba(255, 255, 255, 0.1); font-weight: bold; font-size: 0.85rem; }

        .finance-app-wrapper .toggle-container { display: flex; background: rgba(0,0,0,0.2); border-radius: 12px; margin-bottom: 15px; overflow: hidden; border: 1px solid var(--glass-border); }
        .finance-app-wrapper .toggle-btn { flex: 1; padding: 12px; border: none; background: transparent; color: white; cursor: pointer; border-radius: 0; font-weight: bold; margin: 0; box-shadow: none; }
        .finance-app-wrapper .toggle-btn.active[data-value="expense"] { background: var(--expense-color); color: #000; }
        .finance-app-wrapper .toggle-btn.active[data-value="income"] { background: var(--income-color); color: #000; }

        .finance-app-wrapper .quick-tags { display: flex; flex-wrap: nowrap; gap: 8px; margin-bottom: 15px; justify-content: flex-start; overflow-x: auto; overflow-y: hidden; scroll-snap-type: x proximity; -webkit-overflow-scrolling: touch; scrollbar-width: none; padding: 2px 2px 6px; cursor: grab; }
        .finance-app-wrapper .quick-tags::-webkit-scrollbar { display: none; }
        .finance-app-wrapper .quick-tags.dragging { cursor: grabbing; scroll-snap-type: none; }
        .finance-app-wrapper .tag { flex: none; scroll-snap-align: start; min-width: 64px; display: flex; flex-direction: column; align-items: center; gap: 4px; background: rgba(255,255,255,0.1); padding: 10px 12px; border-radius: 16px; font-size: 0.75rem; white-space: nowrap; cursor: pointer; border: 1px solid rgba(255,255,255,0.2); transition: 0.2s; user-select: none; }
        .finance-app-wrapper .tag .tag-icon { font-size: 1.5rem; line-height: 1; }
        .finance-app-wrapper .tag:active { transform: scale(0.95); }

        .finance-app-wrapper .tag.add-tag-btn { background: transparent; border: 1px dashed rgba(255,255,255,0.4); color: rgba(255,255,255,0.8); }
        .finance-app-wrapper .tag.add-tag-btn:hover { background: rgba(255,255,255,0.05); }

        .finance-app-wrapper .filter-row { display: flex; gap: 10px; margin-bottom: 15px; align-items: center; }
        .finance-app-wrapper .history-tabs { display: flex; gap: 8px; flex: 1; }
        .finance-app-wrapper .history-tab { background: rgba(0,0,0,0.2); border: 1px solid var(--glass-border); color: white; padding: 8px; border-radius: 12px; cursor: pointer; font-size: 0.85rem; flex: 1; margin: 0; }
        .finance-app-wrapper .history-tab.active { background: rgba(255,255,255,0.2); font-weight: bold; }

        .finance-app-wrapper details { background: rgba(0, 0, 0, 0.2); border-radius: 12px; margin-bottom: 10px; overflow: hidden; border: 1px solid var(--glass-border); }
        .finance-app-wrapper summary { padding: 15px; cursor: pointer; font-weight: bold; display: flex; justify-content: space-between; align-items: center; list-style: none; }
        .finance-app-wrapper summary::-webkit-details-marker { display: none; }
        .finance-app-wrapper .day-summary-text { font-size: 0.85rem; opacity: 0.9; }
        .finance-app-wrapper .transaction-list { padding: 10px 15px; background: rgba(255,255,255,0.05); }

        .finance-app-wrapper .transaction-item { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px dashed rgba(255,255,255,0.1); font-size: 0.9rem; }
        .finance-app-wrapper .transaction-item:last-child { border-bottom: none; }

        /* ردیف تراکنش: موبایل‌اول و دو خطی؛ متن کامل جا می‌شود */
        .finance-app-wrapper .tx-info { display: flex; flex-direction: column; gap: 4px; flex: 1; min-width: 0; padding-left: 8px; }
        .finance-app-wrapper .tx-desc {
            min-width: 0; max-width: 100%;
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
            overflow: hidden; white-space: normal; overflow-wrap: anywhere;
            font-size: 0.92rem; line-height: 1.55;
        }
        .finance-app-wrapper .tx-sub { display: flex; align-items: center; justify-content: space-between; gap: 8px; min-width: 0; }
        .finance-app-wrapper .tx-meta { display: flex; align-items: center; gap: 5px; min-width: 0; overflow: hidden; font-size: 0.78rem; opacity: 0.85; }
        .finance-app-wrapper .tx-meta > * { flex: none; }
        .finance-app-wrapper .tx-amount { flex: none; white-space: nowrap; font-weight: bold; font-size: 0.95rem; }

        @media (min-width: 560px) {
            .finance-app-wrapper .tx-info { flex-direction: row; align-items: center; justify-content: space-between; gap: 12px; }
            .finance-app-wrapper .tx-sub { flex: none; }
            .finance-app-wrapper .tx-desc { flex: 1; display: block; white-space: nowrap; text-overflow: ellipsis; }
        }

        .finance-app-wrapper .tx-actions { display: flex; gap: 5px; flex: none; }

        .finance-app-wrapper .action-btn {
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 8px;
            padding: 6px;
            cursor: pointer;
            transition: border-color 0.2s;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: rgba(255,255,255,0.9);
            font-size: 14px;
        }
        .finance-app-wrapper .action-btn:hover { border: 1px solid rgba(255,255,255,0.8); }
        .finance-app-wrapper .action-btn svg { width: 18px; height: 18px; stroke-width: 2px; }

        /* اقساط */
        .finance-app-wrapper .routine-check { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; cursor: pointer; font-size: 0.9rem; }
        .finance-app-wrapper input[type="checkbox"] { width: 20px; height: 20px; padding: 0; flex: none; accent-color: #4ade80; cursor: pointer; }
        .finance-app-wrapper .inst-summary { text-align: center; font-size: 0.9rem; margin-bottom: 15px; padding: 10px; border-radius: 12px; background: rgba(0,0,0,0.2); }
        .finance-app-wrapper .inst-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: rgba(0,0,0,0.2); border: 1px solid var(--glass-border); border-radius: 12px; margin-top: 8px; font-size: 0.9rem; }
        .finance-app-wrapper .inst-item.paid .inst-title { text-decoration: line-through; opacity: 0.55; }
        .finance-app-wrapper .inst-body { flex: 1; overflow: hidden; }
        .finance-app-wrapper .inst-title { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .finance-app-wrapper .inst-amount { font-size: 0.8rem; opacity: 0.85; }
        .finance-app-wrapper .routine-badge { font-size: 0.7rem; padding: 1px 7px; border-radius: 10px; background: rgba(251,191,36,0.2); color: #fcd34d; margin-right: 6px; }

        .finance-app-wrapper .error-msg { background: rgba(248, 113, 113, 0.2); border: 1px solid rgba(248, 113, 113, 0.5); padding: 10px; border-radius: 10px; color: #fca5a5; text-align: center; margin-bottom: 15px; font-size: 0.9rem;}

        /* مودال‌ها */
        .finance-app-wrapper .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.6);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            backdrop-filter: blur(5px);
            -webkit-backdrop-filter: blur(5px);
        }
        .finance-app-wrapper .modal-overlay.active { display: flex; }
        .finance-app-wrapper .modal-box {
            background: #203a43;
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            padding: 25px 20px;
            width: 90%;
            max-width: 350px;
            text-align: center;
            box-shadow: 0 10px 40px rgba(0,0,0,0.5);
            color: white;
            font-family: inherit;
            direction: rtl;
        }
        .finance-app-wrapper .modal-box h4 { margin-top: 0; font-size: 1.2rem; margin-bottom: 10px; color: #fca5a5; }
        .finance-app-wrapper .modal-box p { font-size: 0.9rem; opacity: 0.9; margin-bottom: 25px; line-height: 1.6; }
        .finance-app-wrapper .modal-buttons { display: flex; gap: 10px; }
        .finance-app-wrapper .modal-btn {
            flex: 1; padding: 12px; border-radius: 12px; border: none; cursor: pointer; font-weight: bold; font-family: inherit; font-size: 0.95rem; transition: 0.2s;
        }
        .finance-app-wrapper .modal-btn.confirm { background: #ef4444; color: white; }
        .finance-app-wrapper .modal-btn.confirm:hover { background: #dc2626; }
        .finance-app-wrapper .modal-btn.success { background: #4ade80; color: #000; }
        .finance-app-wrapper .modal-btn.success:hover { background: #22c55e; }
        .finance-app-wrapper .modal-btn.cancel { background: rgba(255,255,255,0.1); color: white; border: 1px solid rgba(255,255,255,0.2); }
        .finance-app-wrapper .modal-btn.cancel:hover { background: rgba(255,255,255,0.2); }

        /* دکمه تغییر تم */
        .finance-app-wrapper .top-row { display: flex; gap: 10px; align-items: center; margin-bottom: 20px; }
        .finance-app-wrapper .top-row .main-tabs { flex: 1; margin-bottom: 0; }
        .finance-app-wrapper .theme-btn { display: flex; align-items: center; justify-content: center; line-height: 1; width: 50px; height: 50px; padding: 0; flex: none; border-radius: 16px; cursor: pointer; font-size: 1.25rem; background: rgba(0,0,0,0.25); margin: 0; }

        /* ===== ریسپانسیو ===== */
        .finance-app-wrapper .bottom-nav { bottom: 8px; }
        .finance-app-wrapper .glass-panel { max-width: 100%; box-sizing: border-box; }
        .finance-app-wrapper .tx-info { min-width: 0; }
        @media (max-width: 420px) {
            .finance-app-wrapper .top-row { gap: 6px; }
            .finance-app-wrapper .theme-btn { width: 42px; height: 42px; border-radius: 13px; font-size: 1.1rem; }
            .finance-app-wrapper .main-tabs { gap: 4px; padding: 4px; }
            .finance-app-wrapper .main-tab { font-size: 0.8rem; padding: 10px 2px; }
            .finance-app-wrapper .glass-panel { padding: 16px 14px; border-radius: 18px; }
            .finance-app-wrapper .stats-grid { gap: 8px; font-size: 0.82rem; }
            .finance-app-wrapper .action-btn { width: 30px; height: 30px; padding: 5px; }
            .finance-app-wrapper .action-btn svg { width: 16px; height: 16px; }
            .finance-app-wrapper .tx-actions { gap: 4px; }
            .finance-app-wrapper .transaction-list { padding: 10px 10px; }
            .finance-app-wrapper .nav-btn { font-size: 0.68rem; }
        }
        @media (min-width: 700px) {
            .finance-app-wrapper .app-container { max-width: 520px; }
        }

        /* ===== نمای دسته‌بندی و اقساط تفکیکی ===== */
        .finance-app-wrapper .view-tab, .finance-app-wrapper .inst-view-tab { background: rgba(0,0,0,0.2); border: 1px solid var(--glass-border); color: white; padding: 8px; border-radius: 12px; cursor: pointer; font-size: 0.85rem; flex: 1; margin: 0; }
        .finance-app-wrapper .view-tab.active, .finance-app-wrapper .inst-view-tab.active { background: rgba(255,255,255,0.2); font-weight: bold; }
        .finance-app-wrapper .cat-sub { font-size: 0.75rem; opacity: 0.8; font-weight: normal; display: block; margin-top: 3px; }
        .finance-app-wrapper.light .view-tab, .finance-app-wrapper.light .inst-view-tab { background: rgba(15,23,42,0.06); color: #0f172a; }
        .finance-app-wrapper.light .view-tab.active, .finance-app-wrapper.light .inst-view-tab.active { background: #fff; }

        .finance-app-wrapper .tag { position: relative; }
        .finance-app-wrapper .inst-tags-row { margin-top: -8px; padding-right: 14px; border-right: 2px solid rgba(255,255,255,0.25); }
        .finance-app-wrapper.light .inst-tags-row { border-right-color: rgba(15,23,42,0.25); }
        .finance-app-wrapper .tag.tag-removable { border-color: rgba(248,113,113,0.85); }
        .finance-app-wrapper .tag .tag-x { position: absolute; top: 2px; left: 5px; font-size: 0.7rem; line-height: 1; color: #f87171; }
        .finance-app-wrapper .tag.tag-edit-on { border-style: solid; border-color: rgba(248,113,113,0.85); color: #fca5a5; }
        .finance-app-wrapper.light .tag .tag-x, .finance-app-wrapper.light .tag.tag-edit-on { color: #dc2626; }

        .finance-app-wrapper .icon-hint { font-size: 0.82rem; opacity: 0.85; margin-bottom: 8px; text-align: right; }
        .finance-app-wrapper .icon-hint b { font-size: 1.1rem; }
        .finance-app-wrapper .icon-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 5px; max-height: 150px; overflow-y: auto; padding: 6px; margin-bottom: 22px; background: rgba(0,0,0,0.25); border: 1px solid var(--glass-border); border-radius: 14px; }
        .finance-app-wrapper .icon-cell { font-size: 1.2rem; line-height: 1; padding: 7px 0; border-radius: 10px; cursor: pointer; text-align: center; border: 1px solid transparent; user-select: none; }
        .finance-app-wrapper .icon-cell.sel { background: rgba(255,255,255,0.25); border-color: rgba(255,255,255,0.55); }
        .finance-app-wrapper.light .icon-grid { background: rgba(15,23,42,0.05); }
        .finance-app-wrapper.light .icon-cell.sel { background: rgba(15,23,42,0.12); border-color: rgba(15,23,42,0.35); }

        .finance-app-wrapper details .transaction-list { box-sizing: border-box; overflow: hidden; transition: height 0.26s ease; }
        .finance-app-wrapper summary { user-select: none; }
        .finance-app-wrapper summary::after { content: '▾'; display: inline-block; font-size: 0.9rem; opacity: 0.55; margin-right: 8px; transition: transform 0.26s ease; }
        .finance-app-wrapper details[open] summary::after { transform: rotate(180deg); }

        /* بازخورد لمسی گرد به جای مستطیل */
        .finance-app-wrapper, .finance-app-wrapper * { -webkit-tap-highlight-color: transparent; }
        .finance-app-wrapper button:focus, .finance-app-wrapper summary:focus, .finance-app-wrapper .tag:focus { outline: none; }
        .finance-app-wrapper .nav-btn, .finance-app-wrapper .main-tab, .finance-app-wrapper .history-tab, .finance-app-wrapper .view-tab, .finance-app-wrapper .inst-view-tab, .finance-app-wrapper .action-btn, .finance-app-wrapper .modal-btn, .finance-app-wrapper .theme-btn, .finance-app-wrapper .toggle-btn, .finance-app-wrapper .icon-cell, .finance-app-wrapper button[type="submit"], .finance-app-wrapper .primary-btn, .finance-app-wrapper .ghost-btn, .finance-app-wrapper .logout-btn { transition: transform 0.12s ease, background 0.2s ease; }
        .finance-app-wrapper .nav-btn:active, .finance-app-wrapper .main-tab:active, .finance-app-wrapper .history-tab:active, .finance-app-wrapper .view-tab:active, .finance-app-wrapper .inst-view-tab:active, .finance-app-wrapper .action-btn:active, .finance-app-wrapper .modal-btn:active, .finance-app-wrapper .theme-btn:active, .finance-app-wrapper .toggle-btn:active, .finance-app-wrapper .icon-cell:active, .finance-app-wrapper button[type="submit"]:active, .finance-app-wrapper .primary-btn:active, .finance-app-wrapper .ghost-btn:active, .finance-app-wrapper .logout-btn:active { transform: scale(0.96); }
        .finance-app-wrapper summary { border-radius: 12px; }
        .finance-app-wrapper summary:active { background: rgba(255,255,255,0.08); }
        .finance-app-wrapper.light summary:active { background: rgba(15,23,42,0.06); }

        /* کشویی دسته با جستجو */
        .finance-app-wrapper .cat-dd-btn { width: 100%; margin: 0; padding: 10px 12px; font-size: 0.88rem; font-family: inherit; color: inherit; border-radius: 12px; border: 1px solid var(--glass-border); background: rgba(255,255,255,0.05); display: flex; justify-content: space-between; align-items: center; gap: 8px; cursor: pointer; }
        .finance-app-wrapper .cat-dd-btn > span:first-child { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .finance-app-wrapper .cat-dd-caret { opacity: 0.6; transition: transform 0.2s ease; }
        .finance-app-wrapper .cat-dd.open .cat-dd-caret { transform: rotate(180deg); }
        .finance-app-wrapper .cat-dd-panel { display: none; margin-top: 6px; padding: 6px; border: 1px solid var(--glass-border); border-radius: 12px; background: rgba(0,0,0,0.25); }
        .finance-app-wrapper .cat-dd.open .cat-dd-panel { display: block; }
        .finance-app-wrapper .cat-search { width: 100%; padding: 8px 10px; font-size: 0.85rem; border-radius: 10px; margin-bottom: 6px; text-align: right; }
        .finance-app-wrapper .cat-items { max-height: 132px; overflow-y: auto; }
        .finance-app-wrapper .cat-item { padding: 7px 10px; border-radius: 9px; cursor: pointer; font-size: 0.86rem; text-align: right; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .finance-app-wrapper .cat-item.sel { background: rgba(255,255,255,0.2); font-weight: bold; }
        .finance-app-wrapper .cat-item:active { background: rgba(255,255,255,0.14); }
        .finance-app-wrapper .cat-empty { padding: 8px; text-align: center; opacity: 0.6; font-size: 0.82rem; }
        .finance-app-wrapper.light .cat-dd-btn { background: rgba(255,255,255,0.85); }
        .finance-app-wrapper.light .cat-dd-panel { background: rgba(15,23,42,0.05); }
        .finance-app-wrapper.light .cat-item.sel { background: rgba(15,23,42,0.12); }
        .finance-app-wrapper.light .cat-item:active { background: rgba(15,23,42,0.08); }

        /* هم‌ارتفاع شدن دکمه‌های تم و تمام‌صفحه با تب‌ها */
        .finance-app-wrapper .top-row { align-items: stretch; }
        .finance-app-wrapper .theme-btn { height: auto; min-height: 0; align-self: stretch; }

        .finance-app-wrapper .dd-actions { display: flex; gap: 6px; margin-top: 6px; }
        .finance-app-wrapper .dd-act { flex: 1; margin: 0; padding: 8px; font-size: 0.82rem; border-radius: 10px; background: rgba(255,255,255,0.08); cursor: pointer; }
        .finance-app-wrapper .dd-act.on { background: rgba(248,113,113,0.25); color: #fca5a5; font-weight: bold; }
        .finance-app-wrapper .cat-item.tag-child { padding-right: 24px; font-size: 0.82rem; opacity: 0.92; }
        .finance-app-wrapper .cat-item.tag-group { display: flex; justify-content: space-between; align-items: center; }
        .finance-app-wrapper .cat-item .grp-caret { opacity: 0.6; font-size: 0.75rem; transition: transform 0.2s ease; }
        .finance-app-wrapper .cat-item.open .grp-caret { transform: rotate(180deg); }
        .finance-app-wrapper .cat-item .item-x { color: #f87171; margin-left: 6px; }
        .finance-app-wrapper.light .dd-act { background: rgba(15,23,42,0.07); color: #0f172a; }
        .finance-app-wrapper.light .dd-act.on { background: rgba(220,38,38,0.12); color: #dc2626; }

        /* ===== حالت روشن ===== */
        .finance-app-wrapper.light { --glass-bg: rgba(255,255,255,0.72); --glass-border: rgba(15,23,42,0.14); --income-color: #16a34a; --expense-color: #dc2626; --text-main: #0f172a; background: linear-gradient(135deg, #dbeafe, #f1f5f9, #e0e7ff); }
        .finance-app-wrapper.light .glass-panel { box-shadow: 0 8px 28px rgba(15,23,42,0.1); }
        .finance-app-wrapper.light h2, .finance-app-wrapper.light h3 { color: #0f172a; }
        .finance-app-wrapper.light input[type="text"], .finance-app-wrapper.light input[type="password"], .finance-app-wrapper.light select { background: rgba(255,255,255,0.85); color: #0f172a; }
        .finance-app-wrapper.light input::placeholder { color: rgba(15,23,42,0.5); }
        .finance-app-wrapper.light input:focus, .finance-app-wrapper.light select:focus { background: #fff; border-color: rgba(15,23,42,0.45); }
        .finance-app-wrapper.light select option { background: #fff; color: #0f172a; }
        .finance-app-wrapper.light button[type="submit"], .finance-app-wrapper.light .primary-btn { background: rgba(15,23,42,0.1); color: #0f172a; }
        .finance-app-wrapper.light button.logout-btn { background: rgba(220,38,38,0.1); border-color: rgba(220,38,38,0.4); color: #dc2626; }
        .finance-app-wrapper.light .ghost-btn { background: rgba(15,23,42,0.06); color: #0f172a; }
        .finance-app-wrapper.light #cancel-edit-btn, .finance-app-wrapper.light #inst-cancel-btn { background: rgba(220,38,38,0.1); color: #dc2626; }
        .finance-app-wrapper.light .main-tabs { background: rgba(15,23,42,0.06); }
        .finance-app-wrapper.light .main-tab { color: #0f172a; }
        .finance-app-wrapper.light .main-tab.active { background: #fff; box-shadow: 0 2px 8px rgba(15,23,42,0.15); }
        .finance-app-wrapper.light .theme-btn { background: rgba(255,255,255,0.8); color: #0f172a; }
        .finance-app-wrapper.light .toggle-container { background: rgba(15,23,42,0.06); }
        .finance-app-wrapper.light .toggle-btn { color: #0f172a; }
        .finance-app-wrapper.light .toggle-btn.active[data-value="expense"], .finance-app-wrapper.light .toggle-btn.active[data-value="income"] { color: #fff; }
        .finance-app-wrapper.light .tag { background: rgba(15,23,42,0.06); border-color: rgba(15,23,42,0.18); }
        .finance-app-wrapper.light .tag.add-tag-btn { background: transparent; border-color: rgba(15,23,42,0.35); color: rgba(15,23,42,0.7); }
        .finance-app-wrapper.light .history-tab { background: rgba(15,23,42,0.06); color: #0f172a; }
        .finance-app-wrapper.light .history-tab.active { background: #fff; }
        .finance-app-wrapper.light details, .finance-app-wrapper.light .stat-item, .finance-app-wrapper.light .person-row, .finance-app-wrapper.light .inst-summary, .finance-app-wrapper.light .inst-item { background: rgba(15,23,42,0.05); }
        .finance-app-wrapper.light .transaction-list { background: rgba(255,255,255,0.55); }
        .finance-app-wrapper.light .transaction-item { border-bottom-color: rgba(15,23,42,0.12); }
        .finance-app-wrapper.light .balance-sub { border-top-color: rgba(15,23,42,0.12); }
        .finance-app-wrapper.light .action-btn { background: rgba(15,23,42,0.06); border-color: rgba(15,23,42,0.2); color: #0f172a; }
        .finance-app-wrapper.light .action-btn:hover { border-color: rgba(15,23,42,0.7); }
        .finance-app-wrapper.light .person-badge.hossein { background: rgba(59,130,246,0.15); color: #1d4ed8; }
        .finance-app-wrapper.light .person-badge.sarina { background: rgba(236,72,153,0.15); color: #be185d; }
        .finance-app-wrapper.light .routine-badge { background: rgba(245,158,11,0.2); color: #b45309; }
        .finance-app-wrapper.light .bottom-nav { background: rgba(255,255,255,0.92); box-shadow: 0 8px 28px rgba(15,23,42,0.18); }
        .finance-app-wrapper.light .nav-btn { color: #0f172a; }
        .finance-app-wrapper.light .nav-btn.active { background: rgba(15,23,42,0.1); }
        .finance-app-wrapper.light .modal-box { background: #fff; color: #0f172a; }
        .finance-app-wrapper.light .modal-box h4 { color: #dc2626; }
        .finance-app-wrapper.light .modal-btn.cancel { background: rgba(15,23,42,0.06); color: #0f172a; border: 1px solid rgba(15,23,42,0.2); }
        .finance-app-wrapper.light .modal-btn.cancel:hover { background: rgba(15,23,42,0.12); }
        .finance-app-wrapper.light .error-msg { color: #b91c1c; }

        /* ===== پاپ‌اور موبایل برای کشویی‌ها ===== */
        .finance-app-wrapper .sheet-backdrop {
            position: fixed; inset: 0; background: rgba(0,0,0,0.55);
            backdrop-filter: blur(3px); -webkit-backdrop-filter: blur(3px);
            z-index: 10000; display: none;
        }
        .finance-app-wrapper .sheet-backdrop.active { display: block; }

        .finance-app-wrapper .sheet-title { display: none; }

        /* پنل کشویی وقتی به صورت شیت پایین صفحه باز می‌شود (موبایل) */
        .finance-app-wrapper .cat-dd-panel.dd-sheet {
            display: block;
            position: fixed;
            left: 0; right: 0; bottom: 0; top: auto;
            margin: 0;
            z-index: 10001;
            border-radius: 22px 22px 0 0;
            border: none;
            border-top: 1px solid var(--glass-border);
            background: #16323c;
            padding: 18px 16px calc(16px + env(safe-area-inset-bottom, 0px));
            box-shadow: 0 -14px 40px rgba(0,0,0,0.5);
            animation: sheet-up 0.22s ease;
            max-height: 78vh;
            overflow-y: auto;
            box-sizing: border-box;
        }
        .finance-app-wrapper.light .cat-dd-panel.dd-sheet { background: #ffffff; box-shadow: 0 -14px 40px rgba(15,23,42,0.25); }
        .finance-app-wrapper .cat-dd-panel.dd-sheet .cat-items { max-height: 46vh; }
        .finance-app-wrapper .cat-dd-panel.dd-sheet .cat-item { padding: 13px 10px; font-size: 0.95rem; }
        .finance-app-wrapper .cat-dd-panel.dd-sheet .cat-search { padding: 12px; font-size: 0.95rem; }
        .finance-app-wrapper .cat-dd-panel.dd-sheet .dd-actions { margin-top: 10px; }
        .finance-app-wrapper .cat-dd-panel.dd-sheet .dd-act { padding: 13px; font-size: 0.9rem; }
        .finance-app-wrapper .cat-dd-panel.dd-sheet .sheet-title {
            display: flex; align-items: center; justify-content: space-between;
            font-weight: bold; font-size: 1rem; margin-bottom: 14px; position: relative;
        }
        .finance-app-wrapper .cat-dd-panel.dd-sheet .sheet-title::before {
            content: ''; position: absolute; top: -12px; left: 50%; transform: translateX(-50%);
            width: 42px; height: 4px; border-radius: 4px; background: rgba(255,255,255,0.3);
        }
        .finance-app-wrapper.light .cat-dd-panel.dd-sheet .sheet-title::before { background: rgba(15,23,42,0.2); }
        .finance-app-wrapper .sheet-close {
            width: auto; margin: 0; padding: 5px 12px; font-size: 0.85rem;
            border-radius: 10px; background: rgba(255,255,255,0.1); cursor: pointer;
        }
        .finance-app-wrapper.light .sheet-close { background: rgba(15,23,42,0.08); color: #0f172a; }

        @media (max-width: 640px) {
            /* مودال‌ها هم روی موبایل به شکل شیت پایین */
            .finance-app-wrapper .modal-overlay.active { align-items: flex-end; }
            .finance-app-wrapper .modal-box {
                width: 100%; max-width: 100%;
                border-radius: 22px 22px 0 0;
                padding: 20px 18px calc(20px + env(safe-area-inset-bottom, 0px));
                max-height: 92vh; overflow-y: auto;
                animation: sheet-up 0.22s ease;
            }
        }
        @keyframes sheet-up { from { transform: translateY(14%); opacity: 0.6; } to { transform: translateY(0); opacity: 1; } }

        /* ===== انتخاب چندتایی و جمع انتخاب‌شده‌ها ===== */
        .finance-app-wrapper .transaction-item { gap: 10px; }
        .finance-app-wrapper .tx-check { width: 21px; height: 21px; flex: none; margin: 0; accent-color: #60a5fa; cursor: pointer; display: none; }
        .finance-app-wrapper.select-mode .tx-check { display: inline-block; }
        .finance-app-wrapper.select-mode .tx-actions { display: none; }
        .finance-app-wrapper.select-mode .transaction-item { padding: 10px 8px; margin: 2px 0; }
        .finance-app-wrapper.select-mode .tx-info { padding-left: 0; }
        .finance-app-wrapper .transaction-item.picked { background: rgba(96,165,250,0.16); border-radius: 12px; box-shadow: inset 0 0 0 1px rgba(96,165,250,0.35); }
        .finance-app-wrapper.light .transaction-item.picked { background: rgba(37,99,235,0.1); box-shadow: inset 0 0 0 1px rgba(37,99,235,0.3); }

        .finance-app-wrapper .select-bar { display: flex; gap: 8px; align-items: center; margin-bottom: 12px; }
        .finance-app-wrapper .select-bar .ghost-btn { margin-top: 0; }
        .finance-app-wrapper .sum-bar {
            display: none; position: sticky; bottom: 76px; z-index: 60;
            margin: 12px 0 0; padding: 12px 14px; border-radius: 16px;
            background: rgba(15,32,39,0.95); border: 1px solid var(--glass-border);
            box-shadow: 0 10px 30px rgba(0,0,0,0.45); font-size: 0.88rem;
        }
        .finance-app-wrapper.light .sum-bar { background: rgba(255,255,255,0.97); box-shadow: 0 10px 30px rgba(15,23,42,0.18); }
        .finance-app-wrapper.select-mode .sum-bar { display: block; }
        .finance-app-wrapper .sum-row { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 8px; }
        .finance-app-wrapper .sum-actions { display: flex; gap: 8px; }
        .finance-app-wrapper .sum-actions button { margin: 0; padding: 9px; font-size: 0.82rem; border-radius: 11px; background: rgba(255,255,255,0.1); cursor: pointer; }
        .finance-app-wrapper.light .sum-actions button { background: rgba(15,23,42,0.07); color: #0f172a; }
        .finance-app-wrapper .sum-actions button.primary { background: rgba(96,165,250,0.3); font-weight: bold; }
        .finance-app-wrapper.light .sum-actions button.primary { background: rgba(37,99,235,0.15); color: #1d4ed8; }

        /* ===== گروه دوم (سفر، پروژه و ...) ===== */
        .finance-app-wrapper .group-badge {
            font-size: 0.68rem; padding: 2px 7px; border-radius: 9px; margin-right: 6px;
            background: rgba(168,85,247,0.22); color: #d8b4fe; white-space: nowrap;
        }
        .finance-app-wrapper.light .group-badge { background: rgba(147,51,234,0.14); color: #7e22ce; }
        .finance-app-wrapper .group-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
        .finance-app-wrapper .group-chip { padding: 7px 12px; border-radius: 12px; font-size: 0.82rem; cursor: pointer; background: rgba(255,255,255,0.08); border: 1px solid var(--glass-border); }
        .finance-app-wrapper .group-chip.sel { background: rgba(168,85,247,0.28); font-weight: bold; }
        .finance-app-wrapper.light .group-chip { background: rgba(15,23,42,0.06); color: #0f172a; }
        .finance-app-wrapper.light .group-chip.sel { background: rgba(147,51,234,0.16); }

        /* ===== نوار وضعیت اینترنت و صف ارسال ===== */
        .finance-app-wrapper .net-bar {
            display: none; align-items: center; justify-content: space-between; gap: 10px;
            padding: 9px 13px; border-radius: 14px; margin-bottom: 14px; font-size: 0.82rem;
            background: rgba(251,191,36,0.16); border: 1px solid rgba(251,191,36,0.4); color: #fcd34d;
        }
        .finance-app-wrapper .net-bar.show { display: flex; }
        .finance-app-wrapper .net-bar.syncing { background: rgba(96,165,250,0.16); border-color: rgba(96,165,250,0.4); color: #93c5fd; }
        .finance-app-wrapper.light .net-bar { background: rgba(245,158,11,0.14); border-color: rgba(245,158,11,0.45); color: #92400e; }
        .finance-app-wrapper.light .net-bar.syncing { background: rgba(37,99,235,0.1); border-color: rgba(37,99,235,0.35); color: #1d4ed8; }
        .finance-app-wrapper .net-bar button { width: auto; margin: 0; padding: 5px 11px; font-size: 0.78rem; border-radius: 10px; background: rgba(255,255,255,0.12); color: inherit; cursor: pointer; flex: none; }
        .finance-app-wrapper.light .net-bar button { background: rgba(15,23,42,0.08); }

        .finance-app-wrapper .pending-badge { font-size: 0.68rem; padding: 2px 7px; border-radius: 9px; margin-right: 6px; background: rgba(251,191,36,0.22); color: #fcd34d; white-space: nowrap; }
        .finance-app-wrapper.light .pending-badge { background: rgba(245,158,11,0.16); color: #92400e; }
        .finance-app-wrapper .transaction-item.pending { opacity: 0.82; }

        /* ===== رویدادها (گروه دوم) ===== */
        .finance-app-wrapper .ev-btn { color: #d8b4fe; }
        .finance-app-wrapper.light .ev-btn { color: #7e22ce; }
        .finance-app-wrapper .ev-filter { display: flex; gap: 6px; overflow-x: auto; scrollbar-width: none; padding-bottom: 6px; margin-bottom: 10px; }
        .finance-app-wrapper .ev-filter::-webkit-scrollbar { display: none; }
        .finance-app-wrapper .ev-filter .group-chip { flex: none; white-space: nowrap; }
        .finance-app-wrapper .hint-line { font-size: 0.78rem; opacity: 0.7; margin-bottom: 10px; line-height: 1.7; }
    </style>
    <?php

    if(!$is_logged_in) {
        ?>
        <div class="finance-app-wrapper">
            <div class="app-container">
                <div class="glass-panel" style="margin-top: 30px;">
                    <h3>🔐 ورود به پنل مالی</h3>
                    <?php if(!empty($finance_login_error)): ?>
                        <div class="error-msg"><?php echo esc_html($finance_login_error); ?></div>
                    <?php endif; ?>
                    <form method="POST">
                        <div class="form-group">
                            <input type="password" name="finance_password" placeholder="رمز عبور" required>
                        </div>
                        <button type="submit" name="finance_login_submit">ورود امن</button>
                    </form>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'finance_transactions';
    $db_results = $wpdb->get_results("SELECT * FROM $table_name ORDER BY id DESC");
    $transactions = [];
    foreach($db_results as $row) {
        $transactions[] = [
            'id' => (int)$row->id,
            'person' => $row->person ? $row->person : 'hossein',
            'type' => $row->tx_type,
            'amount' => (int)$row->amount,
            'desc' => $row->tx_desc,
            'date' => $row->tx_date
        ];
    }

    $inst_table = $wpdb->prefix . 'finance_installments';
    $inst_results = $wpdb->get_results("SELECT * FROM $inst_table ORDER BY id DESC");
    $installments = [];
    foreach($inst_results as $row) {
        $installments[] = [
            'id' => (int)$row->id,
            'person' => $row->person ? $row->person : 'hossein',
            'title' => $row->title,
            'amount' => (int)$row->amount,
            'routine' => (bool)$row->is_routine,
            'paid' => (bool)$row->is_paid,
            'paid_month' => $row->paid_month,
            'archived' => (bool)$row->is_archived
        ];
    }

    $tx_json = wp_json_encode($transactions, JSON_HEX_TAG | JSON_HEX_AMP);
    $inst_json = wp_json_encode($installments, JSON_HEX_TAG | JSON_HEX_AMP);
    $ajax_url = admin_url('admin-ajax.php');
    ?>

    <div class="finance-app-wrapper">
        <div class="app-container">

            <script>
                try { if (localStorage.getItem('finance_theme') === 'light') document.currentScript.closest('.finance-app-wrapper').classList.add('light'); } catch(e) {}
            </script>

            <!-- تب‌های اصلی -->
            <div class="top-row">
            <div class="main-tabs">
                <button type="button" class="main-tab active" data-tab="sarina">👤 سارینا</button>
                <button type="button" class="main-tab" data-tab="hossein">👤 حسین</button>
                <button type="button" class="main-tab" data-tab="report">📊 گزارش کل</button>
            </div>
            <button type="button" class="theme-btn" id="fs-toggle" title="تمام‌صفحه"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3"></path><path d="M21 8V5a2 2 0 0 0-2-2h-3"></path><path d="M3 16v3a2 2 0 0 0 2 2h3"></path><path d="M16 21h3a2 2 0 0 0 2-2v-3"></path></svg></button>
            <button type="button" class="theme-btn" id="theme-toggle" title="تغییر حالت"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"></circle><line x1="12" y1="2" x2="12" y2="4"></line><line x1="12" y1="20" x2="12" y2="22"></line><line x1="4.93" y1="4.93" x2="6.34" y2="6.34"></line><line x1="17.66" y1="17.66" x2="19.07" y2="19.07"></line><line x1="2" y1="12" x2="4" y2="12"></line><line x1="20" y1="12" x2="22" y2="12"></line><line x1="4.93" y1="19.07" x2="6.34" y2="17.66"></line><line x1="17.66" y1="6.34" x2="19.07" y2="4.93"></line></svg></button>
            </div>

            <div class="net-bar" id="net-bar">
                <span id="net-bar-text"></span>
                <button type="button" id="net-sync-btn">تلاش مجدد</button>
            </div>

            <div class="glass-panel" id="form-panel">
                <h3 id="form-title">ثبت تراکنش</h3>
                <form id="transaction-form" onsubmit="event.preventDefault(); return false;">
                    <input type="hidden" id="edit_id" value="0">
                    <input type="hidden" id="tx_date" value="">

                    <div class="toggle-container">
                        <button type="button" class="toggle-btn active" data-value="expense">مخارج</button>
                        <button type="button" class="toggle-btn" data-value="income">درآمد</button>
                    </div>
                    <input type="hidden" id="type" value="expense">
                    <div class="form-group">
                        <input type="text" id="amount" inputmode="numeric" placeholder="مبلغ (تومان)" required>
                    </div>
                    <div class="form-group">
                        <input type="text" id="desc" placeholder="بابت چی بود؟" required>
                    </div>
                    <div class="cat-dd" id="tag-dd" style="margin-bottom: 15px;">
                        <button type="button" class="cat-dd-btn" id="tag-dd-btn">
                            <span id="tag-dd-current">انتخاب مورد آماده</span>
                            <span class="cat-dd-caret">▾</span>
                        </button>
                        <div class="cat-dd-panel">
                            <div class="sheet-title"><span>انتخاب مورد آماده</span><button type="button" class="sheet-close">بستن</button></div>
                            <input type="text" id="tag-search" class="cat-search" placeholder="جستجو...">
                            <div class="cat-items" id="tag-items"></div>
                            <div class="dd-actions">
                                <button type="button" class="dd-act" id="tag-add-btn">➕ جدید</button>
                                <button type="button" class="dd-act" id="tag-del-btn">🗑 حذف</button>
                            </div>
                        </div>
                    </div>
                    <button type="submit" id="submit-btn">ثبت تراکنش</button>
                    <button type="button" id="cancel-edit-btn" style="display: none;" onclick="window.cancelEdit()">❌ انصراف از ویرایش</button>
                </form>
            </div>

            <div class="glass-panel" id="balance-panel">
                <div class="balance-box"><span id="balance-label">موجودی کل</span>: <span id="total-balance">۰</span> تومان</div>
                <div class="stats-grid">
                    <div class="stat-item">
                        <div>سال جاری</div>
                        <div class="text-green" id="year-income">+ ۰</div>
                        <div class="text-red" id="year-expense">- ۰</div>
                    </div>
                    <div class="stat-item">
                        <div>ماه جاری</div>
                        <div class="text-green" id="month-income">+ ۰</div>
                        <div class="text-red" id="month-expense">- ۰</div>
                    </div>
                </div>
                <div id="person-breakdown" style="display:none;"></div>
            </div>

            <!-- بخش اقساط (فقط تب حسین و سارینا) -->
            <div class="glass-panel" id="inst-panel">
                <h3 id="inst-heading">📅 اقساط</h3>
                <div class="inst-summary" id="inst-summary"></div>
                <div id="inst-cat-list"></div>
            </div>

            <div class="glass-panel" id="history-container">
                <h3 id="history-title">تاریخچه</h3>

                <div class="form-group">
                    <select id="month-filter">
                        <option value="">نمایش همه ماه‌ها</option>
                    </select>
                </div>

                <div class="filter-row">
                    <div class="history-tabs">
                        <button type="button" class="history-tab active" data-filter="all">همه</button>
                        <button type="button" class="history-tab" data-filter="income">درآمد</button>
                        <button type="button" class="history-tab" data-filter="expense">مخارج</button>
                    </div>
                </div>

                <div class="filter-row">
                    <div class="history-tabs">
                        <button type="button" class="view-tab active" data-view="date">📅 روزبه‌روز</button>
                        <button type="button" class="view-tab" data-view="cat">🏷 بر اساس عنوان</button>
                        <button type="button" class="view-tab" data-view="group">🧳 بر اساس رویداد</button>
                    </div>
                </div>

                <div class="hint-line" id="view-hint"></div>
                <div class="ev-filter" id="ev-filter"></div>

                <div class="select-bar">
                    <button type="button" class="ghost-btn" id="select-mode-btn" style="flex:1;">☑️ انتخاب چند مورد (جمع‌زدن / رویداد)</button>
                </div>

                <div id="accordion-list"></div>

                <div class="sum-bar" id="sum-bar">
                    <div class="sum-row">
                        <span id="sum-count">۰ مورد انتخاب شده</span>
                        <span id="sum-net"></span>
                    </div>
                    <div class="sum-row" id="sum-detail" style="font-size:0.82rem; opacity:0.9;"></div>
                    <div class="sum-actions">
                        <button type="button" class="primary" id="sum-group-btn">🧳 گذاشتن در رویداد</button>
                        <button type="button" id="sum-all-btn">انتخاب همه</button>
                        <button type="button" id="sum-clear-btn">پاک‌کردن</button>
                    </div>
                </div>
            </div>

            <form method="POST" id="logout-form" style="display:none;">
                <input type="hidden" name="finance_logout" value="1">
            </form>

            <!-- منوی پایین -->
            <nav class="bottom-nav" id="bottom-nav">
                <button type="button" class="nav-btn active" data-section="add">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"></line></svg>
                    <span>ثبت تراکنش</span>
                </button>
                <button type="button" class="nav-btn" data-section="history">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
                    <span>تراکنش ها</span>
                </button>
                <button type="button" class="nav-btn" data-section="inst">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                    <span>اقساط</span>
                </button>
                <button type="button" class="nav-btn nav-logout" id="nav-logout-btn">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                    <span>خروج</span>
                </button>
            </nav>

        </div>

        <!-- مودال تأیید حذف -->
        <div class="modal-overlay" id="delete-modal">
            <div class="modal-box">
                <h4>حذف مورد</h4>
                <p>آیا از حذف این مورد مطمئن هستید؟ <br> این عمل قابل بازگشت نیست.</p>
                <div class="modal-buttons">
                    <button class="modal-btn cancel" onclick="window.closeModal()">انصراف</button>
                    <button class="modal-btn confirm" id="confirm-delete-btn">بله، حذف کن</button>
                </div>
            </div>
        </div>

        <!-- مودال ویرایش (تراکنش / قسط) -->
        <div class="modal-overlay" id="edit-modal">
            <div class="modal-box">
                <h4 id="edit-modal-title" style="color:inherit;">ویرایش</h4>
                <div class="toggle-container" id="em-type-wrap">
                    <button type="button" class="toggle-btn active" data-value="expense">مخارج</button>
                    <button type="button" class="toggle-btn" data-value="income">درآمد</button>
                </div>
                <div class="form-group">
                    <input type="text" id="em-amount" inputmode="numeric" placeholder="مبلغ (تومان)">
                </div>
                <div class="form-group" id="em-cat-wrap">
                    <div class="cat-dd" id="em-cat-dd">
                        <button type="button" class="cat-dd-btn" id="em-cat-btn">
                            <span id="em-cat-current">انتخاب دسته</span>
                            <span class="cat-dd-caret">▾</span>
                        </button>
                        <div class="cat-dd-panel">
                            <div class="sheet-title"><span>انتخاب دسته</span><button type="button" class="sheet-close">بستن</button></div>
                            <input type="text" id="em-cat-search" class="cat-search" placeholder="جستجو...">
                            <div class="cat-items" id="em-cat-items"></div>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <input type="text" id="em-desc" placeholder="بابت چی بود؟">
                </div>
                <label class="routine-check" id="em-routine-wrap" style="justify-content:center;">
                    <input type="checkbox" id="em-routine">
                    <span>🔁 قسط روتین (هر ماه)</span>
                </label>
                <div class="modal-buttons" style="margin-top:20px;">
                    <button class="modal-btn cancel" onclick="window.closeEditModal()">انصراف</button>
                    <button class="modal-btn success" id="em-save-btn">💾 ذخیره</button>
                </div>
            </div>
        </div>

        <!-- مودال تأیید خروج -->
        <div class="modal-overlay" id="logout-modal">
            <div class="modal-box">
                <h4>خروج از حساب</h4>
                <p>آیا می‌خواهید از حساب کاربری خارج شوید؟</p>
                <div class="modal-buttons">
                    <button class="modal-btn cancel" onclick="window.closeLogoutModal()">انصراف</button>
                    <button class="modal-btn confirm" id="confirm-logout-btn">بله، خارج شو</button>
                </div>
            </div>
        </div>

        <!-- مودال افزودن تگ اختصاصی -->
        <div class="modal-overlay" id="add-tag-modal">
            <div class="modal-box">
                <h4 id="add-tag-title">افزودن مورد آماده</h4>
                <div class="form-group" style="margin-bottom: 14px;">
                    <input type="text" id="new-tag-input" placeholder="مثال: اینترنت، باشگاه و..." style="text-align: right; direction: rtl;">
                </div>
                <div class="toggle-container" id="new-tag-type" style="margin-bottom: 14px;">
                    <button type="button" class="toggle-btn active" data-value="expense">مخارج</button>
                    <button type="button" class="toggle-btn" data-value="income">درآمد</button>
                </div>
                <div class="icon-hint">آیکون را انتخاب کنید: <b id="icon-preview">⭐</b></div>
                <div class="icon-grid" id="icon-grid"></div>
                <div class="modal-buttons">
                    <button class="modal-btn cancel" onclick="window.closeTagModal()">انصراف</button>
                    <button class="modal-btn success" id="save-new-tag-btn">➕ افزودن</button>
                </div>
            </div>
        </div>

        <!-- مودال دسته‌بندی گروهی (گروه دوم) -->
        <div class="modal-overlay" id="group-modal">
            <div class="modal-box">
                <h4 id="group-modal-title" style="color:inherit;">🧳 این خرج‌ها برای چی بود؟</h4>
                <p id="group-modal-sub" style="margin-bottom:14px;"></p>
                <div class="group-chips" id="group-chips"></div>
                <div class="form-group">
                    <input type="text" id="group-input" placeholder="مثلاً: سفر شمال، عروسی، بازسازی خونه" style="text-align:right; direction:rtl;">
                </div>
                <div class="modal-buttons">
                    <button class="modal-btn cancel" onclick="window.closeGroupModal()">انصراف</button>
                    <button class="modal-btn success" id="group-save-btn">💾 ثبت</button>
                </div>
                <button type="button" class="ghost-btn" id="group-remove-btn" style="width:100%;">🚫 بیرون آوردن از رویداد</button>
            </div>
        </div>

        <div class="sheet-backdrop" id="sheet-backdrop"></div>

    </div>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const formatter = new Intl.NumberFormat('fa-IR');
            const plain = new Intl.NumberFormat('fa-IR', { useGrouping: false });
            const fmt = n => formatter.format(n);
            let transactions = <?php echo $tx_json ?: '[]'; ?>;
            let installments = <?php echo $inst_json ?: '[]'; ?>;
            const ajaxUrl = "<?php echo esc_url($ajax_url); ?>";

            const PEOPLE = { sarina: 'سارینا', hossein: 'حسین' };
            const MONTHS = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];

            let currentTab = 'sarina';        // sarina | hossein | report
            let currentSection = 'add';       // add | inst | history
            let currentFilter = 'all';
            let currentMonthFilter = '';
            let itemToDelete = null;          // {kind: 'tx'|'inst', id}
            let currentView = 'date';         // date | cat | group
            let selectMode = false;
            let selectedIds = new Set();
            let groupFilter = '';             // فیلتر نمایش بر اساس گروه دوم
            let lastFilteredTx = [];          // آخرین لیست نمایش‌داده‌شده در تاریخچه

            /* ---------- گروه دوم (سفر / پروژه / مناسبت) ---------- */
            const GROUP_KEY = 'finance_tx_groups';
            function readGroups() {
                try { const v = JSON.parse(localStorage.getItem(GROUP_KEY)); return (v && typeof v === 'object') ? v : {}; } catch(e) { return {}; }
            }
            function writeGroups(obj) {
                try { localStorage.setItem(GROUP_KEY, JSON.stringify(obj)); } catch(e) {}
            }
            let txGroups = readGroups();
            function groupOf(id) { return txGroups[id] || ''; }
            function setGroup(ids, name) {
                ids.forEach(id => { if (name) txGroups[id] = name; else delete txGroups[id]; });
                writeGroups(txGroups);
            }
            function allGroupNames() {
                const s = new Set();
                Object.keys(txGroups).forEach(k => { if (txGroups[k]) s.add(txGroups[k]); });
                return Array.from(s).sort((a, b) => a.localeCompare(b, 'fa'));
            }

            const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

            const ICON_EDIT = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
            const ICON_DEL = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>';

            /* یک ردیف تراکنش (مشترک بین نمای روزانه، دسته‌بندی، گروه و اقساط) */
            function txRowHtml(item, opts) {
                opts = opts || {};
                const isReport = currentTab === 'report';
                const g = groupOf(item.id);
                const pend = isPending(item.id);
                // خط اول: عنوان کامل (در نمای دسته/رویداد که عنوان بالای کشوست، تاریخ)
                const title = opts.showDesc === false ? dateLabel(item.date) : String(item.desc);
                // خط دوم: نشان‌ها + مبلغ. در نمای روزانه تاریخ تکراری است، پس نمی‌آید.
                const meta = (isReport ? '<span class="person-badge ' + esc(item.person) + '">' + esc(PEOPLE[item.person] || '') + '</span>' : '') +
                    (g ? '<span class="group-badge">🧳 ' + esc(g) + '</span>' : '') +
                    (pend ? '<span class="pending-badge">⏳ در صف</span>' : '');
                return '<div class="transaction-item' + (selectedIds.has(item.id) ? ' picked' : '') + (pend ? ' pending' : '') + '" data-tx="' + item.id + '">' +
                    '<input type="checkbox" class="tx-check" data-check="' + item.id + '"' + (selectedIds.has(item.id) ? ' checked' : '') + '>' +
                    '<div class="tx-info">' +
                        '<span class="tx-desc" title="' + esc(title) + '">' + esc(title) + '</span>' +
                        '<div class="tx-sub">' +
                            '<div class="tx-meta">' + meta + '</div>' +
                            '<span class="tx-amount ' + (item.type === 'income' ? 'text-green' : 'text-red') + '">' + (item.type === 'income' ? '+' : '-') + fmt(item.amount) + '</span>' +
                        '</div>' +
                    '</div>' +
                    '<div class="tx-actions">' +
                        '<button type="button" onclick="window.setEvent(' + item.id + ')" class="action-btn ev-btn" title="' + (g ? 'رویداد: ' + esc(g) : 'گذاشتن در یک رویداد') + '">🧳</button>' +
                        '<button type="button" onclick="window.editTx(' + item.id + ')" class="action-btn" title="ویرایش">' + ICON_EDIT + '</button>' +
                        '<button type="button" onclick="window.deleteTx(' + item.id + ')" class="action-btn" title="حذف">' + ICON_DEL + '</button>' +
                    '</div></div>';
            }

            /* ---------- تبدیل میلادی به شمسی (رفع مشکل یک ماه عقب بودن) ---------- */
            function g2j(gy, gm, gd) {
                const gdm = [0,31,59,90,120,151,181,212,243,273,304,334];
                let jy = (gy > 1600) ? 979 : 0;
                gy -= (gy > 1600) ? 1600 : 621;
                const gy2 = (gm > 2) ? (gy + 1) : gy;
                let days = (365 * gy) + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100) + Math.floor((gy2 + 399) / 400) - 80 + gd + gdm[gm - 1];
                jy += 33 * Math.floor(days / 12053);
                days %= 12053;
                jy += 4 * Math.floor(days / 1461);
                days %= 1461;
                if (days > 365) {
                    jy += Math.floor((days - 1) / 365);
                    days = (days - 1) % 365;
                }
                const jm = (days < 186) ? 1 + Math.floor(days / 31) : 7 + Math.floor((days - 186) / 30);
                const jd = 1 + ((days < 186) ? (days % 31) : ((days - 186) % 30));
                return [jy, jm, jd];
            }
            function jalaliOf(dateStr) {
                const p = dateStr.substring(0, 10).split('-').map(Number);
                return g2j(p[0], p[1], p[2]);
            }
            function monthKey(dateStr) {
                const j = jalaliOf(dateStr);
                return j[0] + '-' + j[1];
            }
            function monthLabel(key) {
                const [y, m] = key.split('-').map(Number);
                return MONTHS[m - 1] + ' ' + plain.format(y);
            }
            function dateLabel(dateStr) {
                const [y, m, d] = jalaliOf(dateStr);
                return plain.format(d) + ' ' + MONTHS[m - 1] + ' ' + plain.format(y);
            }
            function pad(n) { return String(n).padStart(2, '0'); }
            function ymd(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
            function nowInfo() {
                const n = new Date();
                const j = g2j(n.getFullYear(), n.getMonth() + 1, n.getDate());
                return { monthKey: j[0] + '-' + j[1], year: j[0] };
            }

            function parsePersianInt(str) {
                if (!str) return 0;
                let val = str.toString();
                const persian = [/۰/g, /۱/g, /۲/g, /۳/g, /۴/g, /۵/g, /۶/g, /۷/g, /۸/g, /۹/g];
                const arabic  = [/٠/g, /١/g, /٢/g, /٣/g, /٤/g, /٥/g, /٦/g, /٧/g, /٨/g, /٩/g];
                for(let i=0; i<10; i++) val = val.replace(persian[i], i).replace(arabic[i], i);
                const parsed = parseInt(val.replace(/\D/g, ''), 10);
                return isNaN(parsed) ? 0 : parsed;
            }

            /* ================================================================
               موتور آفلاین: هر ثبت/ویرایش/حذف اول محلی اعمال می‌شود و در صف
               localStorage می‌نشیند؛ هر وقت اینترنت بیاید خودش ارسال می‌شود.
               ================================================================ */
            const QUEUE_KEY = 'finance_pending_ops';
            const CACHE_KEY = 'finance_tx_cache';
            let pendingOps = [];
            let syncing = false;
            let lastSyncError = '';

            function readQueue() {
                try { const v = JSON.parse(localStorage.getItem(QUEUE_KEY)); return Array.isArray(v) ? v : []; } catch(e) { return []; }
            }
            function saveQueue() {
                try { localStorage.setItem(QUEUE_KEY, JSON.stringify(pendingOps)); } catch(e) {}
            }
            function saveCache() {
                try { localStorage.setItem(CACHE_KEY, JSON.stringify({ at: Date.now(), tx: transactions })); } catch(e) {}
            }
            function readCache() {
                try { const v = JSON.parse(localStorage.getItem(CACHE_KEY)); return (v && Array.isArray(v.tx)) ? v : null; } catch(e) { return null; }
            }

            let tmpCounter = -1;
            function nextTmpId() { return tmpCounter--; }
            function isTmp(id) { return id < 0; }

            let pendingIds = new Set();
            function refreshPendingIds() {
                pendingIds = new Set();
                pendingOps.forEach(op => pendingIds.add(op.id));
            }
            function isPending(id) { return pendingIds.has(id); }

            function enqueue(op) {
                pendingOps.push(op);
                saveQueue();
                refreshPendingIds();
                flushQueue();
            }

            function rawPost(obj) {
                const fd = new URLSearchParams();
                Object.keys(obj).forEach(k => fd.append(k, obj[k]));
                return fetch(ajaxUrl, {
                    method: 'POST',
                    body: fd,
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                }).then(res => res.json());
            }

            function remapId(oldId, newId, newDate) {
                const tx = transactions.find(t => t.id === oldId);
                if (tx) { tx.id = newId; if (newDate) tx.date = newDate; }
                if (txGroups[oldId]) { txGroups[newId] = txGroups[oldId]; delete txGroups[oldId]; writeGroups(txGroups); }
                if (selectedIds.has(oldId)) { selectedIds.delete(oldId); selectedIds.add(newId); }
                pendingOps.forEach(op => { if (op.id === oldId) op.id = newId; if (op.payload && String(op.payload.edit_id) === String(oldId)) op.payload.edit_id = newId; });
            }

            function flushQueue() {
                if (syncing) return Promise.resolve();
                if (!pendingOps.length) { renderNetBar(); return Promise.resolve(); }
                if (typeof navigator !== 'undefined' && navigator.onLine === false) { renderNetBar(); return Promise.resolve(); }

                syncing = true;
                lastSyncError = '';
                renderNetBar();

                const step = () => {
                    if (!pendingOps.length) return Promise.resolve();
                    const op = pendingOps[0];
                    const body = Object.assign({}, op.payload);
                    if (op.kind === 'create') body.edit_id = 0;
                    return rawPost(body).then(r => {
                        if (r && r.success) {
                            if (op.kind === 'create' && r.data && r.data.id) remapId(op.id, r.data.id, r.data.date);
                            pendingOps.shift();
                            saveQueue(); refreshPendingIds();
                            return step();
                        }
                        // سرور جواب داد ولی قبول نکرد: این مورد را دور می‌ریزیم تا صف گیر نکند
                        pendingOps.shift();
                        saveQueue(); refreshPendingIds();
                        lastSyncError = 'یک مورد از طرف سرور رد شد و از صف حذف شد.';
                        return step();
                    });
                };

                return step()
                    .catch(() => { lastSyncError = 'اینترنت وصل نشد؛ موارد در صف ماندند.'; })
                    .finally(() => {
                        syncing = false;
                        saveCache();
                        renderMonthSelect();
                        updateUI();
                    });
            }

            function renderNetBar() {
                const bar = document.getElementById('net-bar');
                const txt = document.getElementById('net-bar-text');
                if (!bar || !txt) return;
                const n = pendingOps.length;
                const offline = (typeof navigator !== 'undefined' && navigator.onLine === false);
                bar.classList.remove('syncing');
                if (n === 0 && !offline) { bar.classList.remove('show'); return; }
                bar.classList.add('show');
                if (syncing) {
                    bar.classList.add('syncing');
                    txt.textContent = 'در حال ارسال ' + plain.format(n) + ' مورد...';
                } else if (offline) {
                    txt.textContent = n ? '📴 آفلاین — ' + plain.format(n) + ' مورد ذخیره شده، با وصل‌شدن نت ارسال می‌شود' : '📴 آفلاین — تغییرات ذخیره می‌شود';
                } else {
                    txt.textContent = '⏳ ' + plain.format(n) + ' مورد در صف ارسال' + (lastSyncError ? ' — ' + lastSyncError : '');
                }
            }

            function post(obj) {
                const fd = new URLSearchParams();
                Object.keys(obj).forEach(k => fd.append(k, obj[k]));
                return fetch(ajaxUrl, {
                    method: 'POST',
                    body: fd,
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                }).then(res => res.json());
            }

            function visibleTx() {
                return currentTab === 'report' ? transactions : transactions.filter(t => t.person === currentTab);
            }

            /* ---------- فیلتر ماه (شمسی) ---------- */
            function renderMonthSelect() {
                const select = document.getElementById('month-filter');
                const currentValue = currentMonthFilter;

                const months = new Set();
                visibleTx().forEach(t => months.add(monthKey(t.date)));
                const available = Array.from(months).sort((a, b) => {
                    const [ay, am] = a.split('-').map(Number), [by, bm] = b.split('-').map(Number);
                    return (by * 100 + bm) - (ay * 100 + am);
                });

                select.innerHTML = '<option value="">نمایش همه ماه‌ها</option>';
                available.forEach(key => {
                    const option = document.createElement('option');
                    option.value = key;
                    option.textContent = monthLabel(key);
                    if(key === currentValue) option.selected = true;
                    select.appendChild(option);
                });
                if (!available.includes(currentValue)) currentMonthFilter = '';
            }

            document.getElementById('month-filter').addEventListener('change', (e) => {
                currentMonthFilter = e.target.value;
                updateUI();
            });

            const historyTabs = document.querySelectorAll('.history-tab');
            historyTabs.forEach(tab => {
                tab.addEventListener('click', () => {
                    historyTabs.forEach(t => t.classList.remove('active'));
                    tab.classList.add('active');
                    currentFilter = tab.getAttribute('data-filter');
                    updateUI();
                });
            });

            /* ---------- تب‌های اصلی ---------- */
            const mainTabs = document.querySelectorAll('.main-tab');
            mainTabs.forEach(btn => {
                btn.addEventListener('click', () => setTab(btn.getAttribute('data-tab')));
            });

            /* ---------- حالت روشن / تیره ---------- */
            const appRoot = document.querySelector('.finance-app-wrapper');
            const themeBtn = document.getElementById('theme-toggle');
            const THEME_SUN = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"></circle><line x1="12" y1="2" x2="12" y2="4"></line><line x1="12" y1="20" x2="12" y2="22"></line><line x1="4.93" y1="4.93" x2="6.34" y2="6.34"></line><line x1="17.66" y1="17.66" x2="19.07" y2="19.07"></line><line x1="2" y1="12" x2="4" y2="12"></line><line x1="20" y1="12" x2="22" y2="12"></line><line x1="4.93" y1="19.07" x2="6.34" y2="17.66"></line><line x1="17.66" y1="6.34" x2="19.07" y2="4.93"></line></svg>';
            const THEME_MOON = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>';
            function syncThemeBtn() {
                const light = appRoot.classList.contains('light');
                themeBtn.innerHTML = light ? THEME_MOON : THEME_SUN;
                themeBtn.title = light ? 'حالت تیره' : 'حالت روشن';
            }
            themeBtn.addEventListener('click', () => {
                appRoot.classList.toggle('light');
                try { localStorage.setItem('finance_theme', appRoot.classList.contains('light') ? 'light' : 'dark'); } catch(e) {}
                syncThemeBtn();
            });
            syncThemeBtn();

            /* ---------- تمام‌صفحه ---------- */
            const fsBtn = document.getElementById('fs-toggle');
            const fsRoot = document.documentElement;
            const reqFs = fsRoot.requestFullscreen || fsRoot.webkitRequestFullscreen;
            const exitFs = document.exitFullscreen || document.webkitExitFullscreen;
            const isStandalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches)
                || (window.matchMedia && window.matchMedia('(display-mode: fullscreen)').matches)
                || window.navigator.standalone === true;
            // در آیفون یا وقتی اپ از صفحه اصلی باز شده، دکمه لازم نیست
            if (!reqFs || isStandalone) fsBtn.style.display = 'none';
            const FS_ICON = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3"></path><path d="M21 8V5a2 2 0 0 0-2-2h-3"></path><path d="M3 16v3a2 2 0 0 0 2 2h3"></path><path d="M16 21h3a2 2 0 0 0 2-2v-3"></path></svg>';
            const FS_EXIT_ICON = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3v3a2 2 0 0 1-2 2H3"></path><path d="M21 8h-3a2 2 0 0 1-2-2V3"></path><path d="M3 16h3a2 2 0 0 1 2 2v3"></path><path d="M16 21v-3a2 2 0 0 1 2-2h3"></path></svg>';
            function syncFsBtn() {
                const active = document.fullscreenElement || document.webkitFullscreenElement;
                fsBtn.innerHTML = active ? FS_EXIT_ICON : FS_ICON;
                fsBtn.title = active ? 'خروج از تمام‌صفحه' : 'تمام‌صفحه';
            }
            fsBtn.addEventListener('click', () => {
                const active = document.fullscreenElement || document.webkitFullscreenElement;
                if (active) { if (exitFs) exitFs.call(document); }
                else if (reqFs) { const p = reqFs.call(fsRoot); if (p && p.catch) p.catch(() => {}); }
            });
            document.addEventListener('fullscreenchange', syncFsBtn);
            document.addEventListener('webkitfullscreenchange', syncFsBtn);

            /* ---------- منوی پایین (تعویض بخش بدون رفرش) ---------- */
            const SECTION_PANELS = { add: ['form-panel'], inst: ['inst-panel'], history: ['balance-panel', 'history-container'] };

            function applyView() {
                const isReport = currentTab === 'report';
                const personOnly = s => (s === 'add');
                Object.keys(SECTION_PANELS).forEach(s => {
                    SECTION_PANELS[s].forEach(id => {
                        document.getElementById(id).style.display = (s === currentSection && !(isReport && personOnly(s))) ? 'block' : 'none';
                    });
                });
                document.querySelectorAll('.nav-btn[data-section]').forEach(b => {
                    const s = b.getAttribute('data-section');
                    b.style.display = (isReport && personOnly(s)) ? 'none' : 'flex';
                    b.classList.toggle('active', s === currentSection);
                });
            }

            function setSection(sec) {
                currentSection = sec;
                applyView();
            }

            document.querySelectorAll('.nav-btn[data-section]').forEach(btn => {
                btn.addEventListener('click', () => setSection(btn.getAttribute('data-section')));
            });

            /* ---------- خروج با تأیید ---------- */
            document.getElementById('nav-logout-btn').addEventListener('click', () => {
                document.getElementById('logout-modal').classList.add('active');
            });
            window.closeLogoutModal = function() {
                document.getElementById('logout-modal').classList.remove('active');
            };
            document.getElementById('confirm-logout-btn').addEventListener('click', function() {
                this.textContent = 'در حال خروج...';
                this.disabled = true;
                document.getElementById('logout-form').submit();
            });

            function formTitleText() {
                return 'ثبت تراکنش ' + (PEOPLE[currentTab] || '');
            }

            function setTab(tab) {
                currentTab = tab;
                mainTabs.forEach(b => b.classList.toggle('active', b.getAttribute('data-tab') === tab));
                const isReport = tab === 'report';

                // با هر تعویض تب، به «ثبت تراکنش» برمی‌گردیم (در گزارش کل که ثبت ندارد، «تراکنش ها»)
                currentSection = isReport ? 'history' : 'add';
                applyView();
                document.getElementById('person-breakdown').style.display = isReport ? 'block' : 'none';
                document.getElementById('balance-label').textContent = isReport ? 'موجودی کل (حسین + سارینا)' : 'موجودی ' + PEOPLE[tab];
                document.getElementById('history-title').textContent = isReport ? 'تاریخچه کل' : 'تاریخچه ' + PEOPLE[tab];

                currentMonthFilter = '';
                groupFilter = '';
                selectedIds.clear();
                window.cancelEdit();
                renderTags();
                renderMonthSelect();
                updateUI();
            }

            /* ---------- ویرایش / حذف تراکنش ---------- */
            window.editTx = function(id) {
                const tx = transactions.find(t => t.id === id);
                if(!tx) return;
                openEditModal('tx', tx);
            };

            /* ---------- مودال ویرایش (تراکنش و قسط) ---------- */
            const emModal = document.getElementById('edit-modal');
            const emAmount = document.getElementById('em-amount');
            const emDesc = document.getElementById('em-desc');
            const emRoutine = document.getElementById('em-routine');
            const emSave = document.getElementById('em-save-btn');
            let emTarget = null;
            let emType = 'expense';

            function setEmType(t) {
                emType = t;
                document.querySelectorAll('#em-type-wrap .toggle-btn').forEach(b => b.classList.toggle('active', b.getAttribute('data-value') === t));
            }
            document.querySelectorAll('#em-type-wrap .toggle-btn').forEach(b => {
                b.addEventListener('click', () => setEmType(b.getAttribute('data-value')));
            });
            bindAmountFormat(emAmount);

            let emCats = [];
            let emCatKey = '';

            function fillEmCats(currentDesc) {
                const map = new Map();
                [...tagsOf('main'), ...tagsOf('inst')].forEach(t => {
                    const label = t.replace('📌 ', '');
                    const k = normText(label);
                    if (k && !map.has(k)) map.set(k, label);
                });
                transactions.forEach(t => {
                    const k = normText(t.desc);
                    if (k && !map.has(k)) map.set(k, t.desc);
                });
                emCats = [];
                map.forEach((label, k) => emCats.push({ key: k, label: label }));
                emCatKey = normText(currentDesc);

                closeAllDd();
                document.getElementById('em-cat-search').value = '';
                document.getElementById('em-cat-current').textContent = currentDesc || 'انتخاب دسته';
                renderCatItems('');
            }

            function renderCatItems(filter) {
                const box = document.getElementById('em-cat-items');
                if (!box) return;
                const f = normText(filter);
                const list = f ? emCats.filter(c => c.key.indexOf(f) >= 0) : emCats;
                box.innerHTML = '';
                if (list.length === 0) {
                    box.innerHTML = '<div class="cat-empty">موردی پیدا نشد</div>';
                    return;
                }
                list.forEach(c => {
                    const el = document.createElement('div');
                    el.className = 'cat-item' + (c.key === emCatKey ? ' sel' : '');
                    el.textContent = c.label;
                    el.addEventListener('click', () => {
                        emCatKey = c.key;
                        emDesc.value = c.label;
                        document.getElementById('em-cat-current').textContent = c.label;
                        closeAllDd();
                        renderCatItems('');
                    });
                    box.appendChild(el);
                });
            }

            function openEditModal(kind, item) {
                emTarget = { kind: kind, id: item.id };
                const isTx = kind === 'tx';
                document.getElementById('em-type-wrap').style.display = isTx ? 'flex' : 'none';
                document.getElementById('em-routine-wrap').style.display = isTx ? 'none' : 'flex';
                document.getElementById('em-cat-wrap').style.display = isTx ? 'block' : 'none';
                document.getElementById('edit-modal-title').textContent = (isTx ? 'ویرایش تراکنش ' : 'ویرایش قسط ') + (PEOPLE[item.person] || '');
                emAmount.value = fmt(item.amount);
                emDesc.value = isTx ? item.desc : item.title;
                emDesc.placeholder = isTx ? 'بابت چی بود؟' : 'عنوان قسط';
                if (isTx) { setEmType(item.type); fillEmCats(item.desc); } else emRoutine.checked = !!item.routine;
                emModal.classList.add('active');
            }

            document.getElementById('em-cat-btn').addEventListener('click', function(e) {
                e.stopPropagation();
                const dd = document.getElementById('em-cat-dd');
                if (dd.classList.contains('open')) { closeAllDd(); return; }
                openDd(dd);
                const se = document.getElementById('em-cat-search');
                se.value = '';
                renderCatItems('');
                if (!isMobile()) se.focus();
            });
            document.getElementById('em-cat-search').addEventListener('input', function() {
                renderCatItems(this.value);
            });
            document.getElementById('em-cat-search').addEventListener('keydown', function(e) {
                if (e.key === 'Enter') e.preventDefault();
            });

            window.closeEditModal = function() {
                emTarget = null;
                closeAllDd();
                emModal.classList.remove('active');
            };

            emSave.addEventListener('click', function() {
                if (!emTarget) return;
                const amount = parsePersianInt(emAmount.value);
                const text = emDesc.value.trim();
                if (amount <= 0 || !text) { alert('مبلغ و عنوان را وارد کنید'); return; }

                const t = emTarget;
                emSave.disabled = true;
                emSave.textContent = 'در حال ذخیره...';

                if (t.kind === 'tx') {
                    const tx = transactions.find(x => x.id === t.id);
                    if (tx) {
                        tx.type = emType; tx.amount = amount; tx.desc = text;
                        const queuedCreate = pendingOps.find(o => o.kind === 'create' && o.id === tx.id);
                        if (queuedCreate) {
                            // هنوز ارسال نشده؛ همان مورد در صف را اصلاح می‌کنیم
                            queuedCreate.payload.tx_type = emType;
                            queuedCreate.payload.amount = amount;
                            queuedCreate.payload.tx_desc = text;
                            saveQueue(); flushQueue();
                        } else {
                            enqueue({ kind: 'update', id: tx.id, payload: { action: 'save_finance_transaction', edit_id: tx.id, person: tx.person, tx_type: emType, amount: amount, tx_desc: text, tx_date: tx.date } });
                        }
                        saveCache();
                        renderMonthSelect();
                        updateUI();
                    }
                }
                window.closeEditModal();
                emSave.disabled = false; emSave.textContent = '💾 ذخیره';
            });
            [emAmount, emDesc].forEach(el => el.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); emSave.click(); } }));

            window.cancelEdit = function() {
                document.getElementById('transaction-form').reset();
                document.getElementById('edit_id').value = '0';
                document.getElementById('tx_date').value = '';
                document.getElementById('amount').value = '';
                document.querySelectorAll('#transaction-form .toggle-btn').forEach(b => b.classList.toggle('active', b.getAttribute('data-value') === 'expense'));
                document.getElementById('type').value = 'expense';

                resetTagPick();
                document.getElementById('form-title').textContent = formTitleText();
                const submitBtn = document.getElementById('submit-btn');
                submitBtn.textContent = 'ثبت تراکنش';
                submitBtn.style.background = '';
                submitBtn.style.color = '';

                document.getElementById('cancel-edit-btn').style.display = 'none';
            };

            window.deleteTx = function(id) {
                itemToDelete = { kind: 'tx', id: id };
                document.getElementById('delete-modal').classList.add('active');
            };

            window.closeModal = function() {
                itemToDelete = null;
                document.getElementById('delete-modal').classList.remove('active');
            };

            document.getElementById('confirm-delete-btn').addEventListener('click', function() {
                if(!itemToDelete) return;
                const item = itemToDelete;
                const btn = this;

                if (item.kind === 'tx') {
                    transactions = transactions.filter(t => t.id !== item.id);
                    selectedIds.delete(item.id);
                    setGroup([item.id], '');

                    const qi = pendingOps.findIndex(o => o.kind === 'create' && o.id === item.id);
                    if (qi >= 0) {
                        // هنوز ارسال نشده بود؛ فقط از صف برش می‌داریم
                        pendingOps.splice(qi, 1);
                        pendingOps = pendingOps.filter(o => o.id !== item.id);
                        saveQueue(); refreshPendingIds();
                    } else {
                        pendingOps = pendingOps.filter(o => o.id !== item.id);
                        saveQueue();
                        enqueue({ kind: 'delete', id: item.id, payload: { action: 'delete_finance_transaction', id: item.id } });
                    }
                    saveCache();
                    renderMonthSelect();
                    updateUI();
                }
                window.closeModal();
                btn.textContent = 'بله، حذف کن';
                btn.disabled = false;
            });

            /* ---------- موارد آماده (کشویی با جستجو) ---------- */
            const DEFAULT_TAGS = ['🚕 اسنپ', '☕ کافه', '🛒 سوپر', '⛽ بنزین', '🍽 غذا', '💳 اقساط', '💰 حقوق', '🎧 پشتیبانی', '👤 اکانت', '💆 لیزر', '💬 مشاوره', '🎬 سینما', '🎭 تئاتر', '💧 آب'];
            const DEFAULT_INST_TAGS = ['🛍 اقساط دیجی پی', '🏦 اقساط تارا', '🚕 اقساط اسنپ', '🪙 اقساط اوانو', '🔍 اقساط ترب پی', '💙 اقساط بلو', '🛡 اقساط ازکی', '👨 اقساط قرعه کشی حسین', '👩 اقساط قرعه کشی سارینا'];
            const DEFAULT_INCOME_TAGS = ['💰 حقوق', '🎧 پشتیبانی', '👤 اکانت'];
            const INST_TAG = '💳 اقساط';
            const TAG_KEYS = {
                main: { custom: 'finance_custom_tags', hidden: 'finance_hidden_tags', base: DEFAULT_TAGS },
                inst: { custom: 'finance_inst_custom_tags', hidden: 'finance_inst_hidden_tags', base: DEFAULT_INST_TAGS }
            };
            const INCOME_KEY = 'finance_income_tags';
            let instTagsOpen = false;
            let tagDelMode = false;
            let tagModalScope = 'main';
            const ICON_CHOICES = ['⭐','🏷','🛒','🍽','☕','🍔','🍕','🥤','🚕','🚗','🚌','⛽','🛵','🏠','💡','💧','🔥','📱','💻','🌐','📶','🎬','🎭','🎮','🎧','🎁','👕','👟','💄','💆','💊','🏥','💉','🦷','📚','📝','🏫','🏦','💳','💰','💵','📈','🧾','🛡','🪙','🐱','🌳','🌴','🛫','⚽','🏃','👤','👨','👩','👶','🎂','💍','🔧','🧹','🔍','💙'];
            let selectedIcon = '⭐';
            let newTagKind = 'expense';
            const TRANSFER_PREFIX = '⇄ دریافتی از ';
            function otherPerson(p) { return p === 'sarina' ? 'hossein' : 'sarina'; }
            function transferTagFor(tab) { return TRANSFER_PREFIX + PEOPLE[otherPerson(tab)]; }

            function readTagList(key) {
                try { const v = JSON.parse(localStorage.getItem(key)); return Array.isArray(v) ? v : []; } catch(e) { return []; }
            }
            function writeTagList(key, list) {
                try { localStorage.setItem(key, JSON.stringify(list)); } catch(e) {}
            }
            function tagsOf(scope) {
                const k = TAG_KEYS[scope];
                const hidden = readTagList(k.hidden);
                return [...k.base, ...readTagList(k.custom)].filter(t => hidden.indexOf(t) < 0);
            }
            function mainTags() {
                let list = tagsOf('main');
                if (currentTab !== 'report') {
                    const tt = transferTagFor(currentTab);
                    if (readTagList(TAG_KEYS.main.hidden).indexOf(tt) < 0) {
                        list = [tt, ...list.filter(x => x !== tt)];
                    }
                }
                return list;
            }
            function isIncomeTag(tagText) {
                if (tagText.indexOf(TRANSFER_PREFIX) === 0) return true;
                if (DEFAULT_INCOME_TAGS.indexOf(tagText) >= 0) return true;
                return readTagList(INCOME_KEY).indexOf(tagText) >= 0;
            }
            function removeTag(scope, tagText) {
                const k = TAG_KEYS[scope];
                const customs = readTagList(k.custom);
                const i = customs.indexOf(tagText);
                if (i >= 0) {
                    customs.splice(i, 1);
                    writeTagList(k.custom, customs);
                } else {
                    const hidden = readTagList(k.hidden);
                    if (hidden.indexOf(tagText) < 0) {
                        hidden.push(tagText);
                        writeTagList(k.hidden, hidden);
                    }
                }
                const inc = readTagList(INCOME_KEY).filter(x => x !== tagText);
                writeTagList(INCOME_KEY, inc);
                renderTags();
            }

            function setTxType(kind) {
                const btn = document.querySelector('#transaction-form .toggle-btn[data-value="' + kind + '"]');
                if (btn) btn.click();
            }

            function closeTagDd() { closeAllDd(); }

            function resetTagPick() {
                document.getElementById('tag-dd-current').textContent = 'انتخاب مورد آماده';
                closeTagDd();
            }

            function pickTag(tagText) {
                const clean = tagText.replace('📌 ', '');
                document.getElementById('desc').value = clean;
                document.getElementById('tag-dd-current').textContent = clean;
                setTxType(isIncomeTag(tagText) ? 'income' : 'expense');
                closeTagDd();
            }

            function tagItemEl(tagText, scope, opts) {
                opts = opts || {};
                const el = document.createElement('div');
                el.className = 'cat-item' + (opts.child ? ' tag-child' : '') + (opts.group ? ' tag-group' : '') + (opts.group && instTagsOpen ? ' open' : '');
                if (tagDelMode) {
                    const x = document.createElement('span');
                    x.className = 'item-x';
                    x.textContent = '✕ ';
                    el.appendChild(x);
                }
                const label = document.createElement('span');
                label.textContent = tagText.replace('📌 ', '');
                el.appendChild(label);
                if (opts.group) {
                    const c = document.createElement('span');
                    c.className = 'grp-caret';
                    c.textContent = '▾';
                    el.appendChild(c);
                }
                el.addEventListener('click', () => {
                    if (tagDelMode) { removeTag(scope, tagText); return; }
                    if (opts.group) { instTagsOpen = !instTagsOpen; renderTagItems(document.getElementById('tag-search').value); return; }
                    pickTag(tagText);
                });
                return el;
            }

            function renderTagItems(filter) {
                const box = document.getElementById('tag-items');
                if (!box) return;
                const f = normText(filter || '');
                const inst = tagsOf('inst');
                const hit = t => !f || normText(t).indexOf(f) >= 0;
                box.innerHTML = '';
                let any = false;

                mainTags().forEach(t => {
                    const isGroup = (t === INST_TAG);
                    if (f) {
                        if (hit(t)) { any = true; box.appendChild(tagItemEl(t, 'main', {})); }
                        return;
                    }
                    any = true;
                    box.appendChild(tagItemEl(t, 'main', { group: isGroup }));
                    if (isGroup && instTagsOpen) {
                        inst.forEach(x => box.appendChild(tagItemEl(x, 'inst', { child: true })));
                        const add = document.createElement('div');
                        add.className = 'cat-item tag-child';
                        add.textContent = '➕ افزودن قسط جدید';
                        add.addEventListener('click', () => openTagModal('inst'));
                        box.appendChild(add);
                    }
                });

                if (f) {
                    inst.forEach(t => { if (hit(t)) { any = true; box.appendChild(tagItemEl(t, 'inst', { child: true })); } });
                }
                if (!any) box.innerHTML = '<div class="cat-empty">موردی پیدا نشد</div>';
            }

            function renderTags() {
                renderTagItems(document.getElementById('tag-search') ? document.getElementById('tag-search').value : '');
                const del = document.getElementById('tag-del-btn');
                if (del) {
                    del.classList.toggle('on', tagDelMode);
                    del.textContent = tagDelMode ? '✅ پایان حذف' : '🗑 حذف';
                }
            }

            document.getElementById('tag-dd-btn').addEventListener('click', function(e) {
                e.stopPropagation();
                const dd = document.getElementById('tag-dd');
                if (dd.classList.contains('open')) { closeAllDd(); return; }
                openDd(dd);
                const se = document.getElementById('tag-search');
                se.value = '';
                renderTags();
                if (!isMobile()) se.focus();
            });
            document.getElementById('tag-search').addEventListener('input', function() { renderTagItems(this.value); });
            document.getElementById('tag-search').addEventListener('keydown', function(e) { if (e.key === 'Enter') e.preventDefault(); });
            document.getElementById('tag-del-btn').addEventListener('click', function() {
                tagDelMode = !tagDelMode;
                renderTags();
            });
            document.getElementById('tag-add-btn').addEventListener('click', function() { openTagModal('main'); });

            /* مودال افزودن */
            function setNewTagKind(kind) {
                newTagKind = kind;
                document.querySelectorAll('#new-tag-type .toggle-btn').forEach(b => b.classList.toggle('active', b.getAttribute('data-value') === kind));
            }
            document.querySelectorAll('#new-tag-type .toggle-btn').forEach(b => {
                b.addEventListener('click', () => setNewTagKind(b.getAttribute('data-value')));
            });

            function renderIconGrid() {
                const grid = document.getElementById('icon-grid');
                const prev = document.getElementById('icon-preview');
                if (!grid) return;
                if (prev) prev.textContent = selectedIcon;
                grid.innerHTML = '';
                ICON_CHOICES.forEach(ic => {
                    const cell = document.createElement('span');
                    cell.className = 'icon-cell' + (ic === selectedIcon ? ' sel' : '');
                    cell.textContent = ic;
                    cell.addEventListener('click', () => { selectedIcon = ic; renderIconGrid(); });
                    grid.appendChild(cell);
                });
            }

            function openTagModal(scope) {
                tagModalScope = scope;
                document.getElementById('add-tag-title').textContent = scope === 'inst' ? 'افزودن قسط جدید' : 'افزودن مورد آماده';
                selectedIcon = scope === 'inst' ? '💳' : '⭐';
                setNewTagKind('expense');
                renderIconGrid();
                document.getElementById('new-tag-input').value = '';
                document.getElementById('add-tag-modal').classList.add('active');
                document.getElementById('new-tag-input').focus();
            }

            window.closeTagModal = function() {
                document.getElementById('add-tag-modal').classList.remove('active');
            };

            const saveTagBtn = document.getElementById('save-new-tag-btn');
            if(saveTagBtn) {
                saveTagBtn.addEventListener('click', function() {
                    let inputVal = document.getElementById('new-tag-input').value.trim();
                    if(inputVal) {
                        if (tagModalScope === 'inst' && inputVal.indexOf('قسط') < 0) inputVal = 'اقساط ' + inputVal;
                        const tag = selectedIcon + ' ' + inputVal;
                        const key = TAG_KEYS[tagModalScope].custom;
                        const customTags = readTagList(key);
                        customTags.push(tag);
                        writeTagList(key, customTags);
                        if (newTagKind === 'income') {
                            const inc = readTagList(INCOME_KEY);
                            inc.push(tag);
                            writeTagList(INCOME_KEY, inc);
                        }
                        if (tagModalScope === 'inst') instTagsOpen = true;
                        renderTags();
                        window.closeTagModal();
                    }
                });
            }
            renderTags();

            /* ---------- فرم تراکنش ---------- */
            const typeInput = document.getElementById('type');
            const toggleBtns = document.querySelectorAll('#transaction-form .toggle-btn');
            toggleBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    toggleBtns.forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    typeInput.value = btn.getAttribute('data-value');
                });
            });

            const amountInput = document.getElementById('amount');
            const descInput = document.getElementById('desc');
            function bindAmountFormat(el) {
                el.addEventListener('input', function(e) {
                    const parsed = parsePersianInt(e.target.value);
                    e.target.value = parsed > 0 ? fmt(parsed) : '';
                });
            }
            bindAmountFormat(amountInput);

            function transferMirror(person, type, desc) {
                const other = otherPerson(person);
                if (normText(desc).indexOf('دریافتی از ' + PEOPLE[other]) !== 0) return null;
                return {
                    person: other,
                    type: type === 'income' ? 'expense' : 'income',
                    desc: (type === 'income' ? 'پرداختی به ' : 'دریافتی از ') + PEOPLE[person]
                };
            }

            function saveMirror(mir, amount) {
                addTransactionLocally(mir.person, mir.type, amount, mir.desc);
            }

            /* ثبت محلی + گذاشتن در صف ارسال */
            function addTransactionLocally(person, type, amount, desc) {
                const id = nextTmpId();
                transactions.unshift({ id: id, person: person, type: type, amount: amount, desc: desc, date: ymd(new Date()) });
                enqueue({
                    kind: 'create', id: id,
                    payload: { action: 'save_finance_transaction', edit_id: 0, person: person, tx_type: type, amount: amount, tx_desc: desc }
                });
                saveCache();
                return id;
            }

            const form = document.getElementById('transaction-form');
            const submitBtn = document.getElementById('submit-btn');

            form.addEventListener('submit', function(e) {
                e.preventDefault();
                if (currentTab === 'report') return;

                const editId = parseInt(document.getElementById('edit_id').value) || 0;
                const type = typeInput.value;
                const amount = parsePersianInt(amountInput.value);
                const desc = descInput.value;
                const originalDate = document.getElementById('tx_date').value;
                const person = currentTab;

                if (amount <= 0) return;

                if (editId !== 0) {
                    const index = transactions.findIndex(t => t.id === editId);
                    if (index > -1) {
                        transactions[index].type = type;
                        transactions[index].amount = amount;
                        transactions[index].desc = desc;
                        transactions[index].person = person;
                    }
                    const queuedCreate = pendingOps.find(o => o.kind === 'create' && o.id === editId);
                    if (queuedCreate) {
                        queuedCreate.payload.person = person;
                        queuedCreate.payload.tx_type = type;
                        queuedCreate.payload.amount = amount;
                        queuedCreate.payload.tx_desc = desc;
                        saveQueue(); flushQueue();
                    } else {
                        enqueue({ kind: 'update', id: editId, payload: { action: 'save_finance_transaction', edit_id: editId, person: person, tx_type: type, amount: amount, tx_desc: desc, tx_date: originalDate } });
                    }
                    window.cancelEdit();
                } else {
                    addTransactionLocally(person, type, amount, desc);
                    amountInput.value = '';
                    descInput.value = '';
                    resetTagPick();
                    const mir = transferMirror(person, type, desc);
                    if (mir) saveMirror(mir, amount);
                }

                saveCache();
                renderMonthSelect();
                updateUI();
                submitBtn.textContent = 'ثبت تراکنش';
            });

            /* ---------- اقساط (از روی تراکنش‌های دسته اقساط) ---------- */
            const INST_WORDS = ['اقساط', 'قسط'];
            function isInstTx(t) {
                const d = normText(t.desc);
                return INST_WORDS.some(w => d.indexOf(w) >= 0);
            }

            function renderInstSection() {
                const box = document.getElementById('inst-cat-list');
                const sum = document.getElementById('inst-summary');
                if (!box || !sum) return;

                const list = visibleTx().filter(isInstTx);
                if (list.length === 0) {
                    sum.textContent = 'قسطی ثبت نشده است';
                    box.innerHTML = '<div style="text-align:center; opacity:0.6; padding:12px;">تراکنشی با عنوان قسط پیدا نشد</div>';
                    return;
                }

                const groups = {};
                let grand = 0;
                list.forEach(t => {
                    const k = normText(t.desc) || 'بدون عنوان';
                    const g = groups[k] || (groups[k] = { name: k, items: [], total: 0 });
                    g.items.push(t);
                    g.total += t.amount;
                    grand += t.amount;
                });
                sum.innerHTML = `جمع کل اقساط: <b class="text-red">${fmt(grand)}</b> تومان | ${plain.format(list.length)} پرداخت در ${plain.format(Object.keys(groups).length)} عنوان`;

                const isReport = currentTab === 'report';
                const keys = Object.keys(groups).sort((a, b) => groups[b].total - groups[a].total);
                box.innerHTML = keys.map(k => {
                    const g = groups[k];
                    g.items.sort((a, b) => b.date.localeCompare(a.date) || b.id - a.id);
                    return `
                    <details>
                        <summary>
                            <span>${esc(g.name)}<span class="cat-sub">${plain.format(g.items.length)} بار</span></span>
                            <div class="day-summary-text">جمع: <b>${fmt(g.total)}</b></div>
                        </summary>
                        <div class="transaction-list">
                            ${g.items.map(item => txRowHtml(item, { showDesc: false })).join('')}
                        </div>
                    </details>`;
                }).join('');
                bindAccordion(box);
                bindChecks(box);
            }

            /* ---------- دسته‌بندی ---------- */
            function normText(str) {
                return String(str || '').replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/^[^\p{L}\p{N}]+/u, '').replace(/\s+/g, ' ').trim();
            }

            document.querySelectorAll('.view-tab').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.querySelectorAll('.view-tab').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    currentView = btn.getAttribute('data-view');
                    if (currentView !== 'group') groupFilter = '';
                    updateUI();
                });
            });

            function renderCategories(list) {
                const container = document.getElementById('accordion-list');
                if (!container) return;
                container.innerHTML = '';
                const groups = {};
                list.forEach(t => {
                    const k = normText(t.desc) || 'بدون عنوان';
                    const g = groups[k] || (groups[k] = { name: k, income: 0, expense: 0, items: [] });
                    g.items.push(t);
                    if (t.type === 'income') g.income += t.amount; else g.expense += t.amount;
                });
                const keys = Object.keys(groups).sort((a, b) => (groups[b].income + groups[b].expense) - (groups[a].income + groups[a].expense));
                if (keys.length === 0) {
                    container.innerHTML = '<div style="text-align:center; opacity:0.6; padding:10px;">تراکنشی یافت نشد</div>';
                    return;
                }
                const isReport = currentTab === 'report';
                keys.forEach(k => {
                    const g = groups[k];
                    g.items.sort((a, b) => b.date.localeCompare(a.date) || b.id - a.id);
                    const parts = [];
                    if (g.income > 0) parts.push('<span class="text-green">▲ ' + fmt(g.income) + '</span>');
                    if (g.expense > 0) parts.push('<span class="text-red">▼ ' + fmt(g.expense) + '</span>');
                    const details = document.createElement('details');
                    details.innerHTML = `
                        <summary>
                            <span>${esc(g.name)}<span class="cat-sub">${plain.format(g.items.length)} بار</span></span>
                            <div class="day-summary-text">${parts.join(' | ')}</div>
                        </summary>
                        <div class="transaction-list">
                            ${g.items.map(item => txRowHtml(item, { showDesc: false })).join('')}
                        </div>`;
                    container.appendChild(details);
                });
                bindAccordion(container);
                bindChecks(container);
            }

            /* ---------- آمار و تاریخچه ---------- */
            function calcStats(list, info) {
                const s = { ti: 0, te: 0, mi: 0, me: 0, yi: 0, ye: 0 };
                list.forEach(t => {
                    const inc = t.type === 'income';
                    if (inc) s.ti += t.amount; else s.te += t.amount;
                    if (monthKey(t.date) === info.monthKey) { if (inc) s.mi += t.amount; else s.me += t.amount; }
                    if (jalaliOf(t.date)[0] === info.year) { if (inc) s.yi += t.amount; else s.ye += t.amount; }
                });
                return s;
            }

            function updateUI() {
                const info = nowInfo();
                const list = visibleTx();
                const s = calcStats(list, info);

                document.getElementById('total-balance').textContent = fmt(s.ti - s.te);
                document.getElementById('year-income').textContent = '+' + fmt(s.yi);
                document.getElementById('year-expense').textContent = '-' + fmt(s.ye);
                document.getElementById('month-income').textContent = '+' + fmt(s.mi);
                document.getElementById('month-expense').textContent = '-' + fmt(s.me);

                // تفکیک افراد در تب گزارش کل
                const pb = document.getElementById('person-breakdown');
                if (currentTab === 'report') {
                    pb.innerHTML = Object.keys(PEOPLE).map(p => {
                        const ps = calcStats(transactions.filter(t => t.person === p), info);
                        const bal = ps.ti - ps.te;
                        return `<div class="person-row">
                            <span><span class="person-badge ${p}">${PEOPLE[p]}</span></span>
                            <span><span class="text-green">+${fmt(ps.ti)}</span> | <span class="text-red">-${fmt(ps.te)}</span> | <b class="${bal >= 0 ? 'text-green' : 'text-red'}">${bal < 0 ? '-' : ''}${fmt(Math.abs(bal))}</b></span>
                        </div>`;
                    }).join('');
                }

                const groupedByDate = {};
                let filteredTx = list;
                if (currentFilter !== 'all') filteredTx = filteredTx.filter(t => t.type === currentFilter);
                if (currentMonthFilter !== '') filteredTx = filteredTx.filter(t => monthKey(t.date) === currentMonthFilter);

                filteredTx.forEach(t => {
                    if (!groupedByDate[t.date]) groupedByDate[t.date] = { income: 0, expense: 0, items: [] };
                    groupedByDate[t.date].items.push(t);
                    if (t.type === 'income') groupedByDate[t.date].income += t.amount;
                    else groupedByDate[t.date].expense += t.amount;
                });

                if (groupFilter) filteredTx = filteredTx.filter(t => groupOf(t.id) === groupFilter);
                lastFilteredTx = filteredTx;

                if (currentView === 'group') renderGroupView(filteredTx);
                else if (currentView === 'cat') renderCategories(filteredTx);
                else {
                    const gd = {};
                    filteredTx.forEach(t => {
                        if (!gd[t.date]) gd[t.date] = { income: 0, expense: 0, items: [] };
                        gd[t.date].items.push(t);
                        if (t.type === 'income') gd[t.date].income += t.amount; else gd[t.date].expense += t.amount;
                    });
                    renderHistory(gd);
                }
                renderInstSection();
                updateSumBar();
                renderEvFilter();
                renderNetBar();
            }

            const ACC_MS = 260;
            function bindAccordion(root) {
                if (!root) return;
                root.querySelectorAll('details').forEach(d => {
                    if (d.dataset.acc === '1') return;
                    d.dataset.acc = '1';
                    const summary = d.querySelector('summary');
                    const panel = d.querySelector('.transaction-list');
                    if (!summary || !panel) return;
                    summary.addEventListener('click', e => {
                        e.preventDefault();
                        if (d.dataset.busy === '1') return;
                        d.dataset.busy = '1';
                        if (d.open) {
                            panel.style.height = panel.scrollHeight + 'px';
                            requestAnimationFrame(() => { panel.style.height = '0px'; });
                            setTimeout(() => {
                                d.open = false;
                                panel.style.height = '';
                                d.dataset.busy = '0';
                            }, ACC_MS);
                        } else {
                            d.open = true;
                            const target = panel.scrollHeight;
                            panel.style.height = '0px';
                            requestAnimationFrame(() => { panel.style.height = target + 'px'; });
                            setTimeout(() => {
                                panel.style.height = '';
                                d.dataset.busy = '0';
                            }, ACC_MS);
                        }
                    });
                });
            }

            function renderHistory(groupedData) {
                const container = document.getElementById('accordion-list');
                if(!container) return;

                container.innerHTML = '';
                const sortedDates = Object.keys(groupedData).sort((a, b) => b.localeCompare(a));

                if(sortedDates.length === 0) {
                    container.innerHTML = '<div style="text-align:center; opacity:0.6; padding:10px;">تراکنشی یافت نشد</div>';
                    return;
                }

                const isReport = currentTab === 'report';

                sortedDates.forEach((date, idx) => {
                    const dayData = groupedData[date];
                    const displayDate = dateLabel(date);

                    let summaryText = '';
                    if(currentFilter === 'all') {
                        summaryText = `<span class="text-green">▲ ${fmt(dayData.income)}</span> | <span class="text-red">▼ ${fmt(dayData.expense)}</span>`;
                    } else if (currentFilter === 'income') {
                        summaryText = `<span class="text-green">▲ ${fmt(dayData.income)}</span>`;
                    } else {
                        summaryText = `<span class="text-red">▼ ${fmt(dayData.expense)}</span>`;
                    }

                    const details = document.createElement('details');
                    details.open = (idx === 0);
                    details.innerHTML = `
                        <summary>
                            <span>${displayDate}</span>
                            <div class="day-summary-text">${summaryText}</div>
                        </summary>
                        <div class="transaction-list">
                            ${dayData.items.map(item => txRowHtml(item)).join('')}
                        </div>
                    `;
                    container.appendChild(details);
                });
                bindAccordion(container);
                bindChecks(container);
            }

            /* ================= انتخاب چندتایی و جمع ================= */
            const sumBar = document.getElementById('sum-bar');

            function bindChecks(root) {
                if (!root) return;
                root.querySelectorAll('[data-check]').forEach(cb => {
                    cb.addEventListener('click', e => e.stopPropagation());
                    cb.addEventListener('change', function() {
                        const id = parseInt(this.getAttribute('data-check'), 10);
                        if (this.checked) selectedIds.add(id); else selectedIds.delete(id);
                        const row = this.closest('.transaction-item');
                        if (row) row.classList.toggle('picked', this.checked);
                        updateSumBar();
                    });
                });
                // در حالت انتخاب، کلیک روی خود ردیف هم تیک می‌زند
                root.querySelectorAll('.transaction-item').forEach(row => {
                    row.addEventListener('click', function(e) {
                        if (!selectMode) return;
                        if (e.target.closest('.tx-actions') || e.target.matches('[data-check]')) return;
                        const cb = this.querySelector('[data-check]');
                        if (!cb) return;
                        cb.checked = !cb.checked;
                        cb.dispatchEvent(new Event('change'));
                    });
                });
            }

            function selectedTx() {
                return transactions.filter(t => selectedIds.has(t.id));
            }

            function updateSumBar() {
                if (!sumBar) return;
                const list = selectedTx();
                let inc = 0, exp = 0;
                list.forEach(t => { if (t.type === 'income') inc += t.amount; else exp += t.amount; });
                const net = inc - exp;
                document.getElementById('sum-count').textContent = plain.format(list.length) + ' مورد انتخاب شده';
                const netEl = document.getElementById('sum-net');
                netEl.innerHTML = '<b class="' + (net >= 0 ? 'text-green' : 'text-red') + '">' + (net < 0 ? '-' : '') + fmt(Math.abs(net)) + '</b> تومان';
                document.getElementById('sum-detail').innerHTML =
                    '<span class="text-green">▲ درآمد: ' + fmt(inc) + '</span>' +
                    '<span class="text-red">▼ هزینه: ' + fmt(exp) + '</span>';
            }

            function setSelectMode(on) {
                selectMode = on;
                appRoot.classList.toggle('select-mode', on);
                if (!on) selectedIds.clear();
                document.getElementById('select-mode-btn').textContent = on ? '✖️ پایان انتخاب' : '☑️ انتخاب چندتایی';
                updateUI();
            }

            document.getElementById('select-mode-btn').addEventListener('click', () => setSelectMode(!selectMode));

            document.getElementById('sum-clear-btn').addEventListener('click', () => {
                selectedIds.clear();
                updateUI();
            });

            document.getElementById('sum-all-btn').addEventListener('click', function() {
                const all = lastFilteredTx.every(t => selectedIds.has(t.id)) && lastFilteredTx.length > 0;
                if (all) selectedIds.clear();
                else lastFilteredTx.forEach(t => selectedIds.add(t.id));
                updateUI();
            });

            /* ================= گروه دوم ================= */
            const groupModal = document.getElementById('group-modal');
            let pendingGroupIds = [];
            let pickedGroupName = '';

            function renderGroupChips() {
                const box = document.getElementById('group-chips');
                box.innerHTML = '';
                const names = allGroupNames();
                if (names.length === 0) {
                    box.innerHTML = '<div style="opacity:0.6; font-size:0.82rem;">هنوز گروهی ساخته نشده؛ نام گروه را بنویسید.</div>';
                    return;
                }
                names.forEach(n => {
                    const c = document.createElement('div');
                    c.className = 'group-chip' + (n === pickedGroupName ? ' sel' : '');
                    c.textContent = '🧳 ' + n;
                    c.addEventListener('click', () => {
                        pickedGroupName = n;
                        document.getElementById('group-input').value = n;
                        renderGroupChips();
                    });
                    box.appendChild(c);
                });
            }

            function openGroupModal(ids) {
                if (!ids.length) { alert('اول چند مورد را تیک بزنید'); return; }
                pendingGroupIds = ids;
                pickedGroupName = groupOf(ids[0]) || '';
                document.getElementById('group-input').value = pickedGroupName;
                document.getElementById('group-modal-sub').textContent =
                    plain.format(ids.length) + ' مورد انتخاب شده‌اند. گروه دوم (مثل یک سفر یا پروژه) را انتخاب یا اضافه کنید.';
                renderGroupChips();
                groupModal.classList.add('active');
            }

            window.closeGroupModal = function() {
                pendingGroupIds = [];
                groupModal.classList.remove('active');
            };

            document.getElementById('sum-group-btn').addEventListener('click', () => openGroupModal(Array.from(selectedIds)));

            // دکمهٔ 🧳 روی هر ردیف: بدون نیاز به حالت انتخاب
            window.setEvent = function(id) { openGroupModal([id]); };

            /* چیپ‌های فیلتر رویداد + راهنمای هر نما */
            const VIEW_HINTS = {
                date: 'هر روز یک کشو؛ جمع درآمد و خرج همان روز بالای کشو نوشته شده.',
                cat: 'موارد هم‌عنوان کنار هم جمع می‌شوند (مثلاً همهٔ «سوپر»ها).',
                group: 'خرج‌هایی که برای یک ماجرا بوده‌اند کنار هم؛ با دکمهٔ 🧳 روی هر ردیف یا با انتخاب چند مورد، آن‌ها را داخل یک رویداد بگذار.'
            };

            function renderEvFilter() {
                const box = document.getElementById('ev-filter');
                const hint = document.getElementById('view-hint');
                if (hint) hint.textContent = VIEW_HINTS[currentView] || '';
                if (!box) return;
                const names = allGroupNames();
                box.innerHTML = '';
                if (currentView !== 'group' || names.length === 0) { box.style.display = 'none'; return; }
                box.style.display = 'flex';
                const mk = (label, val) => {
                    const c = document.createElement('div');
                    c.className = 'group-chip' + (groupFilter === val ? ' sel' : '');
                    c.textContent = label;
                    c.addEventListener('click', () => { groupFilter = (groupFilter === val ? '' : val); updateUI(); });
                    return c;
                };
                box.appendChild(mk('همه', ''));
                names.forEach(n => box.appendChild(mk('🧳 ' + n, n)));
            }

            document.getElementById('group-save-btn').addEventListener('click', function() {
                const name = document.getElementById('group-input').value.trim();
                if (!name) { alert('نام گروه را وارد کنید'); return; }
                setGroup(pendingGroupIds, name);
                window.closeGroupModal();
                updateUI();
            });

            document.getElementById('group-remove-btn').addEventListener('click', function() {
                setGroup(pendingGroupIds, '');
                window.closeGroupModal();
                updateUI();
            });

            document.getElementById('group-input').addEventListener('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); document.getElementById('group-save-btn').click(); }
            });

            function renderGroupView(list) {
                const container = document.getElementById('accordion-list');
                if (!container) return;
                container.innerHTML = '';

                const groups = {};
                list.forEach(t => {
                    const k = groupOf(t.id) || '— بدون گروه —';
                    const g = groups[k] || (groups[k] = { name: k, income: 0, expense: 0, items: [] });
                    g.items.push(t);
                    if (t.type === 'income') g.income += t.amount; else g.expense += t.amount;
                });

                const keys = Object.keys(groups).sort((a, b) => {
                    if (a.indexOf('—') === 0) return 1;
                    if (b.indexOf('—') === 0) return -1;
                    return (groups[b].income + groups[b].expense) - (groups[a].income + groups[a].expense);
                });

                if (keys.length === 0) {
                    container.innerHTML = '<div style="text-align:center; opacity:0.6; padding:10px;">تراکنشی یافت نشد</div>';
                    return;
                }

                keys.forEach(k => {
                    const g = groups[k];
                    g.items.sort((a, b) => b.date.localeCompare(a.date) || b.id - a.id);
                    const net = g.income - g.expense;
                    const parts = [];
                    if (g.income > 0) parts.push('<span class="text-green">▲ ' + fmt(g.income) + '</span>');
                    if (g.expense > 0) parts.push('<span class="text-red">▼ ' + fmt(g.expense) + '</span>');
                    parts.push('<b class="' + (net >= 0 ? 'text-green' : 'text-red') + '">' + (net < 0 ? '-' : '') + fmt(Math.abs(net)) + '</b>');
                    const details = document.createElement('details');
                    details.innerHTML =
                        '<summary><span>' + (k.indexOf('—') === 0 ? esc(k) : '🧳 ' + esc(k)) +
                        '<span class="cat-sub">' + plain.format(g.items.length) + ' مورد</span></span>' +
                        '<div class="day-summary-text">' + parts.join(' | ') + '</div></summary>' +
                        '<div class="transaction-list">' + g.items.map(item => txRowHtml(item)).join('') + '</div>';
                    container.appendChild(details);
                });
                bindAccordion(container);
                bindChecks(container);
            }

            /* ================= کشویی‌ها: پاپ‌اور موبایل + بستن با کلیک بیرون ================= */
            const backdrop = document.getElementById('sheet-backdrop');
            const isMobile = () => window.matchMedia('(max-width: 640px)').matches;

            // روی موبایل پنل را از دل پنل‌های شیشه‌ای بیرون می‌کشیم تا position:fixed درست کار کند
            let sheetPanel = null, sheetHome = null;

            function toSheet(dd) {
                const panel = dd.querySelector('.cat-dd-panel');
                if (!panel) return;
                sheetPanel = panel;
                sheetHome = { parent: panel.parentNode, next: panel.nextSibling };
                panel.classList.add('dd-sheet');
                appRoot.appendChild(panel);
            }
            function unSheet() {
                if (!sheetPanel || !sheetHome) { sheetPanel = null; sheetHome = null; return; }
                sheetPanel.classList.remove('dd-sheet');
                sheetHome.parent.insertBefore(sheetPanel, sheetHome.next);
                sheetPanel = null; sheetHome = null;
            }

            function openDd(dd) {
                closeAllDd();
                dd.classList.add('open');
                if (isMobile()) { toSheet(dd); backdrop.classList.add('active'); }
            }
            function closeAllDd() {
                unSheet();
                document.querySelectorAll('.cat-dd.open').forEach(o => o.classList.remove('open'));
                if (backdrop) backdrop.classList.remove('active');
            }
            backdrop.addEventListener('click', closeAllDd);
            document.querySelectorAll('.sheet-close').forEach(b => b.addEventListener('click', closeAllDd));

            // کلیک بیرون از کشویی (دسکتاپ)
            document.addEventListener('click', function(e) {
                if (isMobile()) return;
                if (e.target.closest('.cat-dd')) return;
                closeAllDd();
            });

            // Esc: اول کشویی، بعد مودال‌ها
            document.addEventListener('keydown', function(e) {
                if (e.key !== 'Escape') return;
                if (document.querySelector('.cat-dd.open')) { closeAllDd(); return; }
                const open = document.querySelector('.modal-overlay.active');
                if (open) { closeAllDd(); open.classList.remove('active'); }
            });

            // کلیک روی پس‌زمینه مودال = بستن
            document.querySelectorAll('.modal-overlay').forEach(ov => {
                ov.addEventListener('click', function(e) {
                    if (e.target === ov) { closeAllDd(); ov.classList.remove('active'); }
                });
            });

            window.addEventListener('resize', function() {
                if (!isMobile()) { unSheet(); backdrop.classList.remove('active'); }
                else if (document.querySelector('.cat-dd.open') && !sheetPanel) {
                    toSheet(document.querySelector('.cat-dd.open'));
                    backdrop.classList.add('active');
                }
            });

            /* ================= راه‌اندازی حالت آفلاین ================= */
            pendingOps = readQueue();
            // اگر صفحه آفلاین از کش باز شده و دادهٔ سرور قدیمی‌تر از کش است، از کش استفاده کن
            (function mergeCache() {
                const c = readCache();
                if (c && c.tx.length && transactions.length === 0) transactions = c.tx;
                const minTmp = transactions.reduce((m, t) => Math.min(m, t.id), 0);
                tmpCounter = Math.min(-1, minTmp - 1);
                // مواردی که هنوز ارسال نشده‌اند ولی در لیست نیستند را برگردان
                pendingOps.forEach(op => {
                    if (op.kind !== 'create') return;
                    if (transactions.some(t => t.id === op.id)) return;
                    const p = op.payload;
                    transactions.unshift({ id: op.id, person: p.person, type: p.tx_type, amount: Number(p.amount), desc: p.tx_desc, date: op.date || ymd(new Date()) });
                });
                refreshPendingIds();
            })();

            window.addEventListener('online', () => { renderNetBar(); flushQueue(); });
            window.addEventListener('offline', renderNetBar);
            document.addEventListener('visibilitychange', () => { if (!document.hidden) flushQueue(); });
            document.getElementById('net-sync-btn').addEventListener('click', flushQueue);
            setInterval(flushQueue, 30000);

            // سرویس‌ورکر: صفحه بدون اینترنت هم باز می‌شود
            if ('serviceWorker' in navigator) {
                const swUrl = location.pathname + '?finance_sw=1';
                navigator.serviceWorker.register(swUrl, { scope: location.pathname }).catch(() => {});
            }

            setTab('sarina');
            saveCache();
            flushQueue();
        });
    </script>

    <?php
    return ob_get_clean();
}
