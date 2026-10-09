<?php

/*
 * اگر نسخهٔ دیگری از همین کد (مثلاً اسنیپت قدیمی) هم فعال باشد، به جای «خطای مهم» و از کار افتادن سایت،
 * این نسخه کنار می‌کشد و در پیشخوان پیغام می‌دهد که نسخهٔ قدیمی را غیرفعال کنید.
 */
if (function_exists('wp_finance_setup')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p><b>مدیریت مالی:</b> نسخهٔ قدیمی کد هنوز فعال است. '
           . 'اسنیپت (یا کد داخل functions.php) قدیمی را غیرفعال یا حذف کنید تا نسخهٔ جدید اجرا شود.</p></div>';
    });
} else {

/**
 * ۱. ایجاد جداول دیتابیس (تراکنش‌ها + اقساط) و تنظیم رمز عبور هش شده
 */
add_action('init', 'wp_finance_setup');
function wp_finance_setup() {
    global $wpdb;

    $db_version = '3';
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
            client_uid varchar(40) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY client_uid (client_uid)
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
        wp_send_json_error('دسترسی غیرمجاز', 401);
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
    // بدون این متا، بعضی قالب‌ها صفحه را در موبایل با عرض ۹۸۰ نشان می‌دهند و نمای موبایل فعال نمی‌شود
    echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">' . "\n";
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

    $v = 'fin-v2';
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

    // صفحه: اول شبکه؛ اگر نت نبود یا بیش از ۴ ثانیه طول کشید، آخرین نسخهٔ کش‌شده
    if (req.mode === 'navigate' || (req.headers.get('accept') || '').indexOf('text/html') >= 0) {
        const fromCache = () => caches.match(req).then(r => r || caches.match(self.location.pathname));
        const network = fetch(req).then(res => {
            if (res && res.ok) {
                const copy = res.clone();
                caches.open(CACHE).then(c => c.put(req, copy)).catch(() => {});
            }
            return res;
        });
        event.respondWith(new Promise(resolve => {
            let done = false;
            const finish = r => { if (!done && r) { done = true; resolve(r); } };
            const timer = setTimeout(() => { fromCache().then(finish); }, 4000);
            network.then(r => { clearTimeout(timer); finish(r); })
                .catch(() => { clearTimeout(timer); fromCache().then(r => finish(r || Response.error())); });
        }));
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
 * ۲-الف. همگام‌سازی دسته‌ای: همهٔ تغییرات صف در یک درخواست و به ترتیب اجرا می‌شوند.
 * هر تراکنش یک شناسهٔ یکتای سمت کاربر (uid) دارد؛ پس ارسال دوباره هیچ‌وقت تکراری ثبت نمی‌کند
 * و درخواست می‌تواند با keepalive حتی بعد از بستن اپ هم کامل شود.
 */
add_action('wp_ajax_finance_sync', 'finance_sync');
add_action('wp_ajax_nopriv_finance_sync', 'finance_sync');
function finance_sync() {
    finance_check_auth();

    global $wpdb;
    $table = $wpdb->prefix . 'finance_transactions';
    $ops = json_decode(isset($_POST['ops']) ? wp_unslash($_POST['ops']) : '', true);
    if (!is_array($ops)) wp_send_json_error('داده نامعتبر', 400);

    $today = current_time('Y-m-d');
    $max_date = date('Y-m-d', strtotime($today . ' +1 day'));
    $resolved = []; // uid => id
    $results = [];

    foreach (array_slice($ops, 0, 500) as $op) {
        if (!is_array($op)) { $results[] = ['ok' => false, 'retry' => false]; continue; }
        $kind = isset($op['kind']) ? (string)$op['kind'] : '';
        $uid = substr(preg_replace('/[^A-Za-z0-9_-]/', '', isset($op['uid']) ? (string)$op['uid'] : ''), 0, 40);
        $id = isset($op['id']) ? intval($op['id']) : 0;

        if ($id <= 0 && $uid !== '') {
            if (isset($resolved[$uid])) {
                $id = $resolved[$uid];
            } else {
                $found = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE client_uid = %s LIMIT 1", $uid));
                if ($found) $id = (int)$found;
            }
        }

        if ($kind === 'delete') {
            if ($id > 0) {
                if ($wpdb->delete($table, ['id' => $id]) === false) { $results[] = ['ok' => false, 'retry' => true]; continue; }
            }
            $results[] = ['ok' => true, 'id' => $id];
            continue;
        }

        if ($kind !== 'create' && $kind !== 'update') { $results[] = ['ok' => false, 'retry' => false]; continue; }

        $amount = isset($op['amount']) ? intval($op['amount']) : 0;
        $desc = sanitize_text_field(isset($op['desc']) ? (string)$op['desc'] : '');
        if ($amount <= 0 || $desc === '') { $results[] = ['ok' => false, 'retry' => false]; continue; }
        $fields = [
            'person' => finance_clean_person(isset($op['person']) ? (string)$op['person'] : 'hossein'),
            'tx_type' => (isset($op['type']) && $op['type'] === 'income') ? 'income' : 'expense',
            'amount' => $amount,
            'tx_desc' => $desc,
        ];

        if ($id > 0) {
            // ویرایش، یا ساختنی که قبلاً رسیده بود (فقط مقدارها را به‌روز می‌کنیم)
            if ($wpdb->update($table, $fields, ['id' => $id]) === false) { $results[] = ['ok' => false, 'retry' => true]; continue; }
            if ($uid !== '') $resolved[$uid] = $id;
            $date = $wpdb->get_var($wpdb->prepare("SELECT tx_date FROM $table WHERE id = %d", $id));
            $results[] = ['ok' => true, 'id' => $id, 'date' => $date ? $date : ''];
            continue;
        }

        if ($kind === 'update') { $results[] = ['ok' => false, 'retry' => true]; continue; } // هنوز ساخته نشده

        $date = isset($op['date']) ? (string)$op['date'] : '';
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || $date > $max_date) {
            $date = $today;
        }
        $fields['tx_date'] = $date;
        $fields['client_uid'] = $uid;
        if (!$wpdb->insert($table, $fields)) { $results[] = ['ok' => false, 'retry' => true]; continue; }
        $id = (int)$wpdb->insert_id;
        if ($uid !== '') $resolved[$uid] = $id;
        $results[] = ['ok' => true, 'id' => $id, 'date' => $date];
    }

    wp_send_json_success(['results' => $results]);
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
        .finance-app-wrapper .transaction-list { padding: 8px; background: rgba(255,255,255,0.03); }

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

        @media (max-width: 640px), (hover: none) and (pointer: coarse) and (max-width: 1024px) {
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
        .finance-app-wrapper .tx-check { width: 21px; height: 21px; flex: none; margin: 0; accent-color: #60a5fa; cursor: pointer; display: none; }
        .finance-app-wrapper.select-mode .tx-check { display: inline-block; }
        .finance-app-wrapper.select-mode .tx-actions { display: none; }
        .finance-app-wrapper.select-mode .tx-more { display: none; }
        .finance-app-wrapper .transaction-item.picked { background: rgba(96,165,250,0.16); border-color: rgba(96,165,250,0.45); }
        .finance-app-wrapper.light .transaction-item.picked { background: rgba(37,99,235,0.1); border-color: rgba(37,99,235,0.35); }

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

        .finance-app-wrapper .pending-badge { font-size: 0.68rem; padding: 2px 7px; border-radius: 9px; background: rgba(251,191,36,0.22); color: #fcd34d; white-space: nowrap; }
        .finance-app-wrapper.light .pending-badge { background: rgba(245,158,11,0.16); color: #92400e; }

        /* ===== رویدادها (گروه دوم) ===== */
        .finance-app-wrapper .ev-btn { color: #d8b4fe; }
        .finance-app-wrapper.light .ev-btn { color: #7e22ce; }
        .finance-app-wrapper .ev-filter { display: flex; gap: 6px; overflow-x: auto; scrollbar-width: none; padding-bottom: 6px; margin-bottom: 10px; }
        .finance-app-wrapper .ev-filter::-webkit-scrollbar { display: none; }
        .finance-app-wrapper .ev-filter .group-chip { flex: none; white-space: nowrap; }
        .finance-app-wrapper .hint-line { font-size: 0.78rem; opacity: 0.7; margin-bottom: 10px; line-height: 1.7; }

        /* ===== ردیف تراکنش (کارت): آیکون | عنوان + توضیح | مبلغ + منو ===== */
        .finance-app-wrapper .transaction-item {
            display: flex; align-items: center; gap: 10px;
            padding: 10px; margin-bottom: 6px; border-radius: 14px;
            background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.07);
            font-size: 0.9rem; cursor: pointer; user-select: none;
            transition: background 0.15s ease, transform 0.12s ease;
        }
        .finance-app-wrapper .transaction-item:last-child { margin-bottom: 0; }
        .finance-app-wrapper .transaction-item:active { transform: scale(0.985); background: rgba(255,255,255,0.09); }
        .finance-app-wrapper .tx-avatar {
            flex: none; width: 40px; height: 40px; border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem; line-height: 1; font-weight: bold;
        }
        .finance-app-wrapper .tx-avatar.in { background: rgba(74,222,128,0.16); color: var(--income-color); }
        .finance-app-wrapper .tx-avatar.out { background: rgba(248,113,113,0.14); color: var(--expense-color); }
        .finance-app-wrapper .tx-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
        .finance-app-wrapper .tx-desc {
            font-size: 0.92rem; font-weight: 600; line-height: 1.5;
            display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
            overflow: hidden; overflow-wrap: anywhere;
        }
        .finance-app-wrapper .tx-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; font-size: 0.72rem; opacity: 0.78; }
        .finance-app-wrapper .tx-meta:empty { display: none; }
        .finance-app-wrapper .tx-meta .person-badge, .finance-app-wrapper .tx-meta .group-badge { margin: 0; }
        .finance-app-wrapper .tx-end { flex: none; display: flex; align-items: center; gap: 4px; }
        .finance-app-wrapper .tx-amount { white-space: nowrap; font-weight: bold; font-size: 0.95rem; }
        .finance-app-wrapper .tx-more {
            width: 28px; height: 32px; padding: 0; margin: 0; border: none; border-radius: 9px;
            background: transparent; color: inherit; opacity: 0.55; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
        }
        .finance-app-wrapper .tx-more svg { width: 18px; height: 18px; }
        .finance-app-wrapper .transaction-item.pending .tx-amount { opacity: 0.7; }
        .finance-app-wrapper.light .transaction-item { background: rgba(255,255,255,0.75); border-color: rgba(15,23,42,0.08); }
        .finance-app-wrapper.light .transaction-item:active { background: rgba(15,23,42,0.06); }
        .finance-app-wrapper.light .tx-avatar.in { background: rgba(22,163,74,0.12); }
        .finance-app-wrapper.light .tx-avatar.out { background: rgba(220,38,38,0.1); }
        .finance-app-wrapper .day-summary-text { display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }

        /* منوی هر ردیف (ویرایش / رویداد / حذف) */
        .finance-app-wrapper .menu-head { display: flex; align-items: center; gap: 10px; text-align: right; padding-bottom: 14px; margin-bottom: 10px; border-bottom: 1px solid var(--glass-border); }
        .finance-app-wrapper .menu-head .mh-text { flex: 1; min-width: 0; }
        .finance-app-wrapper .menu-head .mh-title { font-weight: bold; overflow-wrap: anywhere; }
        .finance-app-wrapper .menu-head .mh-sub { font-size: 0.78rem; opacity: 0.75; margin-top: 3px; }
        .finance-app-wrapper .menu-item { display: flex; align-items: center; gap: 10px; margin: 0 0 8px; padding: 13px 14px; border-radius: 13px; background: rgba(255,255,255,0.07); cursor: pointer; font-size: 0.95rem; text-align: right; }
        .finance-app-wrapper .menu-item.danger { color: #fca5a5; background: rgba(248,113,113,0.12); }
        .finance-app-wrapper.light .menu-item { background: rgba(15,23,42,0.05); color: #0f172a; }
        .finance-app-wrapper.light .menu-item.danger { color: #dc2626; background: rgba(220,38,38,0.08); }

        /* ===== موارد آماده: فیلد دستی + دکمه‌های دوتایی ===== */
        .finance-app-wrapper .tag-box { margin-bottom: 15px; padding: 12px; border-radius: 16px; background: rgba(0,0,0,0.18); border: 1px solid var(--glass-border); }
        .finance-app-wrapper .tag-box-label { font-size: 0.8rem; opacity: 0.75; margin: 0 2px 8px; display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        .finance-app-wrapper .desc-wrap { position: relative; }
        .finance-app-wrapper .desc-wrap input { padding-left: 40px; }
        .finance-app-wrapper .desc-clear { position: absolute; left: 6px; top: 50%; transform: translateY(-50%); width: 30px; height: 30px; padding: 0; margin: 0; border: none; border-radius: 50%; background: rgba(255,255,255,0.12); color: inherit; cursor: pointer; display: none; font-size: 0.8rem; line-height: 30px; }
        .finance-app-wrapper .desc-wrap.has-val .desc-clear { display: block; }
        .finance-app-wrapper .tag-head { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
        .finance-app-wrapper .tag-back { width: auto; margin: 0; padding: 8px 12px; font-size: 0.85rem; border-radius: 11px; background: rgba(255,255,255,0.1); cursor: pointer; flex: none; font-weight: bold; }
        .finance-app-wrapper .tag-head-title { font-weight: bold; font-size: 0.9rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .finance-app-wrapper .tag-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; max-height: 46vh; overflow-y: auto; padding: 2px; }
        .finance-app-wrapper .qt {
            position: relative; display: flex; align-items: center; gap: 8px; min-width: 0;
            margin: 0; padding: 11px 10px; border-radius: 13px; font-size: 0.86rem; text-align: right;
            background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.14); color: inherit; cursor: pointer;
            transition: transform 0.12s ease, background 0.15s ease;
        }
        .finance-app-wrapper .qt:active { transform: scale(0.96); }
        .finance-app-wrapper .qt-icon { flex: none; font-size: 1.15rem; line-height: 1; }
        .finance-app-wrapper .qt-label { flex: 1; min-width: 0; overflow: hidden; line-height: 1.4; overflow-wrap: anywhere; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
        .finance-app-wrapper .qt-go { flex: none; opacity: 0.6; font-size: 1.1rem; line-height: 1; }
        .finance-app-wrapper .qt.group { background: rgba(251,191,36,0.12); border-color: rgba(251,191,36,0.35); }
        .finance-app-wrapper .qt.sel { background: rgba(74,222,128,0.2); border-color: rgba(74,222,128,0.6); font-weight: bold; }
        .finance-app-wrapper .qt.removable { border-color: rgba(248,113,113,0.8); }
        .finance-app-wrapper .qt-x { position: absolute; top: 3px; left: 6px; font-size: 0.68rem; color: #f87171; }
        .finance-app-wrapper .tag-empty { grid-column: 1 / -1; text-align: center; font-size: 0.82rem; opacity: 0.7; padding: 10px; }
        .finance-app-wrapper .tag-box .dd-actions { margin-top: 10px; }
        .finance-app-wrapper.light .tag-box { background: rgba(15,23,42,0.04); }
        .finance-app-wrapper.light .qt { background: rgba(255,255,255,0.85); border-color: rgba(15,23,42,0.14); }
        .finance-app-wrapper.light .qt.group { background: rgba(245,158,11,0.12); border-color: rgba(245,158,11,0.4); }
        .finance-app-wrapper.light .qt.sel { background: rgba(22,163,74,0.14); border-color: rgba(22,163,74,0.55); }
        .finance-app-wrapper.light .tag-back, .finance-app-wrapper.light .desc-clear { background: rgba(15,23,42,0.08); color: #0f172a; }
        .finance-app-wrapper.light .qt-x { color: #dc2626; }

        /* «بابت چی بود؟»: دسکتاپ داخل فرم، موبایل به صورت شیت پایین */
        .finance-app-wrapper .desc-dd > .cat-dd-panel.tag-box { display: block; margin: 0 0 15px; padding: 12px; background: rgba(0,0,0,0.18); }
        .finance-app-wrapper.light .desc-dd > .cat-dd-panel.tag-box { background: rgba(15,23,42,0.04); }
        .finance-app-wrapper .desc-trigger {
            display: none; align-items: center; justify-content: space-between; gap: 10px;
            margin: 0 0 15px; text-align: right; cursor: pointer; color: inherit; font-weight: bold;
            background: rgba(251,191,36,0.12); border-color: rgba(251,191,36,0.35);
        }
        .finance-app-wrapper.light .desc-trigger { background: rgba(245,158,11,0.12); border-color: rgba(245,158,11,0.4); color: #0f172a; }
        .finance-app-wrapper .tag-search { display: none; }
        .finance-app-wrapper .cat-dd-panel.dd-sheet .tag-search { display: block; margin-bottom: 12px; }
        .finance-app-wrapper .cat-dd-panel.dd-sheet .tag-box-label { display: none; }
        .finance-app-wrapper .cat-dd-panel.dd-sheet .tag-grid { max-height: 44vh; }
        .finance-app-wrapper #add-tag-modal { z-index: 10002; } /* بالای شیت باز شود */
        @media (max-width: 640px), (hover: none) and (pointer: coarse) and (max-width: 1024px) {
            .finance-app-wrapper .desc-trigger { display: flex; }
            .finance-app-wrapper .desc-dd > .cat-dd-panel.tag-box { display: none; }
        }

        /* دکمهٔ ثبت همیشه بالای منوی پایین در دسترس است */
        .finance-app-wrapper #submit-btn {
            position: sticky; bottom: 84px; z-index: 40; margin-top: 4px; padding: 14px;
            background: #4ade80; color: #052e16; border: none; font-size: 1.02rem;
            box-shadow: 0 8px 24px rgba(0,0,0,0.35);
        }
        .finance-app-wrapper #submit-btn:active { background: #22c55e; }
        .finance-app-wrapper.light #submit-btn { background: #16a34a; color: #fff; box-shadow: 0 8px 22px rgba(22,163,74,0.3); }

        /* پیام کوتاه «ثبت شد» */
        .finance-app-wrapper .toast {
            position: fixed; left: 50%; top: calc(14px + env(safe-area-inset-top, 0px));
            transform: translate(-50%, -16px); opacity: 0; pointer-events: none;
            padding: 10px 18px; border-radius: 14px; font-size: 0.88rem; font-weight: bold; white-space: nowrap;
            background: rgba(15,32,39,0.96); border: 1px solid var(--glass-border); color: #fff;
            box-shadow: 0 10px 30px rgba(0,0,0,0.4); z-index: 100001;
            transition: opacity 0.2s ease, transform 0.2s ease;
        }
        .finance-app-wrapper .toast.show { opacity: 1; transform: translate(-50%, 0); }
        .finance-app-wrapper.light .toast { background: #0f172a; }
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
            'date' => $row->tx_date,
            'uid' => isset($row->client_uid) ? (string)$row->client_uid : ''
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
    $server_at = (int) round(microtime(true) * 1000);
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
                <form id="transaction-form" novalidate onsubmit="event.preventDefault(); return false;">

                    <div class="toggle-container">
                        <button type="button" class="toggle-btn active" data-value="expense">مخارج</button>
                        <button type="button" class="toggle-btn" data-value="income">درآمد</button>
                    </div>
                    <input type="hidden" id="type" value="expense">
                    <div class="form-group">
                        <input type="text" id="amount" inputmode="numeric" placeholder="مبلغ (تومان)" autocomplete="off">
                    </div>
                    <div class="form-group desc-wrap" id="desc-wrap">
                        <input type="text" id="desc" placeholder="✍️ بابت چی بود؟" autocomplete="off" enterkeyhint="done">
                        <button type="button" class="desc-clear" id="desc-clear" title="پاک کردن">✕</button>
                    </div>
                    <div class="cat-dd desc-dd" id="tag-dd">
                    <!-- موبایل: این دکمه موارد آماده را به صورت شیت پایین باز می‌کند؛ دسکتاپ: موارد زیر فیلد دیده می‌شوند -->
                    <button type="button" class="desc-trigger" id="tag-dd-btn">
                        <span>📋 موارد آماده</span>
                        <span class="qt-go">‹</span>
                    </button>
                    <div class="cat-dd-panel tag-box" id="tag-box">
                        <div class="sheet-title"><span>📋 موارد آماده</span><button type="button" class="sheet-close">بستن</button></div>
                        <input type="text" id="tag-search" class="cat-search tag-search" placeholder="🔍 جستجو..." autocomplete="off">
                        <div class="tag-box-label"><span>موارد آماده</span></div>
                        <div class="tag-head" id="tag-head" style="display:none;">
                            <button type="button" class="tag-back" id="tag-back-btn">→ بازگشت</button>
                            <span class="tag-head-title" id="tag-head-title"></span>
                        </div>
                        <div class="tag-grid" id="tag-items"></div>
                        <div class="dd-actions">
                            <button type="button" class="dd-act" id="tag-add-btn">➕ جدید</button>
                            <button type="button" class="dd-act" id="tag-del-btn">🗑 حذف</button>
                        </div>
                    </div>
                    </div>
                    <button type="submit" id="submit-btn">ثبت تراکنش</button>
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

        <!-- منوی هر ردیف تراکنش -->
        <div class="modal-overlay" id="tx-menu">
            <div class="modal-box">
                <div class="menu-head">
                    <span class="tx-avatar" id="txm-avatar"></span>
                    <div class="mh-text">
                        <div class="mh-title" id="txm-title"></div>
                        <div class="mh-sub" id="txm-sub"></div>
                    </div>
                    <b id="txm-amount"></b>
                </div>
                <button type="button" class="menu-item" data-act="edit">✏️ ویرایش</button>
                <button type="button" class="menu-item" data-act="event">🧳 گذاشتن در رویداد</button>
                <button type="button" class="menu-item danger" data-act="delete">🗑 حذف</button>
                <button type="button" class="modal-btn cancel" style="width:100%;" onclick="window.closeTxMenu()">بستن</button>
            </div>
        </div>

        <div class="sheet-backdrop" id="sheet-backdrop"></div>
        <div class="toast" id="toast"></div>

    </div>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            const formatter = new Intl.NumberFormat('fa-IR');
            const plain = new Intl.NumberFormat('fa-IR', { useGrouping: false });
            const fmt = n => formatter.format(n);
            let transactions = <?php echo $tx_json ?: '[]'; ?>;
            const ajaxUrl = "<?php echo esc_url($ajax_url); ?>";
            const SERVER_AT = <?php echo $server_at; ?>;

            const PEOPLE = { sarina: 'سارینا', hossein: 'حسین' };
            const MONTHS = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];

            const $ = id => document.getElementById(id);
            // گوشی: یا صفحهٔ باریک، یا صفحهٔ لمسی (حتی اگر قالب متای viewport نداشته باشد و عرض را ۹۸۰ نشان دهد)
            const MOBILE_MQ = window.matchMedia('(max-width: 640px), (hover: none) and (pointer: coarse) and (max-width: 1024px)');
            const isMobile = () => MOBILE_MQ.matches;
            const appRoot = document.querySelector('.finance-app-wrapper');
            const amountInput = $('amount');
            const descInput = $('desc');
            const typeInput = $('type');

            let currentTab = 'sarina';        // sarina | hossein | report
            let currentSection = 'add';       // add | inst | history
            let currentFilter = 'all';
            let currentMonthFilter = '';
            let itemToDelete = null;          // {kind: 'tx', id}
            let currentView = 'date';         // date | cat | group
            let selectMode = false;
            let selectedIds = new Set();
            let groupFilter = '';             // فیلتر نمایش بر اساس گروه دوم
            let lastFilteredTx = [];          // آخرین لیست نمایش‌داده‌شده در تاریخچه
            let accOpen = {};                 // کشوهای باز/بسته، تا بعد از هر ذخیره همان‌طور بمانند
            let txMenuId = null;

            /* ---------- پیام کوتاه ---------- */
            let toastTimer = null;
            function toast(msg) {
                const el = $('toast');
                if (!el) return;
                el.textContent = msg;
                el.classList.add('show');
                clearTimeout(toastTimer);
                toastTimer = setTimeout(() => el.classList.remove('show'), 1800);
            }

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

            const ICON_MORE = '<svg viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="2"></circle><circle cx="12" cy="12" r="2"></circle><circle cx="12" cy="19" r="2"></circle></svg>';

            /* جدا کردن ایموجی اول متن (🚕 اسنپ ← آیکون + عنوان) */
            const ICON_RE = /^((?:\p{Extended_Pictographic}|⇄)[️‍\p{Extended_Pictographic}]*)\s*(.*)$/u;
            function splitIcon(text) {
                const s = String(text || '').replace('📌 ', '').trim();
                const m = s.match(ICON_RE);
                return (m && m[2]) ? { icon: m[1], label: m[2] } : { icon: '', label: s };
            }

            /* یک ردیف تراکنش (مشترک بین نمای روزانه، دسته‌بندی، رویداد و اقساط) */
            function txRowHtml(item, opts) {
                opts = opts || {};
                const inc = item.type === 'income';
                const g = groupOf(item.id);
                const pend = isPending(item.id);
                const picked = selectedIds.has(item.id);
                const parts = splitIcon(item.desc);
                // در نمای «بر اساس عنوان» و اقساط، عنوان بالای کشوست؛ پس اینجا تاریخ می‌آید
                const title = opts.showDesc === false ? dateLabel(item.date) : parts.label;
                const meta = (currentTab === 'report' ? '<span class="person-badge ' + esc(item.person) + '">' + esc(PEOPLE[item.person] || '') + '</span>' : '') +
                    (opts.showDate ? '<span>' + dateLabel(item.date) + '</span>' : '') +
                    (g && opts.showGroup !== false ? '<span class="group-badge">🧳 ' + esc(g) + '</span>' : '') +
                    (pend ? '<span class="pending-badge" title="در حال ذخیره روی سرور">⏳</span>' : '');
                return '<div class="transaction-item' + (picked ? ' picked' : '') + (pend ? ' pending' : '') + '" data-tx="' + item.id + '">' +
                    '<input type="checkbox" class="tx-check" data-check="' + item.id + '"' + (picked ? ' checked' : '') + '>' +
                    '<span class="tx-avatar ' + (inc ? 'in' : 'out') + '">' + (parts.icon ? esc(parts.icon) : (inc ? '▲' : '▼')) + '</span>' +
                    '<div class="tx-info">' +
                        '<span class="tx-desc">' + esc(title) + '</span>' +
                        '<div class="tx-meta">' + meta + '</div>' +
                    '</div>' +
                    '<div class="tx-end">' +
                        '<span class="tx-amount ' + (inc ? 'text-green' : 'text-red') + '">' + (inc ? '+' : '-') + fmt(item.amount) + '</span>' +
                        '<button type="button" class="tx-more" title="گزینه‌ها">' + ICON_MORE + '</button>' +
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
            function dayTitle(dateStr) {
                const now = new Date();
                const yest = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1);
                if (dateStr === ymd(now)) return 'امروز، ' + dateLabel(dateStr);
                if (dateStr === ymd(yest)) return 'دیروز، ' + dateLabel(dateStr);
                return dateLabel(dateStr);
            }
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
               ذخیره در لحظه + ارسال در پس‌زمینه
               - هر ثبت/ویرایش/حذف همان لحظه روی صفحه و در localStorage اعمال می‌شود.
               - صف در یک درخواست دسته‌ای (finance_sync) و با keepalive فرستاده می‌شود،
                 پس حتی اگر اپ بسته شود درخواست تمام می‌شود.
               - هر تراکنش uid یکتا دارد؛ ارسال دوباره هیچ‌وقت تکراری ثبت نمی‌کند.
               - تا سرور تأیید نکند، هیچ موردی از صف پاک نمی‌شود.
               ================================================================ */
            const QUEUE_KEY = 'finance_pending_ops';
            const CACHE_KEY = 'finance_tx_cache';
            let pendingOps = [];
            let inflight = 0;
            let flushAgain = false;
            let lastSyncError = '';
            let authError = false;
            let cacheSeen = SERVER_AT;

            function newUid() { return 'c' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10); }
            function fromP(p) { return p ? { person: p.person, type: p.type, amount: Number(p.amount) || 0, desc: String(p.desc || '') } : {}; }
            function targetKey(op) { return op.uid ? 'u' + op.uid : 'i' + op.id; }

            function normalizeOp(op) {
                if (!op || typeof op !== 'object') return null;
                if (op.v === 2) { delete op._inflight; return op; }
                // قالب قدیمی صف (قبل از همگام‌سازی دسته‌ای)
                const pl = op.payload || {};
                const id = Number(op.id) || 0;
                if (op.kind === 'delete') return { v: 2, kind: 'delete', id: id, uid: '', at: 0 };
                if (op.kind !== 'create' && op.kind !== 'update') return null;
                return { v: 2, kind: op.kind, id: id, uid: op.kind === 'create' ? newUid() : '', date: op.date || '', at: 0,
                         p: { person: pl.person, type: pl.tx_type, amount: Number(pl.amount) || 0, desc: pl.tx_desc || '' } };
            }
            function readQueue() {
                try {
                    const v = JSON.parse(localStorage.getItem(QUEUE_KEY));
                    return Array.isArray(v) ? v.map(normalizeOp).filter(Boolean) : [];
                } catch(e) { return []; }
            }
            function saveQueue() {
                try { localStorage.setItem(QUEUE_KEY, JSON.stringify(pendingOps, (k, v) => k === '_inflight' ? undefined : v)); } catch(e) {}
            }
            function saveCacheNow() {
                try { localStorage.setItem(CACHE_KEY, JSON.stringify({ at: Date.now(), seen: cacheSeen, tx: transactions })); } catch(e) {}
            }
            let cacheTimer = null;
            function scheduleCacheSave() {
                clearTimeout(cacheTimer);
                cacheTimer = setTimeout(saveCacheNow, 400);
            }
            function readCache() {
                try { const v = JSON.parse(localStorage.getItem(CACHE_KEY)); return (v && Array.isArray(v.tx)) ? v : null; } catch(e) { return null; }
            }

            let tmpCounter = -1;
            function nextTmpId() { return tmpCounter--; }

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
                scheduleFlush();
                setTimeout(renderNetBar, 4500); // اگر طول کشید، نوار وضعیت نشان داده شود
            }
            function removeOp(op) {
                const i = pendingOps.indexOf(op);
                if (i >= 0) pendingOps.splice(i, 1);
            }
            function scheduleFlush() { setTimeout(() => flushQueue(), 0); }

            function opPayload(tx) { return { person: tx.person, type: tx.type, amount: tx.amount, desc: tx.desc }; }

            /* ثبت یا به‌روزرسانی یک تراکنش در صف */
            function queueUpsert(tx) {
                const key = tx.uid ? 'u' + tx.uid : 'i' + tx.id;
                // اگر ساختنش هنوز ارسال نشده، همان را اصلاح کن؛ وگرنه یک ویرایش جدا
                const waiting = pendingOps.find(o => !o._inflight && (o.kind === 'create' || o.kind === 'update') && targetKey(o) === key);
                if (waiting) {
                    waiting.p = opPayload(tx);
                    saveQueue();
                    scheduleFlush();
                } else {
                    enqueue({ v: 2, kind: 'update', id: tx.id, uid: tx.uid || '', at: Date.now(), p: opPayload(tx) });
                }
                scheduleCacheSave();
            }

            function queueDelete(tx) {
                const key = tx.uid ? 'u' + tx.uid : 'i' + tx.id;
                // کارهای ارسال‌نشدهٔ همین مورد لازم نیست؛ حذف همیشه فرستاده می‌شود تا اگر قبلاً رسیده بود، پاک شود
                pendingOps = pendingOps.filter(o => o._inflight || targetKey(o) !== key);
                enqueue({ v: 2, kind: 'delete', id: tx.id, uid: tx.uid || '', at: Date.now() });
                scheduleCacheSave();
            }

            function remapId(oldId, newId, newDate, uid) {
                if (!newId) return;
                const tx = transactions.find(t => t.id === oldId);
                if (oldId !== newId) {
                    const dup = transactions.find(t => t.id === newId);
                    if (tx && dup && dup !== tx) transactions = transactions.filter(t => t !== dup);
                    if (txGroups[oldId]) { txGroups[newId] = txGroups[oldId]; delete txGroups[oldId]; writeGroups(txGroups); }
                    if (selectedIds.has(oldId)) { selectedIds.delete(oldId); selectedIds.add(newId); }
                    pendingOps.forEach(op => { if (op.id === oldId) op.id = newId; });
                    if (txMenuId === oldId) txMenuId = newId;
                    if (emTarget && emTarget.id === oldId) emTarget.id = newId;
                    if (itemToDelete && itemToDelete.id === oldId) itemToDelete.id = newId;
                    pendingGroupIds = pendingGroupIds.map(x => x === oldId ? newId : x);
                }
                if (tx) { tx.id = newId; if (newDate) tx.date = newDate; if (uid) tx.uid = uid; }
            }

            function flushQueue(opts) {
                opts = opts || {};
                const hiding = !!opts.keepalive;
                if (!pendingOps.length || (authError && !opts.force)) { renderNetBar(); return; }
                if (typeof navigator !== 'undefined' && navigator.onLine === false) { renderNetBar(); return; }
                if (inflight > 0 && !hiding) { flushAgain = true; return; }

                // مواردی که الان در راه‌اند (و هر کار دیگری روی همان تراکنش) صبر می‌کنند تا ترتیب به هم نخورد
                const busy = new Set();
                pendingOps.forEach(op => { if (op._inflight) busy.add(targetKey(op)); });
                const batch = pendingOps.filter(op => !op._inflight && !busy.has(targetKey(op)));
                if (!batch.length) return;

                batch.forEach(op => { op._inflight = true; });
                inflight++;
                renderNetBar();

                const fd = new URLSearchParams();
                fd.append('action', 'finance_sync');
                fd.append('ops', JSON.stringify(batch.map(op => {
                    const p = op.p || {};
                    return { kind: op.kind, id: op.id > 0 ? op.id : 0, uid: op.uid || '', date: op.date || '', person: p.person, type: p.type, amount: p.amount, desc: p.desc };
                })));
                const bodyStr = fd.toString();
                let ctrl = null, timer = null;
                if (!hiding && typeof AbortController !== 'undefined') {
                    ctrl = new AbortController();
                    timer = setTimeout(() => ctrl.abort(), 20000);
                }

                return fetch(ajaxUrl, {
                    method: 'POST',
                    body: bodyStr,
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    credentials: 'same-origin',
                    keepalive: bodyStr.length < 60000,   // با بستن اپ قطع نمی‌شود
                    signal: ctrl ? ctrl.signal : undefined
                }).then(res => {
                    if (res.status === 401 || res.status === 403) { const err = new Error('auth'); err.auth = true; throw err; }
                    return res.json();
                }).then(r => {
                    if (!r || !r.success || !r.data || !Array.isArray(r.data.results)) throw new Error('bad response');
                    authError = false;
                    lastSyncError = '';
                    let dropped = 0;
                    batch.forEach((op, i) => {
                        const res = r.data.results[i] || { ok: false, retry: true };
                        op._inflight = false;
                        if (res.ok) {
                            if (op.kind === 'create') remapId(op.id, Number(res.id) || 0, res.date, op.uid);
                            removeOp(op);
                        } else if (res.retry && (op.tries = (op.tries || 0) + 1) < 5) {
                            // دفعهٔ بعد دوباره امتحان می‌شود
                        } else {
                            removeOp(op);
                            dropped++;
                        }
                    });
                    if (dropped) toast('⚠️ ' + plain.format(dropped) + ' مورد را سرور نپذیرفت');
                }).catch(err => {
                    batch.forEach(op => { op._inflight = false; });
                    if (err && err.auth) { authError = true; lastSyncError = ''; }
                    else lastSyncError = 'ارتباط با سرور برقرار نشد؛ خودکار دوباره تلاش می‌شود.';
                }).finally(() => {
                    if (timer) clearTimeout(timer);
                    inflight--;
                    saveQueue();
                    refreshPendingIds();
                    scheduleCacheSave();
                    updateUI();
                    if (flushAgain && inflight === 0) {
                        flushAgain = false;
                        if (!lastSyncError && !authError) scheduleFlush();
                    }
                });
            }

            function renderNetBar() {
                const bar = $('net-bar');
                const txt = $('net-bar-text');
                const btn = $('net-sync-btn');
                if (!bar || !txt) return;
                const n = pendingOps.length;
                const offline = (typeof navigator !== 'undefined' && navigator.onLine === false);
                const oldest = pendingOps.reduce((m, o) => Math.min(m, o.at || 0), Infinity);
                const slow = n > 0 && (Date.now() - oldest) > 4000;
                bar.classList.remove('syncing');
                // ارسال عادی چند صدم ثانیه است؛ نوار فقط وقتی مشکلی هست دیده می‌شود
                if (!offline && !authError && !(n > 0 && (slow || lastSyncError))) { bar.classList.remove('show'); return; }
                bar.classList.add('show');
                if (btn) btn.textContent = authError ? 'ورود دوباره' : 'تلاش مجدد';
                if (authError) {
                    txt.textContent = '🔒 ' + plain.format(n) + ' مورد روی گوشی ذخیره شده؛ برای ارسال دوباره وارد شوید';
                } else if (offline) {
                    txt.textContent = n ? '📴 آفلاین — ' + plain.format(n) + ' مورد روی گوشی ذخیره شده و با وصل‌شدن نت خودکار ارسال می‌شود' : '📴 آفلاین — تغییرات روی گوشی ذخیره می‌شود';
                } else if (inflight) {
                    bar.classList.add('syncing');
                    txt.textContent = 'در حال ارسال ' + plain.format(n) + ' مورد...';
                } else {
                    txt.textContent = '⏳ ' + plain.format(n) + ' مورد در صف ارسال' + (lastSyncError ? ' — ' + lastSyncError : '');
                }
            }

            function visibleTx() {
                return currentTab === 'report' ? transactions : transactions.filter(t => t.person === currentTab);
            }

            /* ---------- فیلتر ماه (شمسی) ---------- */
            function renderMonthSelect() {
                const select = $('month-filter');
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

            $('month-filter').addEventListener('change', (e) => {
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
            const themeBtn = $('theme-toggle');
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
            const fsBtn = $('fs-toggle');
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
                        $(id).style.display = (s === currentSection && !(isReport && personOnly(s))) ? 'block' : 'none';
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
                renderNow();
                try { appRoot.scrollTo({ top: 0 }); } catch(e) { appRoot.scrollTop = 0; }
            }

            document.querySelectorAll('.nav-btn[data-section]').forEach(btn => {
                btn.addEventListener('click', () => setSection(btn.getAttribute('data-section')));
            });

            /* ---------- خروج با تأیید ---------- */
            $('nav-logout-btn').addEventListener('click', () => {
                $('logout-modal').classList.add('active');
            });
            window.closeLogoutModal = function() {
                $('logout-modal').classList.remove('active');
            };
            $('confirm-logout-btn').addEventListener('click', function() {
                this.textContent = 'در حال خروج...';
                this.disabled = true;
                saveCacheNow();
                $('logout-form').submit();
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
                $('person-breakdown').style.display = isReport ? 'block' : 'none';
                $('balance-label').textContent = isReport ? 'موجودی کل (حسین + سارینا)' : 'موجودی ' + PEOPLE[tab];
                $('history-title').textContent = isReport ? 'تاریخچه کل' : 'تاریخچه ' + PEOPLE[tab];

                currentMonthFilter = '';
                groupFilter = '';
                selectedIds.clear();
                resetForm();
                renderNow();
            }

            /* ---------- منوی هر ردیف ---------- */
            function openTxMenu(id) {
                const tx = transactions.find(t => t.id === id);
                if (!tx) return;
                txMenuId = id;
                const inc = tx.type === 'income';
                const parts = splitIcon(tx.desc);
                const g = groupOf(id);
                const av = $('txm-avatar');
                av.className = 'tx-avatar ' + (inc ? 'in' : 'out');
                av.textContent = parts.icon || (inc ? '▲' : '▼');
                $('txm-title').textContent = parts.label;
                $('txm-sub').textContent = [dateLabel(tx.date), PEOPLE[tx.person] || '', g ? '🧳 ' + g : '', isPending(id) ? '⏳ در حال ذخیره' : ''].filter(Boolean).join('  |  ');
                const am = $('txm-amount');
                am.className = inc ? 'text-green' : 'text-red';
                am.textContent = (inc ? '+' : '-') + fmt(tx.amount);
                $('tx-menu').classList.add('active');
            }
            window.closeTxMenu = function() {
                txMenuId = null;
                $('tx-menu').classList.remove('active');
            };
            document.querySelectorAll('#tx-menu .menu-item').forEach(b => {
                b.addEventListener('click', () => {
                    const id = txMenuId;
                    const act = b.getAttribute('data-act');
                    window.closeTxMenu();
                    if (id === null) return;
                    if (act === 'edit') window.editTx(id);
                    else if (act === 'event') window.setEvent(id);
                    else if (act === 'delete') window.deleteTx(id);
                });
            });

            /* ---------- ویرایش / حذف تراکنش ---------- */
            window.editTx = function(id) {
                const tx = transactions.find(t => t.id === id);
                if(!tx) return;
                openEditModal('tx', tx);
            };

            /* ---------- مودال ویرایش ---------- */
            const emModal = $('edit-modal');
            const emAmount = $('em-amount');
            const emDesc = $('em-desc');
            const emRoutine = $('em-routine');
            const emSave = $('em-save-btn');
            let emTarget = null;
            let emType = 'expense';

            function setEmType(t) {
                emType = t;
                document.querySelectorAll('#em-type-wrap .toggle-btn').forEach(b => b.classList.toggle('active', b.getAttribute('data-value') === t));
            }
            document.querySelectorAll('#em-type-wrap .toggle-btn').forEach(b => {
                b.addEventListener('click', () => setEmType(b.getAttribute('data-value')));
            });

            function bindAmountFormat(el) {
                el.addEventListener('input', function(e) {
                    const parsed = parsePersianInt(e.target.value);
                    e.target.value = parsed > 0 ? fmt(parsed) : '';
                });
            }
            bindAmountFormat(emAmount);
            bindAmountFormat(amountInput);

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
                $('em-cat-search').value = '';
                $('em-cat-current').textContent = currentDesc || 'انتخاب دسته';
                renderCatItems('');
            }

            function renderCatItems(filter) {
                const box = $('em-cat-items');
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
                        $('em-cat-current').textContent = c.label;
                        closeAllDd();
                        renderCatItems('');
                    });
                    box.appendChild(el);
                });
            }

            function openEditModal(kind, item) {
                emTarget = { kind: kind, id: item.id };
                $('em-type-wrap').style.display = 'flex';
                $('em-routine-wrap').style.display = 'none';
                $('em-cat-wrap').style.display = 'block';
                $('edit-modal-title').textContent = 'ویرایش تراکنش ' + (PEOPLE[item.person] || '');
                emAmount.value = fmt(item.amount);
                emDesc.value = item.desc;
                emDesc.placeholder = 'بابت چی بود؟';
                setEmType(item.type);
                fillEmCats(item.desc);
                emModal.classList.add('active');
            }

            $('em-cat-btn').addEventListener('click', function(e) {
                e.stopPropagation();
                const dd = $('em-cat-dd');
                if (dd.classList.contains('open')) { closeAllDd(); return; }
                openDd(dd);
                const se = $('em-cat-search');
                se.value = '';
                renderCatItems('');
                if (!isMobile()) se.focus();
            });
            $('em-cat-search').addEventListener('input', function() {
                renderCatItems(this.value);
            });
            $('em-cat-search').addEventListener('keydown', function(e) {
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
                if (amount <= 0 || !text) { toast('مبلغ و عنوان را وارد کنید'); return; }

                const tx = transactions.find(x => x.id === emTarget.id);
                if (tx) {
                    tx.type = emType; tx.amount = amount; tx.desc = text;
                    queueUpsert(tx);
                }
                window.closeEditModal();
                toast('✓ ذخیره شد');
                updateUI();
            });
            [emAmount, emDesc].forEach(el => el.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); emSave.click(); } }));

            window.deleteTx = function(id) {
                itemToDelete = { kind: 'tx', id: id };
                $('delete-modal').classList.add('active');
            };

            window.closeModal = function() {
                itemToDelete = null;
                $('delete-modal').classList.remove('active');
            };

            $('confirm-delete-btn').addEventListener('click', function() {
                if (!itemToDelete) return;
                const tx = transactions.find(t => t.id === itemToDelete.id);
                if (tx) {
                    transactions = transactions.filter(t => t !== tx);
                    selectedIds.delete(tx.id);
                    setGroup([tx.id], '');
                    queueDelete(tx);
                }
                window.closeModal();
                toast('🗑 حذف شد');
                updateUI();
            });

            /* ---------- موارد آماده (دکمه‌های دوتایی + زیرمنو) ---------- */
            const DEFAULT_TAGS = ['🚕 اسنپ', '☕ کافه', '🛒 سوپر', '⛽ بنزین', '🍽 غذا', '💳 اقساط', '💰 حقوق', '🎧 پشتیبانی', '👤 اکانت', '💆 لیزر', '💬 مشاوره', '🎬 سینما', '🎭 تئاتر', '💧 آب'];
            const DEFAULT_INST_TAGS = ['🛍 اقساط دیجی پی', '🏦 اقساط تارا', '🚕 اقساط اسنپ', '🪙 اقساط اوانو', '🔍 اقساط ترب پی', '💙 اقساط بلو', '🛡 اقساط ازکی', '👨 اقساط قرعه کشی حسین', '👩 اقساط قرعه کشی سارینا'];
            const DEFAULT_INCOME_TAGS = ['💰 حقوق', '🎧 پشتیبانی', '👤 اکانت'];
            const INST_TAG = '💳 اقساط';
            const TAG_KEYS = {
                main: { custom: 'finance_custom_tags', hidden: 'finance_hidden_tags', base: DEFAULT_TAGS },
                inst: { custom: 'finance_inst_custom_tags', hidden: 'finance_inst_hidden_tags', base: DEFAULT_INST_TAGS }
            };
            const INCOME_KEY = 'finance_income_tags';
            let tagView = 'root';             // root | inst  (داخل زیرمنوی اقساط)
            let pickedTag = '';
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
                if (pickedTag === tagText) pickedTag = '';
                renderTags();
            }

            function setTxType(kind) {
                const btn = document.querySelector('#transaction-form .toggle-btn[data-value="' + kind + '"]');
                if (btn) btn.click();
            }

            function syncDescWrap() {
                $('desc-wrap').classList.toggle('has-val', descInput.value !== '');
            }
            const tagSearch = $('tag-search');
            const descSheetOpen = () => $('tag-dd').classList.contains('open') && isMobile();
            function openDescSheet() {
                tagSearch.value = '';
                openDd($('tag-dd'));
                renderTags();
            }

            function resetTagPick() {
                pickedTag = '';
                tagView = 'root';
                syncDescWrap();
                renderTags();
            }

            function pickTag(tagText) {
                descInput.value = tagText.replace('📌 ', '');
                pickedTag = tagText;
                syncDescWrap();
                setTxType(isIncomeTag(tagText) ? 'income' : 'expense');
                renderTags();
                if (descSheetOpen()) closeAllDd(); // روی موبایل با انتخاب، شیت بسته می‌شود
            }

            function tileEl(tagText, scope, isGroup) {
                const parts = splitIcon(tagText);
                // داخل زیرمنوی اقساط، کلمهٔ تکراری «اقساط» حذف می‌شود تا اسم کامل جا شود
                if (tagView === 'inst' && scope === 'inst') parts.label = parts.label.replace(/^اقساط\s+/, '') || parts.label;
                const removable = tagDelMode && !isGroup;
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'qt' + (isGroup ? ' group' : '') + (tagText === pickedTag ? ' sel' : '') + (removable ? ' removable' : '');
                b.title = parts.label;
                b.innerHTML = '<span class="qt-icon">' + esc(parts.icon || '🏷') + '</span>' +
                    '<span class="qt-label">' + esc(parts.label) + '</span>' +
                    (isGroup ? '<span class="qt-go">‹</span>' : '') +
                    (removable ? '<span class="qt-x">✕</span>' : '');
                b.addEventListener('click', () => {
                    if (isGroup) { tagView = 'inst'; renderTags(); return; }
                    if (removable) { removeTag(scope, tagText); return; }
                    pickTag(tagText);
                });
                return b;
            }

            function renderTagItems() {
                const box = $('tag-items');
                if (!box) return;
                // در شیت موبایل با جستجوی خود شیت؛ در دسکتاپ با متنی که در «بابت چی بود؟» نوشته شده
                const f = descSheetOpen() ? normText(tagSearch.value) : (pickedTag ? '' : normText(descInput.value));
                const hit = t => normText(t).indexOf(f) >= 0;
                const inst = tagsOf('inst');
                const inSub = tagView === 'inst';

                $('tag-head').style.display = inSub ? 'flex' : 'none';
                if (inSub) $('tag-head-title').textContent = INST_TAG;

                let list;
                if (inSub) list = inst.filter(t => !f || hit(t)).map(t => [t, 'inst', false]);
                else if (f) list = mainTags().filter(hit).map(t => [t, 'main', t === INST_TAG]).concat(inst.filter(hit).map(t => [t, 'inst', false]));
                else list = mainTags().map(t => [t, 'main', t === INST_TAG]);

                box.innerHTML = '';
                if (!list.length) {
                    box.innerHTML = '<div class="tag-empty">' + (!f ? 'موردی وجود ندارد؛ با «➕» اضافه کنید' : descSheetOpen() ? 'موردی پیدا نشد' : '✍️ همین متنی که نوشتید ثبت می‌شود') + '</div>';
                    return;
                }
                const frag = document.createDocumentFragment();
                list.forEach(a => frag.appendChild(tileEl(a[0], a[1], a[2])));
                box.appendChild(frag);
            }

            function renderTags() {
                renderTagItems();
                const del = $('tag-del-btn');
                if (del) {
                    del.classList.toggle('on', tagDelMode);
                    del.textContent = tagDelMode ? '✅ پایان حذف' : '🗑 حذف';
                }
                const add = $('tag-add-btn');
                if (add) add.textContent = tagView === 'inst' ? '➕ قسط جدید' : '➕ مورد جدید';
            }

            descInput.addEventListener('input', function() {
                if (pickedTag && descInput.value !== pickedTag.replace('📌 ', '')) pickedTag = '';
                syncDescWrap();
                renderTagItems();
            });
            $('desc-clear').addEventListener('click', function() {
                descInput.value = '';
                pickedTag = '';
                syncDescWrap();
                renderTags();
                descInput.focus();
            });
            $('tag-dd-btn').addEventListener('click', function(e) {
                e.stopPropagation();
                openDescSheet();
            });
            tagSearch.addEventListener('input', renderTagItems);
            tagSearch.addEventListener('keydown', function(e) { if (e.key === 'Enter') e.preventDefault(); });
            $('tag-back-btn').addEventListener('click', function() {
                tagView = 'root';
                renderTags();
            });
            $('tag-del-btn').addEventListener('click', function() {
                tagDelMode = !tagDelMode;
                renderTags();
            });
            $('tag-add-btn').addEventListener('click', function() { openTagModal(tagView === 'inst' ? 'inst' : 'main'); });

            /* مودال افزودن */
            function setNewTagKind(kind) {
                newTagKind = kind;
                document.querySelectorAll('#new-tag-type .toggle-btn').forEach(b => b.classList.toggle('active', b.getAttribute('data-value') === kind));
            }
            document.querySelectorAll('#new-tag-type .toggle-btn').forEach(b => {
                b.addEventListener('click', () => setNewTagKind(b.getAttribute('data-value')));
            });

            function renderIconGrid() {
                const grid = $('icon-grid');
                const prev = $('icon-preview');
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
                $('add-tag-title').textContent = scope === 'inst' ? 'افزودن قسط جدید' : 'افزودن مورد آماده';
                selectedIcon = scope === 'inst' ? '💳' : '⭐';
                setNewTagKind('expense');
                renderIconGrid();
                $('new-tag-input').value = '';
                $('add-tag-modal').classList.add('active');
                $('new-tag-input').focus();
            }

            window.closeTagModal = function() {
                $('add-tag-modal').classList.remove('active');
            };

            $('save-new-tag-btn').addEventListener('click', function() {
                let inputVal = $('new-tag-input').value.trim();
                if (!inputVal) return;
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
                tagView = tagModalScope === 'inst' ? 'inst' : 'root';
                renderTags();
                window.closeTagModal();
            });
            $('new-tag-input').addEventListener('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); $('save-new-tag-btn').click(); }
            });

            /* ---------- فرم تراکنش ---------- */
            const toggleBtns = document.querySelectorAll('#transaction-form .toggle-btn');
            toggleBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    toggleBtns.forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    typeInput.value = btn.getAttribute('data-value');
                });
            });

            function resetForm() {
                amountInput.value = '';
                descInput.value = '';
                toggleBtns.forEach(b => b.classList.toggle('active', b.getAttribute('data-value') === 'expense'));
                typeInput.value = 'expense';
                $('form-title').textContent = formTitleText();
                resetTagPick();
            }

            function transferMirror(person, type, desc) {
                const other = otherPerson(person);
                if (normText(desc).indexOf('دریافتی از ' + PEOPLE[other]) !== 0) return null;
                return {
                    person: other,
                    type: type === 'income' ? 'expense' : 'income',
                    desc: (type === 'income' ? 'پرداختی به ' : 'دریافتی از ') + PEOPLE[person]
                };
            }

            /* ثبت محلی (فوری) + گذاشتن در صف ارسال پس‌زمینه */
            function addTransactionLocally(person, type, amount, desc) {
                const id = nextTmpId();
                const uid = newUid();
                const date = ymd(new Date());
                transactions.unshift({ id: id, uid: uid, person: person, type: type, amount: amount, desc: desc, date: date });
                enqueue({ v: 2, kind: 'create', id: id, uid: uid, date: date, at: Date.now(), p: { person: person, type: type, amount: amount, desc: desc } });
                scheduleCacheSave();
                return id;
            }

            $('transaction-form').addEventListener('submit', function(e) {
                e.preventDefault();
                if (currentTab === 'report') return;

                const type = typeInput.value;
                const amount = parsePersianInt(amountInput.value);
                const desc = descInput.value.trim();
                const person = currentTab;

                if (amount <= 0) { toast('مبلغ را وارد کنید'); amountInput.focus(); return; }
                if (!desc) {
                    toast('بنویسید بابت چی بود یا یک مورد آماده انتخاب کنید');
                    descInput.focus();
                    return;
                }

                addTransactionLocally(person, type, amount, desc);
                const mir = transferMirror(person, type, desc);
                if (mir) addTransactionLocally(mir.person, mir.type, amount, mir.desc);

                amountInput.value = '';
                descInput.value = '';
                resetTagPick();
                if (document.activeElement && document.activeElement.blur) document.activeElement.blur();
                toast('✓ ثبت شد — ' + fmt(amount) + ' تومان');
                updateUI();
            });

            /* ---------- کشوها (باز/بسته با انیمیشن، وضعیت حفظ می‌شود) ---------- */
            const ACC_MS = 260;
            function makeDetails(key, open, summaryHtml, rowsHtml) {
                const d = document.createElement('details');
                d.dataset.key = key;
                d.open = open;
                d.innerHTML = '<summary>' + summaryHtml + '</summary><div class="transaction-list">' + rowsHtml + '</div>';
                return d;
            }
            function isOpen(key, def) { return accOpen[key] === undefined ? def : accOpen[key]; }

            function toggleDetails(d) {
                const panel = d.querySelector('.transaction-list');
                if (!panel || d.dataset.busy === '1') return;
                d.dataset.busy = '1';
                const key = d.dataset.key;
                if (d.open) {
                    if (key) accOpen[key] = false;
                    panel.style.height = panel.scrollHeight + 'px';
                    requestAnimationFrame(() => { panel.style.height = '0px'; });
                    setTimeout(() => { d.open = false; panel.style.height = ''; d.dataset.busy = '0'; }, ACC_MS);
                } else {
                    if (key) accOpen[key] = true;
                    d.open = true;
                    const target = panel.scrollHeight;
                    panel.style.height = '0px';
                    requestAnimationFrame(() => { panel.style.height = target + 'px'; });
                    setTimeout(() => { panel.style.height = ''; d.dataset.busy = '0'; }, ACC_MS);
                }
            }

            /* رویدادهای لیست‌ها یک بار و به صورت واگذار ثبت می‌شوند (سریع‌تر از ثبت روی تک‌تک ردیف‌ها) */
            function onCheck(cb) {
                const id = parseInt(cb.getAttribute('data-check'), 10);
                if (cb.checked) selectedIds.add(id); else selectedIds.delete(id);
                const row = cb.closest('.transaction-item');
                if (row) row.classList.toggle('picked', cb.checked);
                updateSumBar();
            }
            function bindListEvents(root) {
                if (!root) return;
                root.addEventListener('click', function(e) {
                    const summary = e.target.closest('summary');
                    if (summary && root.contains(summary)) { e.preventDefault(); toggleDetails(summary.parentElement); return; }
                    const row = e.target.closest('.transaction-item');
                    if (!row || !root.contains(row)) return;
                    if (selectMode) {
                        if (e.target.matches('[data-check]')) return;
                        const cb = row.querySelector('[data-check]');
                        if (cb) { cb.checked = !cb.checked; onCheck(cb); }
                        return;
                    }
                    openTxMenu(parseInt(row.getAttribute('data-tx'), 10));
                });
                root.addEventListener('change', function(e) {
                    if (e.target.matches('[data-check]')) onCheck(e.target);
                });
            }
            bindListEvents($('accordion-list'));
            bindListEvents($('inst-cat-list'));

            /* ---------- اقساط (از روی تراکنش‌های دسته اقساط) ---------- */
            const INST_WORDS = ['اقساط', 'قسط'];
            function isInstTx(t) {
                const d = normText(t.desc);
                return INST_WORDS.some(w => d.indexOf(w) >= 0);
            }

            function renderInstSection() {
                const box = $('inst-cat-list');
                const sum = $('inst-summary');
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

                const keys = Object.keys(groups).sort((a, b) => groups[b].total - groups[a].total);
                box.innerHTML = '';
                keys.forEach(k => {
                    const g = groups[k];
                    g.items.sort((a, b) => b.date.localeCompare(a.date) || b.id - a.id);
                    box.appendChild(makeDetails('i:' + k, isOpen('i:' + k, false),
                        '<span>' + esc(g.name) + '<span class="cat-sub">' + plain.format(g.items.length) + ' بار</span></span>' +
                        '<div class="day-summary-text">جمع: <b>' + fmt(g.total) + '</b></div>',
                        g.items.map(item => txRowHtml(item, { showDesc: false })).join('')));
                });
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

            const EMPTY_HTML = '<div style="text-align:center; opacity:0.6; padding:10px;">تراکنشی یافت نشد</div>';

            function renderCategories(list) {
                const container = $('accordion-list');
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
                if (keys.length === 0) { container.innerHTML = EMPTY_HTML; return; }
                const frag = document.createDocumentFragment();
                keys.forEach(k => {
                    const g = groups[k];
                    g.items.sort((a, b) => b.date.localeCompare(a.date) || b.id - a.id);
                    const parts = [];
                    if (g.income > 0) parts.push('<span class="text-green">▲ ' + fmt(g.income) + '</span>');
                    if (g.expense > 0) parts.push('<span class="text-red">▼ ' + fmt(g.expense) + '</span>');
                    frag.appendChild(makeDetails('c:' + k, isOpen('c:' + k, false),
                        '<span>' + esc(g.name) + '<span class="cat-sub">' + plain.format(g.items.length) + ' بار</span></span>' +
                        '<div class="day-summary-text">' + parts.join('') + '</div>',
                        g.items.map(item => txRowHtml(item, { showDesc: false })).join('')));
                });
                container.appendChild(frag);
            }

            /* ---------- آمار و تاریخچه ---------- */
            function calcStats(list, info) {
                const s = { ti: 0, te: 0, mi: 0, me: 0, yi: 0, ye: 0 };
                list.forEach(t => {
                    const inc = t.type === 'income';
                    if (inc) s.ti += t.amount; else s.te += t.amount;
                    const j = jalaliOf(t.date);
                    if (j[0] === info.year) {
                        if (inc) s.yi += t.amount; else s.ye += t.amount;
                        if (j[0] + '-' + j[1] === info.monthKey) { if (inc) s.mi += t.amount; else s.me += t.amount; }
                    }
                });
                return s;
            }

            function renderHistorySection() {
                renderMonthSelect();
                const info = nowInfo();
                const list = visibleTx();
                const s = calcStats(list, info);

                $('total-balance').textContent = fmt(s.ti - s.te);
                $('year-income').textContent = '+' + fmt(s.yi);
                $('year-expense').textContent = '-' + fmt(s.ye);
                $('month-income').textContent = '+' + fmt(s.mi);
                $('month-expense').textContent = '-' + fmt(s.me);

                // تفکیک افراد در تب گزارش کل
                const pb = $('person-breakdown');
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

                let filteredTx = list;
                if (currentFilter !== 'all') filteredTx = filteredTx.filter(t => t.type === currentFilter);
                if (currentMonthFilter !== '') filteredTx = filteredTx.filter(t => monthKey(t.date) === currentMonthFilter);
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
                updateSumBar();
                renderEvFilter();
            }

            /* فقط بخشی که روی صفحه است ساخته می‌شود؛ ثبت در صفحهٔ «ثبت تراکنش» فوری است */
            function renderNow() {
                if (currentSection === 'history') renderHistorySection();
                else if (currentSection === 'inst') renderInstSection();
                renderNetBar();
            }
            let renderQueued = false;
            function updateUI() {
                if (renderQueued) return;
                renderQueued = true;
                requestAnimationFrame(() => { renderQueued = false; renderNow(); });
            }

            function renderHistory(groupedData) {
                const container = $('accordion-list');
                if(!container) return;

                container.innerHTML = '';
                const sortedDates = Object.keys(groupedData).sort((a, b) => b.localeCompare(a));
                if (sortedDates.length === 0) { container.innerHTML = EMPTY_HTML; return; }

                const frag = document.createDocumentFragment();
                sortedDates.forEach((date, idx) => {
                    const dayData = groupedData[date];
                    let summaryText = '';
                    if (currentFilter !== 'expense' && (dayData.income > 0 || currentFilter === 'income')) summaryText += `<span class="text-green">▲ ${fmt(dayData.income)}</span>`;
                    if (currentFilter !== 'income' && (dayData.expense > 0 || currentFilter === 'expense')) summaryText += `<span class="text-red">▼ ${fmt(dayData.expense)}</span>`;
                    frag.appendChild(makeDetails('d:' + date, isOpen('d:' + date, idx === 0),
                        '<span>' + dayTitle(date) + '<span class="cat-sub">' + plain.format(dayData.items.length) + ' مورد</span></span>' +
                        '<div class="day-summary-text">' + summaryText + '</div>',
                        dayData.items.map(item => txRowHtml(item)).join('')));
                });
                container.appendChild(frag);
            }

            /* ================= انتخاب چندتایی و جمع ================= */
            const sumBar = $('sum-bar');

            function selectedTx() {
                return transactions.filter(t => selectedIds.has(t.id));
            }

            function updateSumBar() {
                if (!sumBar || !selectMode) return;
                const list = selectedTx();
                let inc = 0, exp = 0;
                list.forEach(t => { if (t.type === 'income') inc += t.amount; else exp += t.amount; });
                const net = inc - exp;
                $('sum-count').textContent = plain.format(list.length) + ' مورد انتخاب شده';
                $('sum-net').innerHTML = '<b class="' + (net >= 0 ? 'text-green' : 'text-red') + '">' + (net < 0 ? '-' : '') + fmt(Math.abs(net)) + '</b> تومان';
                $('sum-detail').innerHTML =
                    '<span class="text-green">▲ درآمد: ' + fmt(inc) + '</span>' +
                    '<span class="text-red">▼ هزینه: ' + fmt(exp) + '</span>';
            }

            function setSelectMode(on) {
                selectMode = on;
                appRoot.classList.toggle('select-mode', on);
                if (!on) selectedIds.clear();
                $('select-mode-btn').textContent = on ? '✖️ پایان انتخاب' : '☑️ انتخاب چند مورد (جمع‌زدن / رویداد)';
                updateUI();
            }

            $('select-mode-btn').addEventListener('click', () => setSelectMode(!selectMode));

            $('sum-clear-btn').addEventListener('click', () => {
                selectedIds.clear();
                updateUI();
            });

            $('sum-all-btn').addEventListener('click', function() {
                const all = lastFilteredTx.every(t => selectedIds.has(t.id)) && lastFilteredTx.length > 0;
                if (all) selectedIds.clear();
                else lastFilteredTx.forEach(t => selectedIds.add(t.id));
                updateUI();
            });

            /* ================= گروه دوم (رویداد) ================= */
            const groupModal = $('group-modal');
            let pendingGroupIds = [];
            let pickedGroupName = '';

            function renderGroupChips() {
                const box = $('group-chips');
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
                        $('group-input').value = n;
                        renderGroupChips();
                    });
                    box.appendChild(c);
                });
            }

            function openGroupModal(ids) {
                if (!ids.length) { toast('اول چند مورد را تیک بزنید'); return; }
                pendingGroupIds = ids;
                pickedGroupName = groupOf(ids[0]) || '';
                $('group-input').value = pickedGroupName;
                $('group-modal-sub').textContent =
                    plain.format(ids.length) + ' مورد انتخاب شده‌اند. گروه دوم (مثل یک سفر یا پروژه) را انتخاب یا اضافه کنید.';
                renderGroupChips();
                groupModal.classList.add('active');
            }

            window.closeGroupModal = function() {
                pendingGroupIds = [];
                groupModal.classList.remove('active');
            };

            $('sum-group-btn').addEventListener('click', () => openGroupModal(Array.from(selectedIds)));

            window.setEvent = function(id) { openGroupModal([id]); };

            /* چیپ‌های فیلتر رویداد + راهنمای هر نما */
            const VIEW_HINTS = {
                date: 'هر روز یک کشو؛ روی هر ردیف بزنید تا ویرایش، رویداد یا حذف را ببینید.',
                cat: 'موارد هم‌عنوان کنار هم جمع می‌شوند (مثلاً همهٔ «سوپر»ها).',
                group: 'خرج‌هایی که برای یک ماجرا بوده‌اند کنار هم؛ از منوی هر ردیف یا با انتخاب چند مورد، آن‌ها را داخل یک رویداد بگذار.'
            };

            function renderEvFilter() {
                const box = $('ev-filter');
                const hint = $('view-hint');
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

            $('group-save-btn').addEventListener('click', function() {
                const name = $('group-input').value.trim();
                if (!name) { toast('نام رویداد را وارد کنید'); return; }
                setGroup(pendingGroupIds, name);
                window.closeGroupModal();
                toast('🧳 در «' + name + '» گذاشته شد');
                updateUI();
            });

            $('group-remove-btn').addEventListener('click', function() {
                setGroup(pendingGroupIds, '');
                window.closeGroupModal();
                updateUI();
            });

            $('group-input').addEventListener('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); $('group-save-btn').click(); }
            });

            function renderGroupView(list) {
                const container = $('accordion-list');
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
                if (keys.length === 0) { container.innerHTML = EMPTY_HTML; return; }

                const frag = document.createDocumentFragment();
                keys.forEach(k => {
                    const g = groups[k];
                    g.items.sort((a, b) => b.date.localeCompare(a.date) || b.id - a.id);
                    const net = g.income - g.expense;
                    const parts = [];
                    if (g.income > 0) parts.push('<span class="text-green">▲ ' + fmt(g.income) + '</span>');
                    if (g.expense > 0) parts.push('<span class="text-red">▼ ' + fmt(g.expense) + '</span>');
                    parts.push('<b class="' + (net >= 0 ? 'text-green' : 'text-red') + '">' + (net < 0 ? '-' : '') + fmt(Math.abs(net)) + '</b>');
                    frag.appendChild(makeDetails('g:' + k, isOpen('g:' + k, false),
                        '<span>' + (k.indexOf('—') === 0 ? esc(k) : '🧳 ' + esc(k)) +
                        '<span class="cat-sub">' + plain.format(g.items.length) + ' مورد</span></span>' +
                        '<div class="day-summary-text">' + parts.join('') + '</div>',
                        g.items.map(item => txRowHtml(item, { showDate: true, showGroup: false })).join('')));
                });
                container.appendChild(frag);
            }

            /* ================= کشویی‌ها: پاپ‌اور موبایل + بستن با کلیک بیرون ================= */
            const backdrop = $('sheet-backdrop');

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

            /* ================= راه‌اندازی: دادهٔ سرور + کش محلی + صف ================= */
            pendingOps = readQueue();
            (function boot() {
                const c = readCache();
                if (c) {
                    const seen = Number(c.seen) || 0;
                    cacheSeen = Math.max(SERVER_AT, seen);
                    // صفحه از کش سرویس‌ورکر آمده (قدیمی‌تر از داده‌ای که قبلاً دیده‌ایم) → دادهٔ محلی تازه‌تر است
                    if ((seen && SERVER_AT <= seen) || (!seen && transactions.length === 0 && c.tx.length)) transactions = c.tx;
                }
                transactions.forEach(t => { if (!t.uid) t.uid = ''; });

                const findTarget = op => (op.uid && transactions.find(t => t.uid === op.uid)) || transactions.find(t => t.id === op.id);
                pendingOps.forEach(op => {
                    if (!op.uid && op.id < 0) {
                        const t = transactions.find(x => x.id === op.id);
                        if (t && t.uid) op.uid = t.uid;
                    }
                    if (op.kind === 'create') {
                        const onServer = transactions.find(t => t.uid === op.uid && t.id > 0);
                        if (onServer && onServer.id !== op.id) remapId(op.id, onServer.id, '', op.uid); // قبلاً رسیده بود
                        let tx = findTarget(op);
                        if (!tx) {
                            tx = { id: op.id, uid: op.uid, date: op.date || ymd(new Date()) };
                            transactions.unshift(tx);
                        }
                        Object.assign(tx, fromP(op.p));
                        tx.uid = op.uid;
                    } else if (op.kind === 'update') {
                        const tx = findTarget(op);
                        if (tx) Object.assign(tx, fromP(op.p));
                    } else if (op.kind === 'delete') {
                        const tx = findTarget(op);
                        if (tx) transactions = transactions.filter(t => t !== tx);
                    }
                });
                const minId = transactions.reduce((m, t) => Math.min(m, t.id), 0);
                tmpCounter = Math.min(-1, minId - 1);
                refreshPendingIds();
                saveQueue();
            })();

            window.addEventListener('online', () => { lastSyncError = ''; flushQueue(); });
            window.addEventListener('offline', renderNetBar);
            // وقتی اپ بسته/پنهان می‌شود، باقی‌ماندهٔ صف با keepalive فرستاده و کش ذخیره می‌شود
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) { saveCacheNow(); flushQueue({ keepalive: true }); }
                else flushQueue();
            });
            window.addEventListener('pagehide', () => { saveCacheNow(); flushQueue({ keepalive: true }); });
            $('net-sync-btn').addEventListener('click', () => {
                if (authError) { saveCacheNow(); location.reload(); return; }
                lastSyncError = '';
                flushQueue({ force: true });
            });
            setInterval(() => flushQueue(), 20000);

            // سرویس‌ورکر: صفحه بدون اینترنت هم باز می‌شود
            if ('serviceWorker' in navigator) {
                const swUrl = location.pathname + '?finance_sw=1';
                navigator.serviceWorker.register(swUrl, { scope: location.pathname }).catch(() => {});
            }

            setTab('sarina');
            saveCacheNow();
            flushQueue();
        });
    </script>

    <?php
    return ob_get_clean();
}

} // پایان: if (function_exists('wp_finance_setup'))
