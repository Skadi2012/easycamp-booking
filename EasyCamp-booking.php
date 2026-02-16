<?php
/**
 * Plugin Name: EasyCamp Booking
 * Version: 1.01
 * Description: Volle Funktionalitaet + iCal-Export.
 * Author: skadi12.
 */

if (!defined('ABSPATH')) exit;

// --- 1. INSTALLATION ---
register_activation_hook(__FILE__, 'wpec_full_install');
function wpec_full_install() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'easycamp_bookings';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
	    booking_nr varchar(20) DEFAULT '',
        name tinytext NOT NULL,
        email varchar(100) NOT NULL,
        phone varchar(50) NOT NULL,
        start_date date NOT NULL,
        end_date date NOT NULL,
        type varchar(20) NOT NULL,
        adults int DEFAULT 1,
        children int DEFAULT 0,
        pets int DEFAULT 0,
        comment text,
        status varchar(20) DEFAULT 'pending',
	    paid tinyint(1) DEFAULT 0,  
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    add_option('wpec_max_slots', 10);
    add_option('wpec_cancel_hours', 48);
    add_option('wpec_cancel_fee', 5.00);
    add_option('wpec_staff_pw', 'password'); 
    add_option('wpec_sender_name', 'Name-Campingplatz');
    add_option('wpec_sender_mail', get_option('admin_email'));
    add_option('wpec_headline_cal', 'Aktuelle Belegung');
    add_option('wpec_fkk_mode', '0');
    if (!get_option('wpec_ical_token')) {
        add_option('wpec_ical_token', wp_generate_password(12, false));
    }
}

// --- 2. LOGIK ---
add_action('init', 'wpec_handle_logic');
function wpec_handle_logic() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'easycamp_bookings';
    
    $s_name = get_option('wpec_sender_name', 'FSH-Hamburg e.V.');
    $s_mail_raw = get_option('wpec_sender_mail', get_option('admin_email'));
    // Falls mehrere Mails mit Komma getrennt sind, nutzen wir die erste für den From-Header
    $s_mails_array = array_map('trim', explode(',', $s_mail_raw));
    $s_mail = $s_mails_array[0];

    $headers = array('Content-Type: text/plain; charset=UTF-8', 'From: ' . $s_name . ' <' . $s_mail . '>');

    // iCal Export Logik
    if (isset($_GET['wpec_feed']) && $_GET['wpec_feed'] === get_option('wpec_ical_token')) {
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="easycamp.ics"');
        $bookings = $wpdb->get_results("SELECT * FROM $table_name WHERE status != 'cancelled'");
        echo "BEGIN:VCALENDAR\nVERSION:2.0\nPRODID:-//EasyCamp//DE\nMETHOD:PUBLISH\n";
        foreach ($bookings as $b) {
            $start = date('Ymd', strtotime($b->start_date));
            $end   = date('Ymd', strtotime($b->end_date . ' +1 day'));
            $paid_prefix = ($b->paid == 1) ? '€€ ' : '';
            $summary = $paid_prefix . "Buchung: " . $b->name;
            $desc = "Tel: {$b->phone}\\nE-Mail: {$b->email}\\nPersonen: {$b->adults} Erw, {$b->children} Kind\\n";
            $desc .= "Hunde: {$b->pets}\\nFahrzeug: {$b->type}\\nKommentar: " . str_replace(["\r", "\n"], " ", $b->comment);

            echo "BEGIN:VEVENT\nUID:wpec-{$b->id}\nDTSTART;VALUE=DATE:{$start}\nDTEND;VALUE=DATE:{$end}\nSUMMARY:{$summary}\nDESCRIPTION:{$desc}\nEND:VEVENT\n";
        }       
        echo "END:VCALENDAR"; exit;
    }

    // Logout
    if (isset($_GET['wpec_logout'])) {
        setcookie('wpec_staff_auth', '', time() - 3600, "/");
        wp_redirect(remove_query_arg('wpec_logout')); exit;
    }

    // Login
    if (isset($_POST['wpec_login'])) {
        if ($_POST['staff_pw'] === get_option('wpec_staff_pw')) {
            setcookie('wpec_staff_auth', md5(get_option('wpec_staff_pw')), time() + 3600, "/");
            wp_redirect($_SERVER['REQUEST_URI']); exit;
        }
    }

    // --- FRONTEND BUCHUNG MIT VALIDIERUNG ---
    if (isset($_POST['wpec_submit'])) {
        $start = sanitize_text_field($_POST['start_date']);
        $end   = sanitize_text_field($_POST['end_date']);
        $today = date('Y-m-d');
        $max_slots = get_option('wpec_max_slots', 10);

        // A. Validierung: Zeitliche Logik & Vergangenheit
        if (strtotime($start) < strtotime($today)) {
            wp_redirect(add_query_arg('wpec_status', 'error_past', $_SERVER['REQUEST_URI'])); exit;
        }
        if (strtotime($end) <= strtotime($start)) {
            wp_redirect(add_query_arg('wpec_status', 'error_date', $_SERVER['REQUEST_URI'])); exit;
        }

        // B. Validierung: Überbuchung (Tag für Tag Prüfung)
        $check_date = $start;
        while (strtotime($check_date) < strtotime($end)) {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name 
                 WHERE status != 'cancelled' 
                 AND %s BETWEEN start_date AND DATE_SUB(end_date, INTERVAL 1 DAY)", 
                $check_date
            ));

            if ($count >= $max_slots) {
                wp_redirect(add_query_arg('wpec_status', 'error_full', $_SERVER['REQUEST_URI'])); exit;
            }
            $check_date = date('Y-m-d', strtotime($check_date . ' +1 day'));
        }

        // C. Buchungsnummer & Speichern
        $name     = sanitize_text_field($_POST['name']);
        $email    = sanitize_email($_POST['email']);
        $next_id  = $wpdb->get_var("SELECT MAX(id) FROM $table_name") + 1;
        $b_nr     = "EC-" . date('Y') . "-" . str_pad($next_id, 4, '0', STR_PAD_LEFT);

        $wpdb->insert($table_name, [
            'booking_nr' => $b_nr,
            'name' => $name, 'email' => $email, 'phone' => sanitize_text_field($_POST['phone']),
            'start_date' => $start, 'end_date' => $end,
            'type' => sanitize_text_field($_POST['vehicle']), 
            'adults' => intval($_POST['adults']), 'children' => intval($_POST['children']),
            'pets' => intval($_POST['pets']), 'comment' => sanitize_textarea_field($_POST['comment']), 
            'status' => 'pending'
        ]);

        // D. E-Mail Versand
        $admin_headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $s_name . ' <' . $s_mail . '>',
            'Reply-To: ' . $name . ' <' . $email . '>'
        );

        $admin_body = "Neue Buchungsanfrage [$b_nr] von: $name\n\n";
        $admin_body .= "Zeitraum: " . date('d.m.Y', strtotime($start)) . " bis " . date('d.m.Y', strtotime($end)) . "\n";
        $admin_body .= "Personen: " . intval($_POST['adults']) . " Erw., " . intval($_POST['children']) . " Kind.\n";
        $admin_body .= "Hunde: " . (intval($_POST['pets']) > 0 ? $_POST['pets'] : "Keine") . "\n";
        $admin_body .= "Fahrzeug: " . sanitize_text_field($_POST['vehicle']) . "\n";
        $admin_body .= "Anmerkung: " . sanitize_textarea_field($_POST['comment']);

        // An alle Admin-Adressen senden
        foreach($s_mails_array as $m) {
            wp_mail($m, "Neue Anfrage $b_nr: " . $name, $admin_body, $admin_headers);
        }

        $guest_body = "Vielen Dank fuer Ihre Anfrage ($b_nr) bei $s_name.\n\n";
        $guest_body .= "Gewuenschter Zeitraum: " . date('d.m.Y', strtotime($start)) . " bis " . date('d.m.Y', strtotime($end)) . "\n";
        $guest_body .= "Wir pruefen Ihre Anfrage und melden uns schnellstmoeglich.";

        wp_mail($email, "Ihre Anfrage $b_nr bei $s_name", $guest_body, $headers);             
        wp_redirect(add_query_arg('wpec_status', 'success', $_SERVER['REQUEST_URI'])); exit;
    }

    // --- STAFF ACTIONS ---
    if (isset($_COOKIE['wpec_staff_auth']) && $_COOKIE['wpec_staff_auth'] === md5(get_option('wpec_staff_pw'))) {
        
        // Manueller Eintrag durch Staff
        if (isset($_POST['wpec_staff_add'])) {
            $next_id  = $wpdb->get_var("SELECT MAX(id) FROM $table_name") + 1;
            $b_nr     = "M-" . date('Y') . "-" . str_pad($next_id, 4, '0', STR_PAD_LEFT);
            
            $s_name_input = sanitize_text_field($_POST['s_name']);
            $s_email_input = sanitize_email($_POST['s_email']);
            $s_start = sanitize_text_field($_POST['s_start']);
            $s_end = sanitize_text_field($_POST['s_end']);

            $wpdb->insert($table_name, [
                'booking_nr' => $b_nr,
                'name'       => $s_name_input . ' (Manuell)',
                'email'      => $s_email_input,
                'phone'      => sanitize_text_field($_POST['s_phone']),
                'start_date' => $s_start,
                'end_date'   => $s_end,
                'type'       => 'Manuell', 
                'status'     => 'confirmed'
            ]);

            if (!empty($s_email_input)) {
                $confirm_body = "Ihre telefonische Buchung ($b_nr) wurde erfolgreich eingetragen.\n";
                $confirm_body .= "Zeitraum: " . date('d.m.Y', strtotime($s_start)) . " bis " . date('d.m.Y', strtotime($s_end));
                wp_mail($s_email_input, "Buchungsbestaetigung $b_nr - $s_name", $confirm_body, $headers);
            }
            wp_redirect($_SERVER['REQUEST_URI']); exit;
        }

        // Aktionen (Bestätigen, Bezahlt, Storno)
        if (isset($_GET['wpec_act']) && isset($_GET['bid'])) {
            $id = intval($_GET['bid']);
            $b = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id));
            if (!$b) return;

            $info_text = "\n\nDetails zu Ihrer Buchung ($b->booking_nr):\n";
            $info_text .= "Zeitraum: " . date('d.m.Y', strtotime($b->start_date)) . " bis " . date('d.m.Y', strtotime($b->end_date)) . "\n";
            $info_text .= "Personen: $b->adults Erw., $b->children Kind.\n";
            $info_text .= "Hunde: " . ($b->pets > 0 ? $b->pets : "Keine") . "\n";
            $info_text .= "Fahrzeug: $b->type";

            if ($_GET['wpec_act'] == 'mark_paid') {
                $wpdb->update($table_name, ['paid' => 1], ['id' => $id]);
            }
            if ($_GET['wpec_act'] == 'confirm') {
                $wpdb->update($table_name, ['status' => 'confirmed'], ['id' => $id]);
                if (get_option('wpec_auto_confirm') == '1') {
                    $msg = "Hallo " . $b->name . ",\ndeine Buchung " . $b->booking_nr . " ist bestätigt!" . $info_text;
                    wp_mail($b->email, "Bestätigung", $msg, $headers);
                }
            }
            if ($_GET['wpec_act'] == 'delete') {
                $wpdb->update($table_name, ['status' => 'cancelled'], ['id' => $id]);
                wp_mail($b->email, "Stornierung Ihrer Buchung $b->booking_nr - $s_name", "Ihre Buchung wurde erfolgreich storniert." . $info_text, $headers);
            }
            wp_redirect(remove_query_arg(array('wpec_act', 'bid'))); exit;
        }
    }
}

// --- 3. FRONTEND ---
add_shortcode('easycamp_form', 'wpec_frontend_shortcode');
function wpec_frontend_shortcode() {
    $s_name = get_option('wpec_sender_name', 'FSH-Hamburg e.V.');
    $h_cal = get_option('wpec_headline_cal', 'Aktuelle Belegung');
    $fkk_mode = get_option('wpec_fkk_mode', '1');
    $output = '<div class="wpec-container" style="font-family:sans-serif; max-width:600px; margin:auto;">';
    
    // --- STATUS MELDUNGEN (Erfolg & Fehler) ---
    if (isset($_GET['wpec_status'])) {
        if ($_GET['wpec_status'] == 'success') {
            $output .= "<div style='background:#d4edda; color:#155724; padding:15px; margin-bottom:20px; border-radius:5px;'>✔ Anfrage an $s_name gesendet!</div>";
        }
        if ($_GET['wpec_status'] == 'error_past') {
            $output .= "<div style='background:#f8d7da; color:#721c24; padding:15px; margin-bottom:20px; border-radius:5px;'>Fehler: Buchungen in der Vergangenheit sind nicht möglich.</div>";
        }
        if ($_GET['wpec_status'] == 'error_full') {
            $output .= "<div style='background:#f8d7da; color:#721c24; padding:15px; margin-bottom:20px; border-radius:5px;'>Fehler: In diesem Zeitraum sind leider keine Plätze mehr frei. <br> Aber ruf doch mal, wir machen es möglich.</div>";
        }
        if ($_GET['wpec_status'] == 'error_date') {
            $output .= "<div style='background:#f8d7da; color:#721c24; padding:15px; margin-bottom:20px; border-radius:5px;'>Fehler: Das Abreisedatum muss nach dem Anreisedatum liegen.</div>";
        }
    }

    $h_cal = get_option('wpec_headline_cal', 'Aktuelle Belegung');
    $output .= '<h3>' . esc_html($h_cal) . '</h3>';
    $output .= '<div id="wpec-calendar-container">' . wpec_get_calendar_view() . '</div>';
    
    $output .= '<form method="post" style="background:#f9f9f9; padding:20px; border-radius:10px; border:1px solid #ddd;">';
    $output .= '<input type="text" name="name" placeholder="Name" required style="width:100%; margin-bottom:10px; padding:10px; box-sizing:border-box;">';
    $output .= '<div style="display:flex; gap:10px; margin-bottom:10px;"><input type="email" name="email" placeholder="E-Mail" required style="flex:1; padding:10px;"><input type="text" name="phone" placeholder="Telefonnummer" required style="flex:1; padding:10px;"></div>';
    
    $output .= '<div style="display:flex; flex-wrap:wrap; gap:10px; margin-bottom:15px;">
    <label style="flex: 1 1 calc(50% - 5px); min-width:140px; font-size:13px; color:#555;">
        Anreise:<br>
        <input type="date" name="start_date" min="'.date('Y-m-d').'" required style="width:100%; padding:10px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;">
    </label>
    <label style="flex: 1 1 calc(50% - 5px); min-width:140px; font-size:13px; color:#555;">
        Abreise:<br>
        <input type="date" name="end_date" required style="width:100%; padding:10px; border:1px solid #ccc; border-radius:4px; box-sizing:border-box;">
    </label>
</div>';

    $output .= '<div style="display:flex; gap:10px; margin-bottom:10px;">
        <div style="flex:1;"><small>Erwachsene</small><br><input type="number" name="adults" value="1" min="1" style="width:100%; padding:8px;"></div>
        <div style="flex:1;"><small>Kinder</small><br><input type="number" name="children" value="0" min="0" style="width:100%; padding:8px;"></div>
        <div style="flex:1;"><small>Hunde</small><br>
            <select name="pets" style="width:100%; padding:8px;" onchange="document.getElementById(\'pet_warn\').style.display=(this.value>0?\'block\':\'none\'); document.getElementById(\'pet_confirm\').required=(this.value>0);">
                <option value="0">Keine</option><option value="1">1 Hund</option><option value="2">2 Hunde</option>
            </select>
        </div>
    </div>';

    $output .= '<div id="pet_warn" style="display:none; margin-bottom:15px; font-size:13px; color:#c0392b; background:#fdedec; padding:10px; border-left:4px solid #e74c3c;">
        Hunde sind im Naturschutzgebiet zwingend an der Leine zu fuehren!<br>
        <label><input type="checkbox" name="pet_confirm" id="pet_confirm"> Ich akzeptiere die Leinenpflicht</label>
    </div>';
    
    $c_fee = floatval(get_option('wpec_cancel_fee', 0));
    $output .= '<select name="vehicle" style="width:100%; margin-bottom:10px; padding:10px;"><option>Wohnmobil</option><option>Wohnwagen</option><option>Zelt</option></select>';
    $output .= '<textarea name="comment" placeholder="Anmerkungen..." style="width:100%; padding:10px; margin-bottom:10px;"></textarea>';
    
    $output .= '<div style="background:#fff; border:1px solid #ddd; padding:10px; font-size:12px; margin-bottom:10px;"><strong>Storno:</strong> Nur telefonisch moeglich. ';
    if($c_fee > 0) $output .= 'Ab '.get_option('wpec_cancel_hours').'h vor Anreise faellt eine Gebuehr von '.number_format($c_fee,2,',','.').'&euro; an.';
    $output .= '</div>';

    $output .= '<div style="font-size:13px; color:#555; margin-bottom:15px; border-top:1px solid #ddd; padding-top:10px;">';
    if ($fkk_mode === '1') {
        $output .= '<label style="display:block; margin-bottom:5px;"><input type="checkbox" required> Ich weiss, dass Sie ein FKK-Verein sind.</label>';
    }

    $output .= '<label style="display:block;"><input type="checkbox" required> Ich stimme der <a href="/datenschutz" target="_blank">Datenschutzverordnung</a> zu.</label>';
    $output .= '</div>';
    
    $output .= '<button type="submit" name="wpec_submit" style="width:100%; padding:15px; background:#2c3e50; color:white; border:none; font-weight:bold; cursor:pointer;">UNVERBINDLICH ANFRAGEN</button></form>';

    $output .= '<div style="margin-top:30px;">';
    if (isset($_COOKIE['wpec_staff_auth']) && $_COOKIE['wpec_staff_auth'] === md5(get_option('wpec_staff_pw'))) {
        $output .= '<div style="display:flex; justify-content:space-between;"><h4>Betreuer-Ansicht</h4><a href="?wpec_logout=1" style="color:red; text-decoration:none;">[Logout]</a></div>' . wpec_staff_list();
    } else {
        $output .= '<form method="post" style="font-size:11px;">Betreuer-Login: <input type="password" name="staff_pw" style="width:70px;"> <button name="wpec_login">OK</button></form>';
    }
    $output .= '</div></div>';
    return $output;
}
// --- 4. STAFF LISTE ---
function wpec_staff_list() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'easycamp_bookings';
    $s_name = get_option('wpec_sender_name', 'Name-Campingplatz');
    $s_mail = get_option('wpec_sender_mail', get_option('admin_email'));
    $headers = array('Content-Type: text/plain; charset=UTF-8', 'From: ' . $s_name . ' <' . $s_mail . '>');

    // Daten abrufen: Getrennt nach Aktiv und Storniert
    $active_bookings = $wpdb->get_results("SELECT * FROM $table_name WHERE status != 'cancelled' ORDER BY start_date ASC");
    $cancelled_bookings = $wpdb->get_results("SELECT * FROM $table_name WHERE status = 'cancelled' ORDER BY created_at DESC LIMIT 15");

    // Formular f�r manuelle Buchungen
    $html = '<div style="background:#f0f0f0; padding:15px; border-radius:5px; margin-bottom:20px; border:1px solid #ccc;">
        <h5 style="margin:0 0 10px 0;">Neue manuelle Buchung (Telefon)</h5>
        <form method="post" style="display:flex; flex-wrap:wrap; gap:8px;">
            <input type="text" name="s_name" placeholder="Name" required style="flex:1; min-width:120px; padding:5px;">
            <input type="text" name="s_phone" placeholder="Telefon" style="flex:1; min-width:120px; padding:5px;">
            <input type="email" name="s_email" placeholder="E-Mail" style="flex:1; min-width:120px; padding:5px;">
            <input type="date" name="s_start" required style="flex:1; padding:5px;">
            <input type="date" name="s_end" required style="flex:1; padding:5px;">
            <button type="submit" name="wpec_staff_add" style="background:#27ae60; color:white; border:none; padding:5px 15px; cursor:pointer; border-radius:3px;">Speichern</button>
        </form></div>';

    // Tabelle der aktiven Buchungen
    $html .= '<table style="width:100%; font-size:11px; border-collapse:collapse;">';
    $html .= '<tr style="background:#eee;"><th>Nr / Gast</th><th>Datum</th><th>Status</th><th>Aktion</th></tr>';
    
    if ($active_bookings) {
        foreach ($active_bookings as $b) {
            $status_label = ($b->status === 'confirmed') 
                ? '<span style="color:#27ae60; font-weight:bold;">Bestätigt</span>' 
                : '<span style="color:#e67e22; font-weight:bold;">Offen</span>';

        if ($b->paid == 1) {
                $status_label .= '<br><span style="color:#2980b9; font-size:9px;">[BEZAHLT]</span>';
        }

            $html .= "<tr style='border-bottom:1px solid #ddd;'>
                <td><strong>$b->booking_nr</strong><br>
            $b->name<br>
            <small><a href='mailto:$b->email'>$b->email</a></small><br>
            <small>$b->phone</small></td>
                <td>" . date('d.m.', strtotime($b->start_date)) . " - " . date('d.m.', strtotime($b->end_date)) . "</td>
                <td>$status_label</td>
                <td>";
            
            // Wenn noch nicht bestätigt: Zeige OK-Link UND Mail-Link
            if ($b->status !== 'confirmed') {
                // 1. Intern im System auf "Bestätigt" setzen
                $html .= "<a href='?wpec_act=confirm&bid=$b->id' style='color:green; text-decoration:none;'>[OK]</a> | ";
                
                // 2. Mail-Programm öffnen für persönlichen Text
                $subject = rawurlencode("Deine Buchung " . $b->booking_nr);
                $body = rawurlencode("Hallo " . $b->name . ",\n\nvielen Dank für deine Anfrage...");
                $html .= "<a href='mailto:" . $b->email . "?subject=$subject&body=$body' style='color:blue; text-decoration:none;'>[MAIL]</a> | ";
            }
            
        if ($b->paid == 0) {
            $html .= "<a href='?wpec_act=mark_paid&bid=$b->id' style='color:#2980b9; text-decoration:none;'>[€€]</a> | ";
        }

            $html .= "<a href='?wpec_act=delete&bid=$b->id' style='color:red; text-decoration:none;' onclick='return confirm(\"Wirklich stornieren?\")'>[X]</a>
                </td></tr>";
        }
    } else {
        $html .= '<tr><td colspan="4" style="padding:10px; text-align:center;">Keine aktiven Buchungen vorhanden.</td></tr>';
    }
    $html .= '</table>';

    // Archiv-Sektion f�r Stornierungen (ausklappbar)
    if ($cancelled_bookings) {
        $html .= '<details style="margin-top:20px; background:#f9f9f9; padding:10px; border:1px solid #ddd; border-radius:5px;">';
        $html .= '<summary style="cursor:pointer; color:#666; font-size:12px; font-weight:bold;">Stornierte Buchungen anzeigen</summary>';
        $html .= '<table style="width:100%; font-size:10px; border-collapse:collapse; margin-top:10px; color:#777;">';
        $html .= '<tr style="text-align:left; border-bottom:1px solid #ccc;"><th>Nr</th><th>Name</th><th>Zeitraum</th><th>Storno-Datum</th></tr>';
        foreach ($cancelled_bookings as $cb) {
            $html .= "<tr style='border-bottom:1px solid #eee;'>
                <td>$cb->booking_nr</td>
                <td>$cb->name<br><a href='mailto:$cb->email'>$cb->email</a><br>$cb->phone</td>
                <td>" . date('d.m.', strtotime($cb->start_date)) . "-" . date('d.m.', strtotime($cb->end_date)) . "</td>
                <td>" . date('d.m.Y', strtotime($cb->created_at)) . "</td>
            </tr>";
        }
        $html .= '</table></details>';
    }

    return $html;
}
// --- 5. KALENDER ---
function wpec_get_calendar_view() {
    global $wpdb;
    $max = get_option('wpec_max_slots', 10);
    $table = $wpdb->prefix . 'easycamp_bookings';

    $m = isset($_GET['w_m']) ? intval($_GET['w_m']) : date('n');
    $y = isset($_GET['w_y']) ? intval($_GET['w_y']) : date('Y');
    $today_d = date('j'); $today_m = date('n'); $today_y = date('Y');

    $prev_m = ($m == 1) ? 12 : $m - 1; $prev_y = ($m == 1) ? $y - 1 : $y;
    $next_m = ($m == 12) ? 1 : $m + 1; $next_y = ($m == 12) ? $y + 1 : $y;

    $days_in_month = cal_days_in_month(CAL_GREGORIAN, $m, $y);
    $first_day_timestamp = mktime(0, 0, 0, $m, 1, $y);
    $first_day_of_week = date('N', $first_day_timestamp); 
    $month_name = date_i18n('F Y', $first_day_timestamp);

    $html = '<script>
        // Verhindert das Neuladen der Seite beim Bl�ttern
        async function loadMonth(url) {
            const container = document.getElementById("wpec-calendar-container");
            if(!container) return;
            container.style.opacity = "0.5";
            try {
                const response = await fetch(url);
                const text = await response.text();
                const parser = new DOMParser();
                const doc = parser.parseFromString(text, "text/html");
                const newCalendar = doc.getElementById("wpec-calendar-container").innerHTML;
                container.innerHTML = newCalendar;
                // Nach dem Laden Range-Highlighting wiederherstellen, falls Daten schon im Formular stehen
                const startInput = document.getElementsByName("start_date")[0];
                const endInput = document.getElementsByName("end_date")[0];
                if(startInput && startInput.value) highlightRange(startInput.value, endInput.value);
            } catch (err) {
                console.error("Fehler beim Laden des Kalenders", err);
            }
            container.style.opacity = "1";
        }

        let clickCount = 0;
        function setDate(dateStr) {
            const startInput = document.getElementsByName("start_date")[0];
            const endInput = document.getElementsByName("end_date")[0];
            if (clickCount % 2 === 0) {
                startInput.value = dateStr;
                endInput.value = "";
                highlightRange(dateStr, null);
            } else {
                if(new Date(dateStr) > new Date(startInput.value)) {
                    endInput.value = dateStr;
                    highlightRange(startInput.value, dateStr);
                } else {
                    alert("Abreise muss nach der Anreise liegen!");
                    return;
                }
            }
            clickCount++;
        }

        function highlightRange(start, end) {
            const cells = document.querySelectorAll(".wpec-day");
            cells.forEach(cell => {
                const cellDate = cell.getAttribute("data-date");
                cell.style.outline = "none";
                cell.style.boxShadow = "none";
                if (cellDate === start || (end && cellDate === end)) {
                    cell.style.outline = "3px solid #3498db";
                    cell.style.outlineOffset = "-3px";
                }
                if (end && cellDate > start && cellDate < end) {
                    cell.style.boxShadow = "inset 0 0 0 1000px rgba(52, 152, 219, 0.3)";
                }
            });
        }
    </script>';

    $html .= '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; background:#eee; padding:5px; border-radius:4px;">';
    
    $prev_url = add_query_arg(['w_m' => $prev_m, 'w_y' => $prev_y]);
    $html .= '<a href="javascript:void(0)" onclick="loadMonth(\''.$prev_url.'\')" style="text-decoration:none; padding:5px 10px; background:#fff; border-radius:3px; color:#333; font-size:12px; font-weight:bold;">&laquo;</a>';
    
    $html .= '<strong style="font-size:14px;">' . $month_name . '</strong>';
    
    $next_url = add_query_arg(['w_m' => $next_m, 'w_y' => $next_y]);
    $html .= '<a href="javascript:void(0)" onclick="loadMonth(\''.$next_url.'\')" style="text-decoration:none; padding:5px 10px; background:#fff; border-radius:3px; color:#333; font-size:12px; font-weight:bold;">&raquo;</a>';
    
    $html .= '</div>';

    $html .= '<div style="display:grid; grid-template-columns: repeat(7, 1fr); gap:4px; margin-bottom:15px;">';
    $weekdays = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
    foreach ($weekdays as $day) { $html .= '<div style="text-align:center; font-size:10px; font-weight:bold; color:#666;">' . $day . '</div>'; }
    for ($x = 1; $x < $first_day_of_week; $x++) { $html .= '<div></div>'; }

    for ($i = 1; $i <= $days_in_month; $i++) {
        $dateStr = "$y-" . str_pad($m, 2, '0', STR_PAD_LEFT) . "-" . str_pad($i, 2, '0', STR_PAD_LEFT);
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE status != 'cancelled' AND %s BETWEEN start_date AND DATE_SUB(end_date, INTERVAL 1 DAY)", $dateStr));
        
        $bg = "background:#d5f5e3;"; 
        if ($count >= $max) { $bg = "background:#fadbd8; color:#721c24;"; }
        elseif ($count > 0) { $bg = "background:linear-gradient(135deg, #d5f5e3 50%, #fcf3cf 50%);"; }

        $border = ($i == $today_d && $m == $today_m && $y == $today_y) ? "border: 2px solid #2980b9;" : "border: 1px solid #eee;";
        
        $html .= "<div onclick='setDate(\"$dateStr\")' class='wpec-day' data-date='$dateStr' style='$bg $border cursor:pointer; padding:8px; text-align:center; border-radius:4px; font-size:11px; font-weight:bold;'>$i</div>";
    }
    $html .= '</div>';

    $html .= '<div style="font-size:10px; color:#555; text-align:center; line-height:1.5; margin-bottom:20px;">
                <span style="display:inline-block; width:15px; height:15px; background:#d5f5e3; border:1px solid #ccc;"></span> Frei &nbsp;
                <span style="display:inline-block; width:15px; height:15px; background:linear-gradient(135deg, #d5f5e3 50%, #fcf3cf 50%); border:1px solid #ccc;"></span> Teilbelegt &nbsp;
                <span style="display:inline-block; width:15px; height:15px; background:#fadbd8; border:1px solid #ccc;"></span> Voll &nbsp;
                <span style="display:inline-block; width:15px; height:15px; border:2px solid #2980b9;"></span> Heute <br>
                Tippe zwei Tage im Kalender an, um den Zeitraum zu waehlen.
              </div>';
    
    return $html;
}

// --- 6. ADMIN MENU ---
add_action('admin_menu', 'wpec_add_menu');
function wpec_add_menu() {
    add_menu_page('EasyCamp', 'EasyCamp', 'manage_options', 'wpec-settings', 'wpec_settings_page', 'dashicons-calendar-alt');
}
function wpec_settings_page() {
    if (isset($_POST['save'])) {
        update_option('wpec_auto_confirm', $_POST['wpec_auto_confirm']);
        update_option('wpec_headline_cal', sanitize_text_field($_POST['hc']));
        update_option('wpec_fkk_mode', isset($_POST['fkk']) ? '1' : '0');
        update_option('wpec_max_slots', intval($_POST['ms']));
        update_option('wpec_cancel_hours', intval($_POST['ch']));
        update_option('wpec_cancel_fee', floatval($_POST['cf']));
        update_option('wpec_staff_pw', sanitize_text_field($_POST['spw']));
        update_option('wpec_sender_name', sanitize_text_field($_POST['sn']));
        update_option('wpec_sender_mail', sanitize_email($_POST['sm']));
        echo '<div class="updated"><p>Einstellungen gespeichert!</p></div>';
    }
    $ical_url = home_url('/?wpec_feed=' . get_option('wpec_ical_token'));
    ?>
    <div class="wrap"><h1>EasyCamp - Globale Einstellungen</h1>
    <div style="background:#fff; border-left:4px solid #3498db; padding:15px; margin:20px 0; box-shadow:0 1px 1px rgba(0,0,0,.1);">
        <h2 style="margin-top:0;">Google Kalender Export</h2>
        <p>Kopiere diesen Link und fuege ihn in deinem Google Kalender unter "Per URL" ein:</p>
        <code style="display:block; background:#eee; padding:10px; border:1px solid #ccc; word-break:break-all;"><?php echo $ical_url; ?></code>
    </div>
    <form method="post"><table class="form-table">
        <tr style="background:#eee;"><th colspan="2">E-Mail Absender (Header)</th></tr>
        <tr><th>Anzeigename (z.B. Name-Campingplatz)</th><td><input type="text" name="sn" value="<?php echo get_option('wpec_sender_name'); ?>" class="regular-text"></td></tr>
        <tr><th>Absender E-Mail</th><td><input type="email" name="sm" value="<?php echo get_option('wpec_sender_mail'); ?>" class="regular-text"></td></tr>
        <tr><th>Bestätigungs-Modus</th><td><select name="wpec_auto_confirm">
            <option value="0" <?php selected(get_option('wpec_auto_confirm'), '0'); ?>>Manuell (Betreuer schreibt Mail selbst)</option>
            <option value="1" <?php selected(get_option('wpec_auto_confirm'), '1'); ?>>Automatisch (System sendet sofort)</option>
        </select></td></tr>
        <tr><th>Kalender Überschrift</th><td><input type="text" name="hc" value="<?php echo get_option('wpec_headline_cal'); ?>" class="regular-text"></td></tr>
        <tr><th>FKK-Modus aktiv?</th><td><input type="checkbox" name="fkk" value="1" <?php checked(get_option('wpec_fkk_mode'), '1'); ?>> 
        <small>(Wenn aktiv, muss der Gast bestätigen, dass es ein FKK-Platz ist)</small></td></tr>
        <tr style="background:#eee;"><th colspan="2">Buchungsparameter</th></tr>
        <tr><th>Max. Plaetze pro Tag</th><td><input type="number" name="ms" value="<?php echo get_option('wpec_max_slots'); ?>"></td></tr>
        <tr><th>Stornofrist (Stunden)</th><td><input type="number" name="ch" value="<?php echo get_option('wpec_cancel_hours'); ?>"></td></tr>
        <tr><th>Stornogebuehr (&euro;)</th><td><input type="number" step="0.01" name="cf" value="<?php echo get_option('wpec_cancel_fee'); ?>"></td></tr>
        <tr><th>Betreuer Passwort (Frontend)</th><td><input type="text" name="spw" value="<?php echo get_option('wpec_staff_pw'); ?>"></td></tr>
    </table><input type="submit" name="save" class="button button-primary" value="Speichern"></form></div>
    <?php
}