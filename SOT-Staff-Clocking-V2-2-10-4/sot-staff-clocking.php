<?php
/*
Plugin Name: S.O.T. Staff Clocking
Description: QR-based clock-in/out system for staff role.
Version: 2.9.1
Author: S.O.T. Group
*/

function sot_create_clocking_tables() {
    global $wpdb;
    if (function_exists('nocache_headers')) { nocache_headers(); }


    $table_name = $wpdb->prefix . 'sot_clocking';
    $token_table = $wpdb->prefix . 'sot_clock_tokens';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT NOT NULL,
        action ENUM('in','out') NOT NULL,
        timestamp DATETIME NOT NULL,
        note VARCHAR(255) DEFAULT NULL
    ) $charset_collate;";

    
    $holidays_table = $wpdb->prefix . 'sot_clock_holidays';
    $sql .= "CREATE TABLE IF NOT EXISTS $holidays_table (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT NOT NULL,
        holiday_date DATE NOT NULL,
        paid TINYINT(1) NOT NULL DEFAULT 1,
        note VARCHAR(255) DEFAULT NULL
    ) $charset_collate;";
$sql .= "CREATE TABLE IF NOT EXISTS $token_table (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT NOT NULL,
        token VARCHAR(64) NOT NULL,
        created DATETIME NOT NULL
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'sot_create_clocking_tables');

function sot_staff_menu() {
    $current_user = wp_get_current_user();
    if (in_array('staff', $current_user->roles) || current_user_can('manage_options')) {
        add_menu_page(
            'S.O.T. Staff',
            'S.O.T. Staff',
            'read',
            'sot-staff',
            'sot_staff_clocking_page',
            'dashicons-id-alt',
            3
        );
    }
}
add_action('admin_menu', 'sot_staff_menu');

function sot_get_or_create_token($user_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'sot_clock_tokens';

    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT token FROM $table WHERE user_id = %d ORDER BY id DESC LIMIT 1", $user_id
    ));

    if ($existing) return $existing;

    $token = bin2hex(random_bytes(16));
    $wpdb->insert(
        $table,
        [
            'user_id' => $user_id,
            'token' => $token,
            'created' => current_time('mysql')
        ]
    );
    return $token;
}

function sot_get_qr_url($user_id, $action, $token) {
    return add_query_arg([
        'sot_clock' => '1',
        'user' => $user_id,
        'action' => $action,
        'token' => $token
    ], site_url('/'));
}

function sot_display_user_clocking_table($user_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'sot_clocking';

    $selected_month = isset($_GET['sot_month']) ? sanitize_text_field($_GET['sot_month']) : date('Y-m');

    echo '<form method="get"><input type="hidden" name="page" value="sot-staff" />';
    echo '<label for="sot_month">Filter by Month:</label> ';
    echo '<input type="month" name="sot_month" id="sot_month" value="' . esc_attr($selected_month) . '" /> ';
    echo '<input type="submit" value="Filter" class="button" />';
    echo '</form><br>';

    $month_start = $selected_month . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    $entries = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_name WHERE user_id = %d AND timestamp BETWEEN %s AND %s ORDER BY timestamp DESC",
        $user_id, $month_start . ' 00:00:00', $month_end . ' 23:59:59'
    ));

    if (!$entries) {
        echo '<p>No clocking records found yet for selected month.</p>';
        return;
    }

    echo '<h2>Your Clocking Records</h2>';
    echo '<table class="widefat fixed striped">';
    echo '<thead><tr><th>Date</th><th>Time</th><th>Action</th><th>Note</th></tr></thead><tbody>';

    foreach ($entries as $entry) {
        $datetime = date_create($entry->timestamp);
        $date = $datetime->format('Y-m-d');
        $time = $datetime->format('H:i:s');
        $action = strtoupper($entry->action);
        $note = esc_html($entry->note);
        echo "<tr>";echo "<td>{$user_name}</td>";echo "<td>{$date}</td>";echo "<td>{$time}</td>";echo "<td>{$action}</td>";echo "<td>{$note}</td>";
echo "<td><a href='#' class='sot-edit-entry' data-id='{$entry->id}' data-date='{$date}' data-time='{$time}' data-action='{$action}' data-note='{$note}' data-user='{$user_name}'>Edit</a></td>";
echo "</tr>";
    }

    echo '</tbody></table>';
    
    echo '<div id="sotEditModal" style="display:none; position:fixed; top:20%; left:30%; background:#fff; border:1px solid #ccc; padding:20px; z-index:9999;">
        <h3>Edit Entry</h3>
        <form id="sotEditForm" method="post">
            <input type="hidden" name="sot_save_edit" value="1">
            <input type="hidden" name="entry_id" id="modal_entry_id">
            <label>Date:</label> <input type="date" name="modal_date" id="modal_date"><br><br>
            <label>Time:</label> <input type="time" name="modal_time" id="modal_time"><br><br>
            <label>Action:</label>
            <select name="modal_action" id="modal_action">
                <option value="IN">IN</option>
                <option value="OUT">OUT</option>
            </select><br><br>
            <label>Note:</label><br>
            <textarea name="modal_note" id="modal_note" rows="3" cols="40"></textarea><br><br>
            <button type="submit" class="button button-primary">Save</button>
            <button type="button" id="modal_cancel_btn" class="button">Cancel</button>
        </form>
    </div>';

    echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
            document.querySelectorAll(".sot-edit-entry").forEach(link => {
                link.addEventListener("click", function(e) {
                    e.preventDefault();
                    document.getElementById("modal_entry_id").value = this.dataset.id;
                    document.getElementById("modal_date").value = this.dataset.date;
                    document.getElementById("modal_time").value = this.dataset.time.substring(0,5);
                    document.getElementById("modal_action").value = this.dataset.action.toUpperCase();
                    document.getElementById("modal_note").value = this.dataset.note;
                    document.getElementById("sotEditModal").style.display = "block";
                });
            });
            document.getElementById("modal_cancel_btn").onclick = function() {
                document.getElementById("sotEditModal").style.display = "none";
            };
        });
    </script>';
    

}

function sot_admin_display_all_clocking_records() {
    if (!current_user_can('manage_options')) return;

    global $wpdb;
    $table_name = $wpdb->prefix . 'sot_clocking';

    $selected_user_id = isset($_GET['sot_user']) ? intval($_GET['sot_user']) : 0;
    $selected_month = isset($_GET['sot_month']) ? sanitize_text_field($_GET['sot_month']) : date('Y-m');

    
    if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["sot_save_edit"])) {
        global $wpdb;
        $table = $wpdb->prefix . "sot_clocking";

        $entry_id = intval($_POST["entry_id"]);
        $date = sanitize_text_field($_POST["modal_date"]);
        $time = sanitize_text_field($_POST["modal_time"]);
        $action = sanitize_text_field($_POST["modal_action"]);
        $note = sanitize_textarea_field($_POST["modal_note"]);
        $timestamp = "$date $time";

        $wpdb->update(
            $table,
            [ "timestamp" => $timestamp, "action" => $action, "note" => $note ],
            [ "id" => $entry_id ],
            [ "%s", "%s", "%s" ],
            [ "%d" ]
        );

        echo "<div class='notice notice-success is-dismissible'><p>Clocking entry updated successfully.</p></div>";
    }
echo '<h2>All Clocking Records</h2>';

    echo '<form method="get"><input type="hidden" name="page" value="sot-staff" />';
    echo '<label for="sot_user">Filter by Staff:</label> ';
    echo '<select name="sot_user" id="sot_user">';
    echo '<option value="0">-- All Staff --</option>';
    $staff_users = get_users(['role' => 'staff']);
    foreach ($staff_users as $user) {
        $selected = $selected_user_id == $user->ID ? 'selected' : '';
        echo "<option value='{$user->ID}' {$selected}>{$user->display_name}</option>";
    }
    echo '</select> ';

    echo '<label for="sot_month">Month:</label> ';
    echo '<input type="month" name="sot_month" id="sot_month" value="' . esc_attr($selected_month) . '" /> ';
    echo '<input type="submit" value="Filter" class="button button-primary" />';
    echo '</form><br>';

    $query = "SELECT c.*, u.display_name as user_name FROM $table_name c LEFT JOIN {$wpdb->users} u ON c.user_id = u.ID WHERE 1=1";
    $params = [];

    if ($selected_user_id > 0) {
        $query .= " AND c.user_id = %d";
        $params[] = $selected_user_id;
    }

    $month_start = $selected_month . '-01';
    $month_end = date('Y-m-t', strtotime($month_start));
    $query .= " AND c.timestamp BETWEEN %s AND %s";
    $params[] = $month_start . ' 00:00:00';
    $params[] = $month_end . ' 23:59:59';

    $query .= " ORDER BY c.timestamp DESC";
    $entries = $wpdb->get_results($wpdb->prepare($query, ...$params));

    if (!$entries) {
        echo '<p><strong>No records found for selected filters.</strong></p>';
        return;
    }

    echo '<table class="widefat fixed striped">';
    echo '<thead><tr><th>Staff Name</th><th>Date</th><th>Time</th><th>Action</th><th>Note</th></tr></thead><tbody>';

    foreach ($entries as $entry) {
        $datetime = date_create($entry->timestamp);
        $date = $datetime->format('Y-m-d');
        $time = $datetime->format('H:i:s');
        $action = strtoupper($entry->action);
        $note = esc_html($entry->note);
        $user_name = esc_html($entry->user_name);
        echo "<tr>";echo "<td>{$user_name}</td>";echo "<td>{$date}</td>";echo "<td>{$time}</td>";echo "<td>{$action}</td>";echo "<td>{$note}</td>";
echo "<td><a href='#' class='sot-edit-entry' data-id='{$entry->id}' data-date='{$date}' data-time='{$time}' data-action='{$action}' data-note='{$note}' data-user='{$user_name}'>Edit</a></td>";
echo "</tr>";
    }

    echo '</tbody></table>';
    
    echo '<div id="sotEditModal" style="display:none; position:fixed; top:20%; left:30%; background:#fff; border:1px solid #ccc; padding:20px; z-index:9999;">
        <h3>Edit Entry</h3>
        <form id="sotEditForm" method="post">
            <input type="hidden" name="sot_save_edit" value="1">
            <input type="hidden" name="entry_id" id="modal_entry_id">
            <label>Date:</label> <input type="date" name="modal_date" id="modal_date"><br><br>
            <label>Time:</label> <input type="time" name="modal_time" id="modal_time"><br><br>
            <label>Action:</label>
            <select name="modal_action" id="modal_action">
                <option value="IN">IN</option>
                <option value="OUT">OUT</option>
            </select><br><br>
            <label>Note:</label><br>
            <textarea name="modal_note" id="modal_note" rows="3" cols="40"></textarea><br><br>
            <button type="submit" class="button button-primary">Save</button>
            <button type="button" id="modal_cancel_btn" class="button">Cancel</button>
        </form>
    </div>';

    echo '<script>
        document.addEventListener("DOMContentLoaded", function() {
            document.querySelectorAll(".sot-edit-entry").forEach(link => {
                link.addEventListener("click", function(e) {
                    e.preventDefault();
                    document.getElementById("modal_entry_id").value = this.dataset.id;
                    document.getElementById("modal_date").value = this.dataset.date;
                    document.getElementById("modal_time").value = this.dataset.time.substring(0,5);
                    document.getElementById("modal_action").value = this.dataset.action.toUpperCase();
                    document.getElementById("modal_note").value = this.dataset.note;
                    document.getElementById("sotEditModal").style.display = "block";
                });
            });
            document.getElementById("modal_cancel_btn").onclick = function() {
                document.getElementById("sotEditModal").style.display = "none";
            };
        });
    </script>';
    

}


function sot_staff_clocking_page() {
    $current_user = wp_get_current_user();
    if (!in_array('staff', $current_user->roles) && !current_user_can('manage_options')) {
        wp_die('You do not have access to this page.');
    }

    $user_id = get_current_user_id();
    $name = esc_html($current_user->display_name);
    $token = sot_get_or_create_token($user_id);
    $clock_in_url = sot_get_qr_url($user_id, 'in', $token);
    $clock_out_url = sot_get_qr_url($user_id, 'out', $token);

    echo '<div class="wrap">';
    echo '<h1>S.O.T. Staff Clocking</h1>';
    echo '<p><strong>Clock In Link:</strong><br><input type="text" value="' . esc_url($clock_in_url) . '" readonly style="width: 100%;"></p>';
    echo '<p><strong>Clock Out Link:</strong><br><input type="text" value="' . esc_url($clock_out_url) . '" readonly style="width: 100%;"></p>';
    sot_display_user_clocking_table($user_id);

    if (current_user_can('manage_options')) {
        sot_admin_display_all_clocking_records();
    }

    echo '</div>';
}

function sot_handle_clock_qr() {
    if (!(isset($_REQUEST['sot_clock']) || isset($_POST['sot_clock_submit']) || isset($_GET['sot_clock']))) return;

    // Accept both GET (to show confirm) and POST (to submit)
    $user_id = isset($_REQUEST['user']) ? intval($_REQUEST['user']) : 0;
    $action  = isset($_REQUEST['do']) ? sanitize_text_field($_REQUEST['do']) : (isset($_REQUEST['action']) ? sanitize_text_field($_REQUEST['action']) : '');
    $token   = isset($_REQUEST['token']) ? sanitize_text_field($_REQUEST['token']) : '';

    if (!$user_id || !in_array($action, array('in','out'), true)) {
        wp_die('Invalid parameters.');
    }

    global $wpdb;
    $token_table    = $wpdb->prefix . 'sot_clock_tokens';
    $clocking_table = $wpdb->prefix . 'sot_clocking';

    // Validate token for user
    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM $token_table WHERE user_id = %d AND token = %s", $user_id, $token)
    );
    if (!$row) {
        wp_die('Invalid or expired QR token.');
    }

    // If POST: process submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sot_clock_submit'])) {
        // Nonce check
        $nonce_action = 'sot_clock_' . $user_id . '_' . $action;
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], $nonce_action)) {
            wp_die('Security check failed.');
        }

        // Duplicate guard: prevent duplicate entries for same user/action within 30 seconds
        $recent = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $clocking_table WHERE user_id = %d AND action = %s ORDER BY timestamp DESC LIMIT 1",
            $user_id, $action
        ));
        $now_ts = current_time('timestamp');
        if ($recent) {
            $recent_ts = strtotime($recent->timestamp);
            if (($now_ts - $recent_ts) < 30) {
                $msg = sprintf(
                    '<h2>Already recorded</h2><p>You already clocked %s at %s. Please wait before submitting again.</p>',
                    strtoupper(esc_html($action)),
                    esc_html(date_i18n('H:i:s', $recent_ts))
                );
                wp_die($msg);
            }
        }

        // Insert the record
        $wpdb->insert(
            $clocking_table,
            array(
                'user_id'   => $user_id,
                'action'    => ($action === 'in') ? 'in' : 'out',
                'timestamp' => current_time('mysql'),
                'note'      => null
            ),
            array('%d','%s','%s','%s')
        );

        $name = '';
        if ($u = get_userdata($user_id)) { $name = esc_html($u->display_name); }

        // Success page
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        echo '<title>Clock ' . strtoupper(esc_html($action)) . ' - Success</title>';
        echo '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;padding:24px;background:#f6f7f9}';
        echo '.card{max-width:520px;margin:40px auto;background:#fff;border-radius:14px;box-shadow:0 6px 24px rgba(0,0,0,.08);padding:28px;text-align:center}';
        echo 'h1{margin:0 0 10px;font-size:22px} p{margin:8px 0;color:#444} a.button{display:inline-block;margin-top:14px;padding:12px 18px;border-radius:10px;border:1px solid #ccc;text-decoration:none} </style>';
        echo '</head><body><div class="card">';
        echo '<h1>Clock ' . strtoupper(esc_html($action)) . ' recorded</h1>';
        echo '<p>' . ($name ? $name . ' — ' : '') . date_i18n('Y-m-d H:i:s', current_time('timestamp')) . '</p>';
        echo '<a class="button" href="' . esc_url(home_url('/')) . '">Close</a>';
        echo '</div></body></html>';
        exit;
    }

    // Else GET: show confirmation page with a button
    $user = get_userdata($user_id);
    $name = $user ? esc_html($user->display_name) : ('User #' . intval($user_id));
    $action_label = ($action === 'in') ? 'Clock In' : 'Clock Out';

    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . esc_html($action_label) . '</title>';
    echo '<meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">';
    echo '<meta http-equiv="Pragma" content="no-cache">';
    echo '<meta http-equiv="Expires" content="0">';
    echo '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;padding:24px;background:#f6f7f9}';
    echo '.card{max-width:520px;margin:40px auto;background:#fff;border-radius:14px;box-shadow:0 6px 24px rgba(0,0,0,.08);padding:28px;text-align:center}';
    echo 'h1{margin:0 0 10px;font-size:22px} p{margin:8px 0;color:#444} button{padding:14px 20px;font-size:18px;border-radius:12px;border:0;background:#111;color:#fff;cursor:pointer}';
    echo 'button[disabled]{opacity:.6;cursor:not-allowed} .name{font-weight:600}</style>';
    echo '</head><body><div class="card">';
    echo '<h1>' . esc_html($action_label) . '</h1>';
    echo '<p class="name">' . $name . '</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('sot_clock_' . $user_id . '_' . $action);
    echo '<input type="hidden" name="sot_clock_submit" value="1" />';
    echo '<input type="hidden" name="action" value="sot_clock_submit" />';
    echo '<input type="hidden" name="sot_clock" value="1" />';
    echo '<input type="hidden" name="user" value="' . intval($user_id) . '" />';
    echo '<input type="hidden" name="do" value="' . esc_attr($action) . '" />';
    echo '<input type="hidden" name="token" value="' . esc_attr($token) . '" />';
    echo '<button id="confirmBtn" type="submit">' . esc_html($action_label) . '</button>';
    echo '</form>';
    echo '<p>Tap once to confirm. Do not refresh this page.</p>';
    echo '<script>(function(){var b=document.getElementById("confirmBtn");if(!b)return;b.addEventListener("click",function(e){var f=b.form;b.disabled=true; if(f && typeof f.submit==="function"){f.submit();}});})();</script>';
    echo '</div></body></html>';
    exit;
}

add_action('init', 'sot_handle_clock_qr');

add_action('admin_footer', function () {
    if (isset($_GET['page']) && $_GET['page'] === 'sot-staff' && current_user_can('manage_options')) {
        echo "<script>
            document.querySelectorAll('.sot-editable-table td[contenteditable=true]').forEach(cell => {
                cell.addEventListener('blur', () => {
                    cell.style.backgroundColor = '#fff3cd';
                });
            });
        </script>";
    }
});


add_action('admin_menu', function () {
    if (current_user_can('manage_options')) {
        add_submenu_page('sot-staff', 'Add Manually', 'Add Manually', 'manage_options', 'sot-staff-manual-ui', 'sot_manual_ui_page');
    
add_submenu_page('sot-staff', 'Delete Entries', 'Delete Entries', 'manage_options', 'sot-staff-delete-ui', 'sot_staff_delete_ui_page');

    }
});



function sot_manual_ui_page() {
    global $wpdb;

    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    echo '<div class="wrap"><h1>Add Manually</h1>';

    if (
        isset($_POST['sot_manual_add']) &&
        check_admin_referer('sot_manual_add_entry')
    ) {
        $user_id = intval($_POST['manual_user_id']);
        $timestamp = sanitize_text_field($_POST['manual_timestamp']);
        $action = $_POST['manual_action'] === 'out' ? 'out' : 'in';
        $note = sanitize_text_field($_POST['manual_note']);

        if (!$timestamp) {
            $timestamp = current_time('mysql');
        }

        $result = $wpdb->insert($wpdb->prefix . "sot_clocking", [
            'user_id' => $user_id,
            'timestamp' => $timestamp,
            'action' => $action,
            'note' => $note
        ], [ '%d', '%s', '%s', '%s' ]);

        if ($result === false) {
            echo '<div class="notice notice-error"><p>Failed to add entry. Please check the input.</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>Entry added successfully.</p></div>';
        }
    }

    echo '<form method="POST">';
    wp_nonce_field('sot_manual_add_entry');

    echo '<table class="form-table">
        <tr><th>User</th><td><select name="manual_user_id">';
    $users = get_users(['role__in' => ['staff']]);
    foreach ($users as $user) {
        echo "<option value='{$user->ID}'>" . esc_html($user->display_name) . "</option>";
    }
    echo '</select></td></tr>
        <tr><th>Date & Time</th><td><input type="datetime-local" name="manual_timestamp" required></td></tr>
        <tr><th>Action</th><td><select name="manual_action">
            <option value="in">IN</option>
            <option value="out">OUT</option>
        </select></td></tr>
        <tr><th>Note</th><td><input type="text" name="manual_note" placeholder="Optional note" style="width:100%;"></td></tr>
    </table>
    <p><input type="submit" name="sot_manual_add" class="button button-primary" value="Add Entry"></p>
    </form></div>';
}



function sot_staff_delete_ui_page() {
    global $wpdb;

    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    if (isset($_GET['delete_id']) && check_admin_referer('sot_delete_entry_' . $_GET['delete_id'])) {
        $wpdb->delete($wpdb->prefix . "sot_clocking", ['id' => intval($_GET['delete_id'])]);
        echo '<div class="notice notice-success"><p>Entry deleted.</p></div>';
    }

    // Filter processing
    $user_filter = isset($_GET['filter_user']) ? intval($_GET['filter_user']) : '';
    $month_filter = isset($_GET['filter_month']) ? sanitize_text_field($_GET['filter_month']) : '';
    $month_sql = "";
    $params = [];

    if ($user_filter) {
        $user_sql = "user_id = %d";
        $params[] = $user_filter;
    } else {
        $user_sql = "1=1";
    }

    if ($month_filter) {
        [$year, $month] = explode("-", $month_filter);
        $month_sql = " AND MONTH(timestamp) = %d AND YEAR(timestamp) = %d";
        $params[] = intval($month);
        $params[] = intval($year);
    }

    $query = $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}sot_clocking WHERE $user_sql $month_sql ORDER BY timestamp DESC LIMIT 100",
        $params
    );

    $entries = $wpdb->get_results($query);

    echo '<div class="wrap"><h1>Delete Clocking Entries</h1>';
    echo '<form method="GET"><input type="hidden" name="page" value="sot-staff-delete-ui" />';

    // User filter
    echo '<label>User: <select name="filter_user"><option value="">All</option>';
    foreach (get_users(['role__in' => ['staff']]) as $user) {
        $selected = ($user->ID == $user_filter) ? "selected" : "";
        echo "<option value='{$user->ID}' $selected>" . esc_html($user->display_name) . "</option>";
    }
    echo '</select></label> ';

    // Month filter
    echo '<label>Month: <input type="month" name="filter_month" value="' . esc_attr($month_filter) . '" /></label> ';
    echo '<input type="submit" class="button button-primary" value="Filter" /></form><br>';

    echo '<table class="widefat fixed striped">';
    echo '<thead><tr>
        <th>User</th><th>Date</th><th>Time</th><th>Action</th><th>Note</th><th>Delete</th>
    </tr></thead><tbody>';

    foreach ($entries as $entry) {
        $user_info = get_userdata($entry->user_id);
        $username = $user_info ? esc_html($user_info->user_login) : 'Unknown';
        $datetime = date_create($entry->timestamp);
        $date = esc_html($datetime->format('Y-m-d'));
        $time = esc_html($datetime->format('H:i:s'));
        $action = esc_html(strtoupper($entry->action));
        $note = esc_html($entry->note);
        $nonce = wp_create_nonce('sot_delete_entry_' . $entry->id);
        $url = admin_url("admin.php?page=sot-staff-delete-ui&delete_id={$entry->id}&_wpnonce={$nonce}");
        $delete_link = "<a href='{$url}' class='button' style='color:white;background:#d63638;' onclick='return confirm(\'Delete this entry?\')'>Delete</a>";

        echo "<tr><td>{$username}</td><td>{$date}</td><td>{$time}</td><td>{$action}</td><td>{$note}</td><td>{$delete_link}</td></tr>";
    }

    echo '</tbody></table></div>';
}



add_action('admin_menu', function () {
    
    if (current_user_can('manage_options') || current_user_can('staff')) {
        add_submenu_page('sot-staff', 'Calendar', 'Calendar', 'read', 'sot-staff-calendar-ui', 'sot_staff_calendar_ui_page');
    }
});



function sot_staff_calendar_ui_page() {
    global $wpdb, $current_user;
    wp_get_current_user();

    $selected_month = isset($_GET['month']) ? sanitize_text_field($_GET['month']) : date('Y-m');
    $year = date('Y', strtotime($selected_month));
    $month = date('m', strtotime($selected_month));
    $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $start_date = "{$selected_month}-01 00:00:00";
    $end_date = "{$selected_month}-{$days_in_month} 23:59:59";

    $is_admin = current_user_can('manage_options');
    $user_clause = $is_admin ? "" : "AND user_id = {$current_user->ID}";

    $entries = $wpdb->get_results("
        SELECT user_id, action, timestamp
        FROM {$wpdb->prefix}sot_clocking
        WHERE timestamp BETWEEN '{$start_date}' AND '{$end_date}' {$user_clause}
        ORDER BY user_id, timestamp ASC
    ");

    $daily_totals = [];
    foreach ($entries as $entry) {
        $user_id = $entry->user_id;
        $ts = strtotime($entry->timestamp);
        $date = date('Y-m-d', $ts);
        $action = strtoupper($entry->action);

        if (!isset($daily_totals[$user_id][$date])) {
            $daily_totals[$user_id][$date] = [];
        }
        $daily_totals[$user_id][$date][] = ['action' => $action, 'ts' => $ts];
    }

    $user_hours = [];
    foreach ($daily_totals as $user_id => $dates) {
        foreach ($dates as $date => $records) {
            $stack = [];
            $total_sec = 0;
            foreach ($records as $r) {
                if ($r['action'] === 'IN') {
                    $stack[] = $r['ts'];
                } elseif ($r['action'] === 'OUT' && count($stack)) {
                    $in_ts = array_shift($stack);
                    if ($r['ts'] > $in_ts) {
                        $total_sec += ($r['ts'] - $in_ts);
                    }
                }
            }
            $user_hours[$user_id][$date] = $total_sec;
        }
    }

    echo '<div class="wrap"><h1>Calendar View</h1>';
    echo '<form method="GET">';
    echo '<input type="hidden" name="page" value="sot-staff-calendar-ui">';
    echo '<label for="month">Select Month: </label>';
    echo '<input type="month" id="month" name="month" value="' . esc_attr($selected_month) . '">';
    echo '<input type="submit" class="button" value="View">';
    echo '</form><br>';

    echo '<table class="widefat fixed striped"><thead><tr><th>Date</th>';
    $usernames = [];
    foreach ($user_hours as $uid => $_) {
        $name = get_userdata($uid)->display_name;
        $usernames[$uid] = $name;
        echo "<th>{$name}</th>";
    }
    echo '</tr></thead><tbody>';

    $monthly_total = [];

    for ($day = 1; $day <= $days_in_month; $day++) {
        $dstr = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $wd = date_i18n("D", strtotime($dstr));
        echo "<tr><td>{$dstr} ({$wd})</td>";
        foreach ($usernames as $uid => $name) {
            $secs = $user_hours[$uid][$dstr] ?? 0;
            $h = floor($secs / 3600);
            $m = floor(($secs % 3600) / 60);
            echo "<td>{$h}h {$m}min</td>";
            if (!isset($monthly_total[$uid])) $monthly_total[$uid] = 0;
            $monthly_total[$uid] += $secs;
        }
        echo '</tr>';
    }

    echo '<tr><th>Total</th>';
    foreach ($usernames as $uid => $name) {
        $total_secs = $monthly_total[$uid];
        $h = floor($total_secs / 3600);
        $m = floor(($total_secs % 3600) / 60);
        echo "<th>{$h}h {$m}min</th>";
    }
    echo '</tr></tbody></table></div>';
}


function sot_handle_clock_submit() {
    // Accept POST only
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        wp_die('Invalid request method.');
    }
    $user_id = isset($_POST['user']) ? intval($_POST['user']) : 0;
    $action  = isset($_POST['do']) ? sanitize_text_field($_POST['do']) : (isset($_POST['action']) ? sanitize_text_field($_POST['action']) : '');
    $token   = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';
    if (!$user_id || !in_array($action, array('in','out'), true)) {
        wp_die('Invalid parameters.');
    }

    if (function_exists('nocache_headers')) { nocache_headers(); }

    global $wpdb;
    $token_table    = $wpdb->prefix . 'sot_clock_tokens';
    $clocking_table = $wpdb->prefix . 'sot_clocking';

    // Validate token for user
    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM $token_table WHERE user_id = %d AND token = %s", $user_id, $token)
    );
    if (!$row) {
        wp_die('Invalid or expired QR token.');
    }

    // Nonce check
    $nonce_action = 'sot_clock_' . $user_id . '_' . $action;
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], $nonce_action)) {
        wp_die('Security check failed.');
    }

    // Duplicate guard within 30s
    $recent = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $clocking_table WHERE user_id = %d AND action = %s ORDER BY timestamp DESC LIMIT 1",
        $user_id, $action
    ));
    $now_ts = current_time('timestamp');
    if ($recent) {
        $recent_ts = strtotime($recent->timestamp);
        if (($now_ts - $recent_ts) < 30) {
            $msg = sprintf(
                '<h2>Already recorded</h2><p>You already clocked %s at %s. Please wait before submitting again.</p>',
                strtoupper(esc_html($action)),
                esc_html(date_i18n('H:i:s', $recent_ts))
            );
            wp_die($msg);
        }
    }

    // Insert record
    $ins = $wpdb->insert(
        $clocking_table,
        array(
            'user_id'   => $user_id,
            'action'    => ($action === 'in') ? 'in' : 'out',
            'timestamp' => current_time('mysql'),
            'note'      => null
        ),
        array('%d','%s','%s','%s')
    );

    if ($ins === false) {
        wp_die('Failed to save clocking entry.');
    }

    $name = '';
    if ($u = get_userdata($user_id)) { $name = esc_html($u->display_name); }

    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Clock ' . strtoupper(esc_html($action)) . ' - Success</title>';
    echo '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;padding:24px;background:#f6f7f9}';
    echo '.card{max-width:520px;margin:40px auto;background:#fff;border-radius:14px;box-shadow:0 6px 24px rgba(0,0,0,.08);padding:28px;text-align:center}';
    echo 'h1{margin:0 0 10px;font-size:22px} p{margin:8px 0;color:#444} a.button{display:inline-block;margin-top:14px;padding:12px 18px;border-radius:10px;border:1px solid #ccc;text-decoration:none} </style>';
    echo '</head><body><div class="card">';
    echo '<h1>Clock ' . strtoupper(esc_html($action)) . ' recorded</h1>';
    echo '<p>' . ($name ? $name . ' — ' : '') . date_i18n('Y-m-d H:i:s', current_time('timestamp')) . '</p>';
    echo '<a class="button" href="' . esc_url(home_url('/')) . '">Close</a>';
    echo '</div></body></html>';
    exit;
}

add_action('admin_post_nopriv_sot_clock_submit', 'sot_handle_clock_submit');
add_action('admin_post_sot_clock_submit', 'sot_handle_clock_submit');

/**
 * ===== Clocking Health Page =====
 * Detects anomalies by (user, date):
 * - More than 2 INs or 2 OUTs per day
 * - Mismatched pairs (IN != OUT)
 * Provides one-click fixes:
 * - Trim extras (keep earliest 2 INs & 2 OUTs)
 * - Add missing OUT at 23:59:00
 * - Add missing IN at 00:00:00
 */
add_action('admin_menu', function() {
    add_submenu_page(
        'sot-staff',
        'Clocking Health',
        'Clocking Health',
        'manage_options',
        'sot-clock-health',
        'sot_render_clocking_health_page'
    );
});

function sot_render_clocking_health_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    global $wpdb;
    $table = $wpdb->prefix . 'sot_clocking';

    // Filters: month and user
    $selected_month = isset($_GET['health_month']) ? sanitize_text_field($_GET['health_month']) : date('Y-m');
    $user_filter    = isset($_GET['health_user']) ? intval($_GET['health_user']) : 0;

    $month_start = $selected_month . '-01 00:00:00';
    $month_end   = date('Y-m-t 23:59:59', strtotime($selected_month . '-01'));

    // Build query
    $where = "timestamp BETWEEN %s AND %s";
    $args  = array($month_start, $month_end);
    if ($user_filter) {
        $where .= " AND user_id = %d";
        $args[] = $user_filter;
    }
    $sql = $wpdb->prepare("SELECT * FROM $table WHERE $where ORDER BY user_id, timestamp ASC", $args);
    $rows = $wpdb->get_results($sql);

    // Group by user + date
    $by_day = array();
    foreach ($rows as $r) {
        $d = substr($r->timestamp, 0, 10); // YYYY-MM-DD
        $key = $r->user_id . '|' . $d;
        if (!isset($by_day[$key])) $by_day[$key] = array('user_id'=>$r->user_id, 'date'=>$d, 'ins'=>array(), 'outs'=>array(), 'all'=>array());
        $by_day[$key]['all'][] = $r;
        if ($r->action === 'in')  $by_day[$key]['ins'][]  = $r;
        if ($r->action === 'out') $by_day[$key]['outs'][] = $r;
    }

    // Find anomalies
    $issues = array();
    foreach ($by_day as $k => $info) {
        $uid = $info['user_id'];
        $d   = $info['date'];
        $ins = count($info['ins']);
        $outs= count($info['outs']);
        $problem = array();

        if ($ins > 2)  $problem[] = "More than 2 INs ($ins)";
        if ($outs > 2) $problem[] = "More than 2 OUTs ($outs)";
        if ($ins != $outs) $problem[] = "Mismatched pairs (IN=$ins, OUT=$outs)";

        if (!empty($problem)) {
            $issues[] = array(
                'user_id'=>$uid,
                'date'=>$d,
                'ins'=>$ins,
                'outs'=>$outs,
                'problem'=>implode('; ', $problem)
            );
        }
    }

    // Users list for dropdown
    $users = sot_get_staff_users();
    $user_map = array();
    foreach ($users as $u) { $user_map[$u->ID] = $u->display_name; }

    echo '<div class="wrap"><h1>Clocking Health</h1>';
    echo '<form method="get" style="margin:10px 0 20px;">';
    echo '<input type="hidden" name="page" value="sot-clock-health" />';
    echo '<label>Month: <input type="month" name="health_month" value="'.esc_attr($selected_month).'" /></label> ';
    echo '<label>User: <select name="health_user"><option value="0">All</option>';
    foreach ($user_map as $id=>$name) {
        $sel = ($id === $user_filter) ? ' selected' : '';
        echo '<option value="'.intval($id).'"'.$sel.'>'.esc_html($name).'</option>';
    }
    echo '</select></label> ';
    echo '<button class="button button-primary">Filter</button>';
    echo '</form>';

    if (empty($issues)) {
        echo '<p>No issues found for the selected range.</p></div>';
        return;
    }

    // Bulk fix form
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    echo '<input type="hidden" name="action" value="sot_health_bulk_fix" />';
    wp_nonce_field('sot_health_bulk_fix');

    echo '<table class="widefat fixed striped"><thead><tr>';
    echo '<th><input type="checkbox" id="sot-select-all"></th>';
    echo '<th>User</th><th>Date</th><th>IN</th><th>OUT</th><th>Problem</th><th>Fix Options</th>';
    echo '</tr></thead><tbody>';

    foreach ($issues as $i => $iss) {
        $uid = $iss['user_id'];
        $d   = $iss['date'];
        $user_name = isset($user_map[$uid]) ? $user_map[$uid] : ('User #'.$uid);

        echo '<tr>';
        echo '<td><input type="checkbox" name="items[]" value="'.esc_attr($uid.'|'.$d).'"></td>';
        echo '<td>'.esc_html($user_name).'</td>';
        echo '<td>'.esc_html($d).'</td>';
        echo '<td>'.intval($iss['ins']).'</td>';
        echo '<td>'.intval($iss['outs']).'</td>';
        echo '<td>'.esc_html($iss['problem']).'</td>';
        echo '<td>';
        echo '<label><input type="radio" name="fix['.esc_attr($uid.'|'.$d).']" value="trim_extras" checked> Keep earliest 2 INs & 2 OUTs, delete extras</label><br>';
        echo '<label><input type="radio" name="fix['.esc_attr($uid.'|'.$d).']" value="add_missing_out"> Add missing OUT at 23:59:00</label><br>';
        echo '<label><input type="radio" name="fix['.esc_attr($uid.'|'.$d).']" value="add_missing_in"> Add missing IN at 00:00:00</label>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '<p><button class="button button-primary">Apply Fix to Selected</button></p>';
    echo '</form>';

    echo '<script>
    const allCb = document.getElementById("sot-select-all");
    if (allCb) {
        allCb.addEventListener("change", function(){
            document.querySelectorAll(\'input[name="items[]"]\').forEach(cb => cb.checked = allCb.checked);
        });
    }
    </script>';

    echo '</div>';
}

add_action('admin_post_sot_health_bulk_fix', 'sot_health_bulk_fix_handler');
function sot_health_bulk_fix_handler() {
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions.');
    }
    check_admin_referer('sot_health_bulk_fix');

    if (!isset($_POST['items']) || !is_array($_POST['items'])) {
        wp_redirect(add_query_arg(array('page'=>'sot-clock-health','updated'=>'0'), admin_url('admin.php')));
        exit;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'sot_clocking';

    foreach ($_POST['items'] as $val) {
        list($uid_str, $date) = array_pad(explode('|', $val), 2, '');
        $uid = intval($uid_str);
        $fix_type = isset($_POST['fix'][$val]) ? sanitize_text_field($_POST['fix'][$val]) : 'trim_extras';

        // Load all rows for that user+date
        $start = $date . ' 00:00:00';
        $end   = $date . ' 23:59:59';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE user_id=%d AND timestamp BETWEEN %s AND %s ORDER BY timestamp ASC",
            $uid, $start, $end
        ));

        if ($fix_type === 'trim_extras') {
            // Keep earliest 2 INs and earliest 2 OUTs; delete the rest
            $keep_ids = array();
            $in_count = 0;
            $out_count= 0;
            foreach ($rows as $r) {
                if ($r->action === 'in') {
                    if ($in_count < 2) { $keep_ids[] = $r->id; $in_count++; }
                } elseif ($r->action === 'out') {
                    if ($out_count < 2) { $keep_ids[] = $r->id; $out_count++; }
                }
            }
            if (!empty($keep_ids)) {
                $placeholders = implode(',', array_fill(0, count($keep_ids), '%d'));
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM $table WHERE user_id=%d AND timestamp BETWEEN %s AND %s AND id NOT IN ($placeholders)",
                    array_merge(array($uid, $start, $end), $keep_ids)
                ));
            }
        } elseif ($fix_type === 'add_missing_out') {
            $ft = sot_clock_get_fallback_times();
            // If there are more INs than OUTs, add OUT at 23:59
            $ins = 0; $outs=0;
            foreach ($rows as $r) { if ($r->action==='in') $ins++; if ($r->action==='out') $outs++; }
            if ($ins > $outs) {
                // Choose which OUT to add based on existing OUT count
                $out_time = ($outs == 0) ? $ft['morning_out'] : $ft['afternoon_out'];
                $wpdb->insert($table, array(
                    'user_id'=>$uid,
                    'action'=>'out',
                    'timestamp'=>$date . ' ' . $out_time . ':00',
                    'note'=>'Auto OUT (health fix)'
                ), array('%d','%s','%s','%s'));
            }
        } elseif ($fix_type === 'add_missing_in') {
            $ft = sot_clock_get_fallback_times();
            // If there are more OUTs than INs, add IN at 00:00
            $ins = 0; $outs=0;
            foreach ($rows as $r) { if ($r->action==='in') $ins++; if ($r->action==='out') $outs++; }
            if ($outs > $ins) {
                // Choose which IN to add based on existing IN count
                $in_time = ($ins == 0) ? $ft['morning_in'] : $ft['afternoon_in'];
                $wpdb->insert($table, array(
                    'user_id'=>$uid,
                    'action'=>'in',
                    'timestamp'=>$date . ' ' . $in_time . ':00',
                    'note'=>'Auto IN (health fix)'
                ), array('%d','%s','%s','%s'));
            }
        }
    }

    // Redirect back with success flag
    wp_redirect(add_query_arg(array('page'=>'sot-clock-health','updated'=>'1'), admin_url('admin.php')));
    exit;
}



/**
 * ===== Clocking Settings (Fallback Times) =====
 */
add_action('admin_menu', function() {
    add_submenu_page(
        'sot-staff',
        'Clocking Settings',
        'Clocking Settings',
        'manage_options',
        'sot-clock-settings',
        'sot_render_clock_settings_page'
    );
});

function sot_clock_get_fallback_times() {
    $defaults = array(
        'morning_in'     => '08:30',
        'morning_out'    => '12:00',
        'afternoon_in'   => '13:00',
        'afternoon_out'  => '18:00',
    );
    $opt = get_option('sot_clock_fallback_times', array());
    if (!is_array($opt)) { $opt = array(); }
    $times = array_merge($defaults, $opt);
    // basic validation HH:MM
    foreach ($times as $k => $v) {
        if (!preg_match('/^\\d{1,2}:\\d{2}$/', $v)) {
            $times[$k] = $defaults[$k];
        }
    }
    return $times;
}

function sot_clock_save_settings() {
    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions.');
    }
    check_admin_referer('sot_clock_settings');

    $keys = array('morning_in','morning_out','afternoon_in','afternoon_out');
    $save = array();
    foreach ($keys as $k) {
        $val = isset($_POST[$k]) ? sanitize_text_field($_POST[$k]) : '';
        if (!preg_match('/^\\d{1,2}:\\d{2}$/', $val)) {
            continue;
        }
        $save[$k] = $val;
    }
    if (!empty($save)) {
        update_option('sot_clock_fallback_times', $save);
    }
    wp_redirect(add_query_arg(array('page'=>'sot-clock-settings','updated'=>'1'), admin_url('admin.php')));
    exit;
}
add_action('admin_post_sot_clock_save_settings', 'sot_clock_save_settings');

function sot_render_clock_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    $times = sot_clock_get_fallback_times();
    echo '<div class="wrap"><h1>Clocking Settings</h1>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="max-width:520px;">';
    echo '<input type="hidden" name="action" value="sot_clock_save_settings">';
    wp_nonce_field('sot_clock_settings');
    echo '<table class="form-table"><tbody>';
    echo '<tr><th scope="row"><label for="morning_in">Morning In</label></th><td><input name="morning_in" id="morning_in" type="time" value="'.esc_attr($times['morning_in']).'" /></td></tr>';
    echo '<tr><th scope="row"><label for="morning_out">Morning Out</label></th><td><input name="morning_out" id="morning_out" type="time" value="'.esc_attr($times['morning_out']).'" /></td></tr>';
    echo '<tr><th scope="row"><label for="afternoon_in">Afternoon In</label></th><td><input name="afternoon_in" id="afternoon_in" type="time" value="'.esc_attr($times['afternoon_in']).'" /></td></tr>';
    echo '<tr><th scope="row"><label for="afternoon_out">Afternoon Out</label></th><td><input name="afternoon_out" id="afternoon_out" type="time" value="'.esc_attr($times['afternoon_out']).'" /></td></tr>';
    echo '</tbody></table>';
    echo '<p class="submit"><button type="submit" class="button button-primary">Save Changes</button></p>';
    echo '</form>';
    echo '<p><em>These times are used by the <strong>Clocking Health</strong> fixes when adding missing IN/OUT entries.</em></p>';
    echo '</div>';
}



/**
 * ===== Salary Settings (Standard Hours & Daily Salary per Staff) =====
 */
add_action('admin_menu', function() {
    add_submenu_page(
        'sot-staff',
        'Salary Settings',
        'Salary Settings',
        'manage_options',
        'sot-salary-settings',
        'sot_render_salary_settings_page'
    );
});

function sot_get_standard_hours_per_day() {
    $opt = get_option('sot_standard_hours_per_day', '8.0');
    if (!is_string($opt) && !is_numeric($opt)) $opt = '8.0';
    $val = floatval($opt);
    if ($val <= 0) $val = 8.0;
    return $val;
}

function sot_save_salary_settings() {
    if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
    check_admin_referer('sot_salary_settings');
    // Save rolling balance start date (YYYY-MM-DD)
    if (isset($_POST['balance_start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['balance_start_date'])) {
        update_option('sot_balance_start_date', sanitize_text_field($_POST['balance_start_date']));
    }


    
    // Save logo URL
    if (isset($_POST['salary_logo_url'])) { update_option('sot_salary_logo_url', esc_url_raw($_POST['salary_logo_url'])); }
    // Save working days
    $days = isset($_POST['working_days']) ? (array)$_POST['working_days'] : array();
    $days = array_values(array_intersect(array('1','2','3','4','5','6','7'), $days));
    update_option('sot_working_days', array_map('intval', $days));
if (isset($_POST['standard_hours'])) {
        $std = sanitize_text_field($_POST['standard_hours']);
        if (preg_match('/^\\d+(\\.\\d+)?$/', $std)) {
            update_option('sot_standard_hours_per_day', $std);
        }
    }

    if (isset($_POST['daily_salary']) && is_array($_POST['daily_salary'])) {
        foreach ($_POST['daily_salary'] as $uid => $val) {
            $uid = intval($uid);
            $num = floatval($val);
            update_user_meta($uid, 'sot_daily_salary', $num);
        }
    }

    
    // Save fixed salary flags
    $fs = isset($_POST['fixed_salary']) && is_array($_POST['fixed_salary']) ? $_POST['fixed_salary'] : array();
    foreach (sot_get_staff_users() as $u) {
        $flag = isset($fs[$u->ID]) ? '1' : '';
        update_user_meta($u->ID, 'sot_fixed_salary', $flag);
    }
    // Save fixed monthly salary amounts
    if (isset($_POST['fixed_salary_amount']) && is_array($_POST['fixed_salary_amount'])) {
        foreach ($_POST['fixed_salary_amount'] as $uid => $val) {
            $uid = intval($uid);
            $num = floatval($val);
            update_user_meta($uid, 'sot_fixed_salary_amount', $num);
        }
    }
    wp_redirect(add_query_arg(array('page'=>'sot-salary-settings','updated'=>'1'), admin_url('admin.php')));
    exit;
}
add_action('admin_post_sot_save_salary_settings', 'sot_save_salary_settings');

function sot_render_salary_settings_page() {
    if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
    $standard = sot_get_standard_hours_per_day();
    $users = sot_get_staff_users();

    echo '<div class="wrap"><h1>Salary Settings</h1>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    echo '<input type="hidden" name="action" value="sot_save_salary_settings">';
    wp_nonce_field('sot_salary_settings');
    // Rolling Balance Start Date field
$balance_start_date = get_option('sot_balance_start_date', '');
if (empty($balance_start_date)) {
    // Back-compat: if old month option exists, use its first day
    $oldm = get_option('sot_balance_start_month', '');
    if (!empty($oldm) && preg_match('/^\d{4}-\d{2}$/', $oldm)) {
        $balance_start_date = $oldm . '-01';
    } else {
        $balance_start_date = '2025-01-01';
    }
}
echo '<h2>Rolling Balance Start</h2>';
echo '<p><label>Start Date (all staff): <input type="date" name="balance_start_date" value="'.esc_attr($balance_start_date).'" /></label><br>';
echo '<small>Balances before this date are ignored (treated as 0). Default is Jan 1 of this year.</small></p>';


    echo '<h2>Standard Hours per Day</h2>';
    echo '<p><input type="number" step="0.25" min="0" name="standard_hours" value="'.esc_attr($standard).'" /> hours</p>';

    
    // Working Days UI
    $wd = sot_get_working_days();
    $labels = array(1=>'Mon',2=>'Tue',3=>'Wed',4=>'Thu',5=>'Fri',6=>'Sat',7=>'Sun');
    echo '<h2>Working Days</h2>';
    echo '<p>';
    foreach ($labels as $num=>$lab) {
        $checked = in_array($num, $wd) ? 'checked' : '';
        echo '<label style="margin-right:12px;"><input type="checkbox" name="working_days[]" value="'.$num.'" '.$checked.'> '.$lab.'</label>';
    }
    echo '</p>';

    // Company Logo URL
    $logo = get_option('sot_salary_logo_url', '');
    echo '<h2>Company Logo</h2>';
    echo '<p><label>Logo URL: <input type="url" name="salary_logo_url" value="'.esc_attr($logo).'" style="width:420px" placeholder="https://example.com/logo.png" /></label></p>';
echo '<h2>Daily Salary per Staff</h2>';
    echo '<table class="widefat fixed striped"><thead><tr><th>User</th><th>Daily Salary ($)</th><th>Fixed Salary?</th><th>Fixed Monthly Salary ($)</th></tr></thead><tbody>';
    foreach ($users as $u) {
        $rate = get_user_meta($u->ID, 'sot_daily_salary', true);
        if ($rate === '') $rate = '0';
        echo '<tr><td>'.esc_html($u->display_name).'</td>';
        echo '<td><input type="number" step="0.01" min="0" name="daily_salary['.intval($u->ID).']" value="'.esc_attr($rate).'" /></td>'; $fixed = get_user_meta($u->ID, 'sot_fixed_salary', true) ? 'checked' : ''; echo '<td><label><input type="checkbox" name="fixed_salary['.intval($u->ID).']" value="1" '.$fixed.'> Yes</label></td>';
 $fixed_amount = get_user_meta($u->ID, 'sot_fixed_salary_amount', true);
 if ($fixed_amount === '') { $fixed_amount = '0'; }
 echo '<td><input type="number" step="0.01" min="0" name="fixed_salary_amount['.intval($u->ID).']" value="'.esc_attr($fixed_amount).'" /></td></tr>';
    }
    echo '</tbody></table>';

    echo '<p class="submit"><button type="submit" class="button button-primary">Save Settings</button></p>';
    echo '</form></div>';
}



/**
 * ===== Salary Sheets =====
 * Build per-user monthly sheet with rolling overtime/undertime and printable view.
 */
add_action('admin_menu', function() {
    add_submenu_page(
        'sot-staff',
        'Salary Sheets',
        'Salary Sheets',
        'manage_options',
        'sot-salary-sheets',
        'sot_render_salary_sheets_page'
    );
});

function sot_get_daily_seconds_for_user($user_id, $start_date, $end_date) {
    global $wpdb;
    $table = $wpdb->prefix . 'sot_clocking';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT action, timestamp FROM $table WHERE user_id=%d AND timestamp BETWEEN %s AND %s ORDER BY timestamp ASC",
        $user_id, $start_date, $end_date
    ));

    $by_day = array();
    foreach ($rows as $r) {
        $d = substr($r->timestamp, 0, 10);
        if (!isset($by_day[$d])) $by_day[$d] = array();
        $by_day[$d][] = array('action'=>$r->action, 'ts'=>strtotime($r->timestamp));
    }

    $daily = array();
    foreach ($by_day as $d => $list) {
        $stack = array();
        $seconds = 0;
        foreach ($list as $rec) {
            if ($rec['action'] === 'in') {
                $stack[] = $rec['ts'];
            } elseif ($rec['action'] === 'out') {
                $in_ts = array_pop($stack);
                if ($in_ts) {
                    $seconds += max(0, $rec['ts'] - $in_ts);
                }
            }
        }
        // ignore unpaired INs; or could cap at day end if needed
        $daily[$d] = $seconds;
    }
    return $daily;
}

function sot_seconds_to_hm($secs) {
    $h = floor($secs / 3600);
    $m = floor(($secs % 3600) / 60);
    return sprintf('%dh %02dmin', $h, $m);
}

function sot_render_salary_sheets_page() {
    $working_days = sot_get_working_days();
    $is_working = function($date_str) use ($working_days) {
        $w = (int) date('N', strtotime($date_str));
        return in_array($w, $working_days);
    };
    if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
    $selected_month = isset($_GET['month']) ? sanitize_text_field($_GET['month']) : date('Y-m');
    $user_id = isset($_GET['user']) ? intval($_GET['user']) : 0;

    $users = sot_get_staff_users();

    echo '<div class="wrap"><h1>Salary Sheets</h1>';
    echo '<form method="GET" style="margin-bottom:12px;">';
    echo '<input type="hidden" name="page" value="sot-salary-sheets" />';
    echo '<label>Month: <input type="month" name="month" value="'.esc_attr($selected_month).'" /></label> ';
    echo '<label>User: <select name="user"><option value="0">Select User</option>';
    foreach ($users as $u) {
        $sel = ($user_id === $u->ID) ? ' selected' : '';
        echo '<option value="'.intval($u->ID).'"'.$sel.'>'.esc_html($u->display_name).'</option>';
    }
    echo '</select></label> ';
    echo '<button class="button button-primary">Build</button>';
    echo '</form>';

    if ($user_id <= 0) { echo '</div>'; return; }

    // Compute ranges
    $year = date('Y', strtotime($selected_month));
    $month = date('m', strtotime($selected_month));
    $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
    $month_start = $selected_month . '-01 00:00:00';
    $month_end   = $selected_month . '-' . $days_in_month . ' 23:59:59';

    // Standard hours
    $standard_hours = sot_get_standard_hours_per_day();
    $standard_secs  = intval(round($standard_hours * 3600));

    // Daily actuals for month
    $daily = sot_get_daily_seconds_for_user($user_id, $month_start, $month_end);
    $holidays = sot_get_holidays_for_user_month($user_id, $month_start, $month_end);
    $is_fixed = get_user_meta($user_id, 'sot_fixed_salary', true) ? true : false;
    $days = [];
    for ($d=1; $d <= $days_in_month; $d++) {
        $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $days[$date] = isset($daily[$date]) ? $daily[$date] : 0;
    }

    // Rolling balances: compute prior balance up to day before month
    global $wpdb;
    $first_ts = $wpdb->get_var($wpdb->prepare("SELECT MIN(timestamp) FROM {$wpdb->prefix}sot_clocking WHERE user_id=%d", $user_id));
$start_balance_secs = 0;
$prior_end = date('Y-m-d H:i:s', strtotime($month_start) - 1);
$anchor_date = get_option('sot_balance_start_date', '');
if (empty($anchor_date)) $anchor_date = '2025-01-01';
$anchor_ts = $anchor_date ? strtotime($anchor_date . ' 00:00:00') : null;
if ($first_ts) {
    $range_start_ts = strtotime($first_ts);
    if ($anchor_ts) { $range_start_ts = max($range_start_ts, $anchor_ts); }
    if ($range_start_ts <= strtotime($prior_end)) {
        $range_start = date('Y-m-d H:i:s', $range_start_ts);
        $prior_daily = sot_get_daily_seconds_for_user($user_id, $range_start, $prior_end);
        foreach ($prior_daily as $d => $secs) {
            $start_balance_secs += ($secs - $standard_secs);
        }
    }
}

    // Monthly sums
    $days_worked = 0;
    $overtime_secs = 0;
    $undertime_secs = 0;
    $month_delta_secs = 0;
    foreach ($days as $d => $secs) {
        $is_holiday = isset($holidays[$d]) ? ($holidays[$d] ? 'paid' : 'unpaid') : false;
        if ($is_holiday === 'paid') { $secs = $standard_secs; }
        if ($is_holiday === 'unpaid' && $is_fixed) { $std_day = $standard_secs; $secs = 0; }
        $std_day = $is_working($d) ? $standard_secs : 0;
        if ($secs > 0) $days_worked += 1; else if ($is_holiday === 'paid' && !$is_fixed) $days_worked += 1;
        $diff = $secs - $std_day;
        if ($diff > 0) $overtime_secs += $diff;
        if ($diff < 0) $undertime_secs += (-$diff);
        $month_delta_secs += $diff;
    }
    $end_balance_secs = $start_balance_secs + $month_delta_secs;

    // Salary calc
    $daily_salary = floatval(get_user_meta($user_id, 'sot_daily_salary', true));
    $fixed_amount = floatval(get_user_meta($user_id, 'sot_fixed_salary_amount', true));
    $total_pay = $is_fixed ? $fixed_amount : ($daily_salary * $days_worked);

    // Printable layout modeled on your example
    $user = get_userdata($user_id);
    $name = $user ? $user->display_name : ('User #'.$user_id);
    $month_label = date_i18n('F Y', strtotime($selected_month . '-01'));
    $period_label = date_i18n('j M', strtotime($selected_month . '-01')) . ' - ' . date_i18n('j M Y', strtotime($selected_month . '-' . $days_in_month));


    $logo = get_option('sot_salary_logo_url', '');
    $a4css = '<style>@page { size: A4; margin: 12mm; } @media print { html,body{height:auto} }</style>';
    echo $a4css;
    echo '<div id="salary-sheet" style="background:#fff; padding:24px; max-width:800px; box-shadow:0 4px 16px rgba(0,0,0,.06);">';
    if (!empty($logo)) {
        echo '<div style="text-align:left; margin-bottom:8px;"><img src="'.esc_url($logo).'" alt="Logo" style="max-height:64px; height:auto; width:auto;"></div>';
    }

    echo '<div style="text-align:right;">Dili, ' . esc_html(date_i18n('j. F Y')) . '</div>';
    echo '<h2 style="margin-top:16px; margin-bottom:16px; font-size:24px;">Salario Fulan ' . esc_html($month_label) . '</h2>';
    echo '<p style="margin:18px 0 18px 0;"><strong>Serbisu Nain:</strong> ' . esc_html($name) . '</p>';

    echo '<table style="width:100%; border-collapse:collapse; margin-top:20px;">';
    echo '<thead><tr>';
    echo '<th style="border-bottom:1px solid #ddd; text-align:left; padding:6px;">Periodo</th>';
    echo '<th style="border-bottom:1px solid #ddd; text-align:center; padding:6px;">Loron (Days)</th>';
    echo '<th style="border-bottom:1px solid #ddd; text-align:center; padding:6px;">Overtime (h)</th>';
    echo '<th style="border-bottom:1px solid #ddd; text-align:center; padding:6px;">Undertime (h)</th>';
    echo '<th style="border-bottom:1px solid #ddd; text-align:center; padding:6px;">$ / Day</th>';
    echo '<th style="border-bottom:1px solid #ddd; text-align:right; padding:6px;">Total ($)</th>';
    echo '</tr></thead><tbody>';
    echo '<tr>';
    echo '<td style="padding:6px;">' . esc_html($period_label) . '</td>';
    echo '<td style="padding:6px; text-align:center;">' . intval($days_worked) . '</td>';
    echo '<td style="padding:6px; text-align:center;">' . number_format($overtime_secs/3600, 2) . '</td>';
    echo '<td style="padding:6px; text-align:center;">' . number_format($undertime_secs/3600, 2) . '</td>';
    echo '<td style="padding:6px; text-align:center;">' . ($is_fixed ? '&mdash;' : number_format($daily_salary, 2)) . '</td>';
    echo '<td style="padding:6px; text-align:right;">' . number_format($total_pay, 2) . '</td>';
    echo '</tr></tbody></table>'; if ($is_fixed) { echo '<p><em>Note: Fixed salary — daily total not calculated; unpaid holidays reduce overtime balance.</em></p>'; }

    // Rolling balance
    echo '<p style="margin-top:24px;">';
    echo '<strong>Rolling balance:</strong> Start ' . number_format($start_balance_secs/3600,2) . ' h, ';
    echo 'Month ' . number_format($month_delta_secs/3600,2) . ' h, ';
    echo 'End ' . number_format($end_balance_secs/3600,2) . ' h.';
    echo '</p>';

    // Detail table per day
    echo '<details style="margin-top:24px;"><summary>Show daily breakdown</summary>';
    echo '<table style="width:100%; border-collapse:collapse; margin-top:8px;">';
    echo '<thead><tr><th style="text-align:left; border-bottom:1px solid #ddd; padding:6px;">Date</th><th style="text-align:center; border-bottom:1px solid #ddd; padding:6px;">Weekday</th><th style="text-align:center; border-bottom:1px solid #ddd; padding:6px;">Actual</th><th style="text-align:center; border-bottom:1px solid #ddd; padding:6px;">Standard</th><th style="text-align:center; border-bottom:1px solid #ddd; padding:6px;">Δ (h)</th></tr></thead><tbody>';
    foreach ($days as $d => $secs) {
        $is_holiday = isset($holidays[$d]) ? ($holidays[$d] ? 'paid' : 'unpaid') : false;
        if ($is_holiday === 'paid') { $secs = $standard_secs; }
        if ($is_holiday === 'unpaid' && $is_fixed) { $std_day = $standard_secs; $secs = 0; }
        $std_day = $is_working($d) ? $standard_secs : 0;
        $wd = date_i18n('D', strtotime($d));
        $std_for_day = $is_working($d) ? $standard_secs : 0;
        $delta_h = ($secs - $std_for_day) / 3600.0;
        echo '<tr>';
        echo '<td style="padding:6px;">' . esc_html(date_i18n('Y-m-d', strtotime($d))) . '</td>';
        echo '<td style="padding:6px; text-align:center;">' . esc_html($wd) . '</td>';
        $label = '';
        if (isset($holidays[$d])) { $label = $holidays[$d] ? ' (Paid Holiday)' : ' (Unpaid Holiday)'; }
        echo '<td style="padding:6px; text-align:center;">' . esc_html(sot_seconds_to_hm($secs)) . $label . '</td>';
        echo '<td style="padding:6px; text-align:center;">' . ($is_working($d) ? esc_html(number_format($standard_hours,2)).'h' : '<span style="color:#888">0h (off)</span>') . '</td>';
        echo '<td style="padding:6px; text-align:center;">' . esc_html(number_format($delta_h,2)) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></details>';

    // Signature line
    echo '<div style="margin-top:40px;">________________________<br>Asinatura Serbisu Nain.</div>';

    echo '<div style="margin-top:18px;">';
    echo '<button class="button" id="sot-print-sheet">Print / Save as PDF</button>';
    echo '</div>';

    echo '</div>'; // sheet container

    echo '<style>@media print { 
        body * { visibility: hidden !important; }
        #salary-sheet, #salary-sheet * { visibility: visible !important; }
        #salary-sheet { position: absolute; left: 0; top: 0; width: 100%; }
        #wpadminbar, #adminmenumain, #adminmenuwrap, #adminmenu, #wpfooter, .notice, .updated, .error { display: none !important; visibility: hidden !important; }
    }</style>';
    echo '<script>
    (function(){
        var btn = document.getElementById("sot-print-sheet");
        if (!btn) return;
        btn.addEventListener("click", function(e){
            e.preventDefault();
            // Open a clean window with only the sheet content (works around admin chrome)
            var sheet = document.getElementById("salary-sheet");
            if (!sheet) { window.print(); return; }
            var w = window.open("", "_blank", "width=960,height=700");
            if (!w) { window.print(); return; }
            var html = "<!DOCTYPE html><html><head><meta charset=\\"utf-8\\"><meta name=\\"viewport\\" content=\\"width=device-width, initial-scale=1\\">" +
                       "<title>Salary Sheet</title>" +
                       "<style>@media print{html,body{margin:0;padding:0} } body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; padding:24px;} </style>" +
                       "</head><body>" + sheet.outerHTML + "<script>window.onload=function(){setTimeout(function(){window.print();window.close();}, 200);};<\/script></body></html>";
            w.document.open();
            w.document.write(html);
            w.document.close();
        });
    })();
    </script>';
    echo '</div>'; // wrap
}



function sot_get_staff_users() {
    // Prefer users with role 'staff'
    $staff = get_users(array('role__in' => array('staff'), 'fields' => array('ID','display_name')));
    if (!empty($staff)) return $staff;
    // Fallback: all users (in case role doesn't exist yet)
    return get_users(array('fields' => array('ID','display_name')));
}



function sot_get_working_days() {
    // Store as array of ISO-8601 numeric weekdays: 1=Mon ... 7=Sun
    $opt = get_option('sot_working_days', array(1,2,3,4,5));
    if (!is_array($opt) || empty($opt)) $opt = array(1,2,3,4,5);
    // sanitize
    $opt = array_values(array_intersect(array(1,2,3,4,5,6,7), array_map('intval', $opt)));
    return $opt;
}



/**
 * ===== Holidays Admin =====
 */
add_action('admin_menu', function() {
    add_submenu_page(
        'sot-staff',
        'Holidays',
        'Holidays',
        'manage_options',
        'sot-holidays',
        'sot_render_holidays_page'
    );
});

function sot_render_holidays_page() {
    if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
    $users = sot_get_staff_users();
    $selected_month = isset($_GET['month']) ? sanitize_text_field($_GET['month']) : date('Y-m');
    $year = date('Y', strtotime($selected_month));
    $month = date('m', strtotime($selected_month));

    echo '<div class="wrap"><h1>Holidays</h1>';

    // Add form
    echo '<h2>Add Holiday</h2>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">';
    echo '<input type="hidden" name="action" value="sot_add_holiday" />';
    wp_nonce_field('sot_add_holiday');
    echo '<label>User<br><select name="user_id" required>';
    echo '<option value="">Select Staff</option>';
    foreach ($users as $u) {
        echo '<option value="'.intval($u->ID).'">'.esc_html($u->display_name).'</option>';
    }
    echo '</select></label>';
    echo '<label>Date<br><input type="date" name="date" required /></label>';
    echo '<label>Type<br><select name="paid"><option value="1">Paid</option><option value="0">Unpaid</option></select></label>';
    echo '<label>Note<br><input type="text" name="note" placeholder="(optional)"></label>';
    echo '<button class="button button-primary">Add</button>';
    echo '</form>';

    // List holidays for selected month
    echo '<hr><h2>Manage Holidays</h2>';
    echo '<form method="get" style="margin-bottom:12px;">';
    echo '<input type="hidden" name="page" value="sot-holidays" />';
    echo '<label>Month: <input type="month" name="month" value="'.esc_attr($selected_month).'" /></label> ';
    echo '<button class="button">Filter</button>';
    echo '</form>';

    global $wpdb;
    $holidays_table = $wpdb->prefix . 'sot_clock_holidays';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT h.*, u.display_name FROM $holidays_table h LEFT JOIN {$wpdb->users} u ON u.ID=h.user_id WHERE DATE_FORMAT(holiday_date,'%%Y-%%m')=%s ORDER BY holiday_date ASC, user_id ASC",
        $selected_month
    ));

    if (!$rows) {
        echo '<p>No holiday entries for this month.</p></div>';
        return;
    }

    echo '<table class="widefat fixed striped"><thead><tr><th>Date</th><th>User</th><th>Type</th><th>Note</th><th></th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $del_url = wp_nonce_url(add_query_arg(array('action'=>'sot_delete_holiday','id'=>$r->id), admin_url('admin-post.php')), 'sot_delete_holiday_'.$r->id);
        echo '<tr>';
        echo '<td>'.esc_html($r->holiday_date).'</td>';
        echo '<td>'.esc_html($r->display_name ?: ('User #'.$r->user_id)).'</td>';
        echo '<td>'.($r->paid ? 'Paid' : 'Unpaid').'</td>';
        echo '<td>'.esc_html($r->note).'</td>';
        echo '<td><a class="button button-small" href="'.esc_url($del_url).'">Delete</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

add_action('admin_post_sot_add_holiday', 'sot_add_holiday_handler');
function sot_add_holiday_handler() {
    if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
    check_admin_referer('sot_add_holiday');

    $uid  = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
    $paid = isset($_POST['paid']) ? intval($_POST['paid']) : 1;
    $note = isset($_POST['note']) ? sanitize_text_field($_POST['note']) : '';

    if (!$uid || !$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        wp_die('Invalid input.');
    }

    global $wpdb;
    $holidays_table = $wpdb->prefix . 'sot_clock_holidays';

    // Insert holiday record
    $wpdb->insert($holidays_table, array(
        'user_id' => $uid,
        'holiday_date' => $date,
        'paid' => $paid,
        'note' => $note ?: ($paid ? 'Paid Holiday' : 'Unpaid Holiday'),
    ), array('%d','%s','%d','%s'));

    // For PAID holiday, also create standard IN/OUT entries with note (visibility in calendar)
    if ($paid) {
        $ft = sot_clock_get_fallback_times();
        $pairs = array(
            array('in',  $ft['morning_in'] . ':00'),
            array('out', $ft['morning_out'] . ':00'),
            array('in',  $ft['afternoon_in'] . ':00'),
            array('out', $ft['afternoon_out'] . ':00'),
        );
        $clock = $wpdb->prefix . 'sot_clocking';
        foreach ($pairs as $p) {
            $wpdb->insert($clock, array(
                'user_id' => $uid,
                'action' => $p[0],
                'timestamp' => $date . ' ' . $p[1],
                'note' => 'Paid Holiday',
            ), array('%d','%s','%s','%s'));
        }
    }

    wp_redirect(add_query_arg(array('page'=>'sot-holidays','month'=>substr($date,0,7),'updated'=>'1'), admin_url('admin.php')));
    exit;
}

add_action('admin_post_sot_delete_holiday', 'sot_delete_holiday_handler');
function sot_delete_holiday_handler() {
    if (!current_user_can('manage_options')) wp_die('Insufficient permissions.');
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if (!$id || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'sot_delete_holiday_'.$id)) {
        wp_die('Invalid request.');
    }
    global $wpdb;
    $holidays_table = $wpdb->prefix . 'sot_clock_holidays';
    // Optionally, also delete Paid Holiday generated clock entries for that date
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $holidays_table WHERE id=%d", $id));
    if ($row && intval($row->paid) === 1) {
        $clock = $wpdb->prefix . 'sot_clocking';
        $wpdb->query($wpdb->prepare("DELETE FROM $clock WHERE user_id=%d AND DATE(timestamp)=%s AND note='Paid Holiday'", $row->user_id, $row->holiday_date));
    }
    $wpdb->delete($holidays_table, array('id'=>$id), array('%d'));
    wp_redirect(add_query_arg(array('page'=>'sot-holidays','updated'=>'1'), admin_url('admin.php')));
    exit;
}


function sot_get_holidays_for_user_month($user_id, $month_start, $month_end) {
    global $wpdb;
    $holidays_table = $wpdb->prefix . 'sot_clock_holidays';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT holiday_date, paid FROM $holidays_table WHERE user_id=%d AND holiday_date BETWEEN %s AND %s",
        $user_id, substr($month_start,0,10), substr($month_end,0,10)
    ));
    $map = array();
    foreach ($rows as $r) { $map[$r->holiday_date] = intval($r->paid); }
    return $map;
}
