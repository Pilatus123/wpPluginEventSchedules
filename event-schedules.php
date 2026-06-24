<?php
/**
 * Plugin Name: Event Schedules
 * Description: Manage event sessions, import participants, and show personal schedules.
 * Version: 3.3
 * 
 * ================================================================================
 * OVERVIEW
 * ================================================================================
 * 
 * ADMIN WORKFLOW:
 * 1. Go to Tools -> Event Schedules to manage sessions and participants.
 * 2. Define SLOTS: e.g. "Slot 1" Saturday 14 June 09:00-10:30. A slot is a fixed time block.
 *    Multiple events can share one slot.
 * 3. Add EVENTS: 
 *    For "for everyone" events (meals, keynotes) no CSV name is needed. Enter their own date,
 *    time, location and description (optional). No slot is needed
 *    For workshop events enter CSV name, pick slots, location and description (optional).
 *    The time comes from the slot and not the event itself.
 * 4. Upload participants CSV
 * 5. Upload Buchungen CSV to assign workshops.
 * 6. Reset button wipes everything!!
 * 
 * WORKFLOW TO INGETRATE INTO WEBSITE:
 * 1. Create a WP page, paste [event_search] into it.
 *    Participants can search for their name, see their schedule. 
 * 2. Create another WP page, paste [event_schedule] into it.
 *   Participants can see their schedule in a calendar format (plugin reads ?=TOKEN from URL).
 * 

 * =============================================================================
 * DATABASE TABLES
 * =============================================================================
 *
 *   wp_es_participants
 *     email             VARCHAR(190)  PRIMARY KEY  — unique identifier, links both CSVs
 *     first_name        TEXT
 *     last_name         TEXT
 *     token             VARCHAR(64)   random hex — used in the schedule URL (?t=TOKEN)
 *     created_at        DATETIME
 *
 *   wp_es_slots
 *     id                BIGINT AUTO_INCREMENT PRIMARY KEY
 *     name              VARCHAR(64)   short label shown on schedule, e.g. "Slot 1"
 *     slot_date         DATE NULL
 *     start_time        TIME NULL
 *     end_time          TIME NULL
 *  
 *
 *   wp_es_events
 *     id                BIGINT AUTO_INCREMENT PRIMARY KEY
 *     display_name      TEXT          what participants see on their schedule
 *     csv_name          TEXT NULL     exact workshop name from the Buchungen CSV
 *                                     (only needed for workshop events, not for-everyone)
 *     location          TEXT NULL     e.g. "Room 3", "Main Hall", "Restaurant"
 *     description       TEXT NULL     optional text shown on the schedule card
 *     event_date        DATE NULL     only set when for_everyone = 1
 *     start_time        TIME NULL     only set when for_everyone = 1
 *     end_time          TIME NULL     only set when for_everyone = 1
 *                                     (workshop events get their time from wp_es_slots)
 *     type              VARCHAR(32)   workshop / meal / keynote / break / social / other
 *     for_everyone      TINYINT(1)    1 = shown to all participants automatically 0 = only shown if participant is enrolled
 *     double_slot       TINYINT(1)    whether the workshop's duration is 1 or 2 slots
 *                          
 *   wp_es_event_slots
 *     event_id          BIGINT        FK -> wp_es_events.id
 *     slot_id           BIGINT        FK -> wp_es_slots.id
 *     PRIMARY KEY (event_id, slot_id)
 *     — links a workshop to the slots it runs in
 *     — a double workshop has two rows here (one per slot)
 *
 *   wp_es_enrollments
 *     id                BIGINT AUTO_INCREMENT PRIMARY KEY
 *     participant_email VARCHAR(190)  FK -> wp_es_participants.email
 *     event_id          BIGINT        FK -> wp_es_events.id
 *     slot_id           BIGINT        FK -> wp_es_slots.id — which slot this enrollment is for
 *     UNIQUE (participant_email, event_id, slot_id)
 *
 * =============================================================================
 * CSV COLUMN MATCHING
 * =============================================================================
 *
 * All column lookups use PREFIX matching (find_col_prefix / find_all_cols_prefix).
 * The plugin works even if the registration platform changes the full column name,
 * as long as the start of the name stays the same.
 *
 *   "E-Mail"             matches "E-Mail (please use your students email...)"
 *   "First Name"         matches "First Name"
 *   "Last Name"          matches "Last Name"
 *   "Status"             matches "Status"
 *   "Please select the"  matches both workshop columns
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

define('colorMain', '#af2417'); // used for buttons, and calander headers
define("colorLight", '#af2417a6'); // used for borders
define("colorLight2", '#af241764'); // used for borders between events in schedule view
define("colorTextBG", '#f5f3f3'); // used for text on colored backgrounds (e.g. event cards in schedule view)
define("color_hover", '#f2d3d3'); // used for hover states on buttons and event cards in schedule view
define("color_workshopType", '#b9a09e'); // used for the badge that indicates the event type (workshop, meal, social, etc.) in schedule view
class Event_Schedules
{
    const TABLE_PARTICIPANTS = 'es_participants';
    const TABLE_SLOTS = 'es_slots';
    const TABLE_EVENTS = 'es_events';
    const TABLE_EVENT_SLOTS = 'es_event_slots';
    const TABLE_ENROLLMENTS = 'es_enrollments';

    const DB_VERSION = '1.0';



    // init() is called when the plugin is loaded. It wires up all the hooks.
    public static function init()
    {
        // Called once when admin clicks "Activate Plugin"
        register_activation_hook(__FILE__, [__CLASS__, 'on_activate']);

        // Add admin menu under Tools in WP admin sidebar
        add_action('admin_menu', [__CLASS__, 'add_admin_menu']);

        // Load our inline CSS only on our own admin page (not everywhere)
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assests']);

        // admin_post_{action} is the hook WP calls when a form with action="{admin_url('admin-post.php')}" and input name="action" is submitted.
        add_action('admin_post_es_save_slot', [__CLASS__, 'handle_save_slot']);
        add_action('admin_post_es_delete_slot', [__CLASS__, 'handle_delete_slot']);
        add_action('admin_post_es_save_event', [__CLASS__, 'handle_save_event']);
        add_action('admin_post_es_delete_event', [__CLASS__, 'handle_delete_event']);
        add_action('admin_post_es_import_participants', [__CLASS__, 'handle_import_participants']);
        add_action('admin_post_es_import_buchungen', [__CLASS__, 'handle_import_buchungen']);
        add_action('admin_post_es_save_settings', [__CLASS__, 'handle_save_settings']);
        add_action('admin_post_es_reset', [__CLASS__, 'handle_reset']);

        // Shortcodes: [event_search] and [event_schedule]
        // WP calls these functions and replaces the tag with their return value
        add_shortcode('event_search', [__CLASS__, 'shortcode_search']);
        add_shortcode('event_schedule', [__CLASS__, 'shortcode_schedule']);

        // Register our custom URL param so WP doesn't strip it
        add_action('init', [__CLASS__, 'register_query_vars']);

        // Intercept requests for ICS calendar file download
        add_action('template_redirect', [__CLASS__, 'maybe_serve_ics']);
    }


    // HELPERS

    // Helper to build full table name including WP's prefix
    private static function table_name($name)
    {
        global $wpdb;
        return $wpdb->prefix . $name;
    }

    // Helper: store flash mesages to display after the POST->redirect->GET cycle
    // type: 'success' | 'error' | 'warning' | 'info'
    private static function set_flash(array $messages)
    {
        set_transient('es_flash_' . get_current_user_id(), $messages, 60);
    }

    private static function redirect_admin(array $extra = [])
    {
        wp_redirect(add_query_arg(array_merge(['page' => 'event-schedules'], $extra), admin_url('tools.php')));
        exit;
    }

    // Find the first column header that start with the given prefix
    // case-sensitive, returns the full header string or null
    private static function find_col_prefix(array $headers, string $prefix): ?string
    {
        foreach ($headers as $h) {
            if (stripos(trim($h), $prefix) === 0)
                return $h;
        }
        return null;
    }

    // Find all column headers that start with the given prefix
    // used for multiple "Please select workshop..." columns.
    private static function find_all_cols_prefix(array $headers, string $prefix): array
    {
        $matches = [];
        foreach ($headers as $i => $h) {
            if (stripos(trim($h), $prefix) === 0)
                $matches[$i] = $h;
        }
        return $matches;
    }



    //ACTIVATION

    // create DB tables on activation
    public static function on_activate()
    {
        self::create_tables();
    }

    private static function create_tables()
    {
        global $wpdb;

        // dbDelta() creates tables if missing, adds columns if 
        // they don't exist, but doesn't delete anything. Safe to run on every activation.
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $charset = $wpdb->get_charset_collate();
        $pt = self::table_name(self::TABLE_PARTICIPANTS);
        $sl = self::table_name(self::TABLE_SLOTS);
        $et = self::table_name(self::TABLE_EVENTS);
        $es = self::table_name(self::TABLE_EVENT_SLOTS);
        $nt = self::table_name(self::TABLE_ENROLLMENTS);

        // PARTICIPANTS: email is the primary key because it's the common identifier
        // across both the participants CSV and the Buchungen CSV.
        // token is the random string used in the public schedule URL (?t=TOKEN).
        dbDelta("CREATE TABLE $pt (
            email VARCHAR(190) NOT NULL,
            first_name TEXT NOT NULL,
            last_name TEXT NOT NULL,
            token VARCHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (email),
            UNIQUE KEY token (token)
        ) $charset;");

        // SLOTS: created manually by admin. Key fields:
        //   name       — short label e.g. "Slot 1"
        //  slot_date   — the date of this slot (used for display and to prevent overlapping slots)
        //  start_time  — the start time of this slot (used for display and to prevent overlapping slots)
        //  end_time    — the end time of this slot (used for display and to prevent overlapping slots)

        dbDelta("CREATE TABLE $sl (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(64) NOT NULL,
            slot_date DATE NULL,
            start_time TIME NULL,
            end_time TIME NULL,
            PRIMARY KEY  (id)
        ) $charset;");

        // EVENTS: created manually by admin. Key fields:
        //  display_name — shown on the participant's schedule
        //  csv_name     — the exact string to match in the Buchungen CSV
        //  for_everyone — if 1, shown to all participants without needing enrollment
        //  type         — workshop / meal / break / social / other (used for display only, no functional difference)
        //  date/time     — only used for "for everyone" events, workshops get their time from the slots they are linked to
        //  description    — optional text shown on the schedule card
        //   
        dbDelta("CREATE TABLE $et (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            display_name TEXT NOT NULL,
            csv_name TEXT NULL,
            location TEXT NULL,
            description TEXT NULL,
            event_date DATE NULL,
            start_time TIME NULL,
            end_time TIME NULL,
            type VARCHAR(32) NOT NULL DEFAULT 'workshop',
            for_everyone TINYINT(1) NOT NULL DEFAULT 0,
            double_slot TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id)
        ) $charset;");


        // EVENT SLOTS: pure join table linking events to slots.
        // An event can have multiple slots (e.g. a workshop
        // that runs in both Slot 1 and Slot 2), and a slot can have
        // multiple events (e.g. Slot 1 has Workshop A and Workshop B
        // running simultaneously, participants choose which one to attend).
        dbDelta("CREATE TABLE $es (
            event_id BIGINT UNSIGNED NOT NULL,
            slot_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY  (event_id, slot_id),
            KEY slot_id (slot_id)
        ) $charset;");


        // ENROLLMENTS: pure join table linking participants to events.
        // UNIQUE(participant_email, event_id) prevents duplicate enrollments.
        dbDelta("CREATE TABLE $nt (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            participant_email VARCHAR(190) NOT NULL,
            event_id BIGINT UNSIGNED NOT NULL,
            slot_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_pair (participant_email, event_id, slot_id),
            KEY participant_email (participant_email),
            KEY event_id (event_id),
            KEY slot_id (slot_id)
        ) $charset;");

        update_option('es_db_version', self::DB_VERSION);
    }

    // Admin Menu & Assets
    // Adds "Event SChedules" under Tools in the left-hand WP admin sidebar
    public static function add_admin_menu()
    {
        add_management_page(
            'Event Schedules', // browser <title>
            'Event Schedules', // sodebar menu labels
            'manage_options',  // capability required to see this menu
            'event-schedules', // page slug (used in URL)
            [__CLASS__, 'admin_page'] // callback to render the page
        );
    }

    // Load small inline stylesheet only on our admin page
    public static function admin_assests($hook)
    {
        // Only load on our plugin's admin page
        if ($hook !== 'tools_page_event-schedules') {
            return;
        }
        // Small amount of CSS to make the admin page look nicer
        wp_add_inline_style('wp-admin', '
      .es-section { background:#fff; border:1px solid #ccd0d4; padding:20px 24px; margin-bottom:24px; }
      .es-section h2 { margin-top:0; padding-bottom:10px; border-bottom:1px solid #eee; }
      .es-stats { display:flex; gap:20px; flex-wrap:wrap; margin-bottom:12px; }
      .es-stat { background:#f0f6fc; border:1px solid #c3d4e4; border-radius:4px; padding:12px 20px; text-align:center; min-width:100px; }
      .es-stat strong { display:block; font-size:28px; line-height:1.2; color:#0073aa; }
      .es-stat span { font-size:12px; color:#666; }
      .es-table { width:100%; border-collapse:collapse; margin-top:12px; font-size:13px; }
      .es-table th { background:#f5f5f5; text-align:left; padding:8px 10px; border:1px solid #ddd; }
      .es-table td { padding:8px 10px; border:1px solid #ddd; vertical-align:top; }
      .es-table tr:nth-child(even) td { background:#fafafa; }
      .es-badge { display:inline-block; border-radius:3px; padding:1px 7px; font-size:11px; font-weight:600; }
      .es-badge-green { background:#00a32a; color:#fff; }
      .es-badge-blue  { background:#0073aa; color:#fff; }
      .es-badge-grey  { background:#888; color:#fff; }
      .es-edit-row td { background:#fffce0 !important; }
      .es-danger { border-color:#d63638 !important; }
      .es-danger h2 { color:#d63638; }
      .es-slot-checks { display:flex; flex-wrap:wrap; gap:12px; }
      .es-slot-checks label { display:flex; align-items:center; gap:6px; background:#f0f6fc;
        border:1px solid #c3d4e4; border-radius:4px; padding:6px 12px; cursor:pointer; }
      .es-slot-checks label:hover { background:#e0eef8; }
    ');

        // JavaScript to toggle date/time fields vs slot checkboxes
        // based on whether "for everyone" is checked or not.
        wp_add_inline_script('jquery', '
      jQuery(function($) {
        function toggleEventFormMode() {
          var forEveryone = $("#es_for_everyone").is(":checked");
          // Show date/time fields for "for everyone" events
          $(".es-datetime-row").toggle(forEveryone);
          // Show slot checkboxes for workshop events
          $(".es-slots-row").toggle(!forEveryone);
          // Show/hide CSV name row (only needed for workshops)
          $(".es-csvname-row").toggle(!forEveryone);
        }
        // Run on page load to set the correct initial state
        toggleEventFormMode();
        // Run whenever the checkbox changes
        $("#es_for_everyone").on("change", toggleEventFormMode);
      });
    ');
    }

    // Renders the full admin UI in sections.

    public static function admin_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions to access this page.');
        }

        global $wpdb;
        $pt = self::table_name(self::TABLE_PARTICIPANTS);
        $sl = self::table_name(self::TABLE_SLOTS);
        $et = self::table_name(self::TABLE_EVENTS);
        $nt = self::table_name(self::TABLE_ENROLLMENTS);
        $form_url = esc_url(admin_url('admin-post.php'));

        // read flash messages
        $uid = get_current_user_id();
        $flash = get_transient("es_flash_$uid");
        delete_transient("es_flash_$uid"); // flash messages are one-time use, so delete after reading

        // check if we are editing an existing event
        $editing_slot = null;
        $editing_event = null;
        if (!empty($_GET['edit_slot']))
            $editing_slot = $wpdb->get_row($wpdb->prepare("SELECT * FROM $sl WHERE id=%d", (int) $_GET['edit_slot']));
        if (!empty($_GET['edit_event']))
            $editing_event = $wpdb->get_row($wpdb->prepare("SELECT * FROM $et WHERE id=%d", (int) $_GET['edit_event']));

        $total_participants = (int) $wpdb->get_var("SELECT COUNT(*) FROM $pt");
        $total_events = (int) $wpdb->get_var("SELECT COUNT(*) FROM $et");
        $total_enrollments = (int) $wpdb->get_var("SELECT COUNT(*) FROM $nt");
        $all_slots = $wpdb->get_results("SELECT * FROM $sl ORDER BY slot_date ASC, start_time ASC");

        echo '<div class="wrap"><h1>Event Schedules</h1>';

        // Flash message
        if ($flash) {
            foreach ((array) $flash as $msg) {
                echo '<div class="notice notice-' . esc_attr($msg['type'] ?? 'info') . ' is-dismissible"><p>' . wp_kses_post($msg['msg']) . '</p></div>';
            }
        }

        // Section 1: overwiev & settings
        echo '<div class="es-section">';
        echo '<h2>Overview</h2>';
        echo '<div class="es-stats">';
        echo '<div class="es-stat"><strong>' . $total_participants . '</strong><span>Participants</span></div>';
        echo '<div class="es-stat"><strong>' . $total_events . '</strong><span>Events/Sessions</span></div>';
        echo '<div class="es-stat"><strong>' . count($all_slots) . '</strong><span>Slots</span></div>';
        echo '<div class="es-stat"><strong>' . $total_enrollments . '</strong><span>Enrollments</span></div>';
        echo '</div>';

        // Schedule page URL settings
        $schedule_url = get_option('es_schedule_page_id', '');
        echo '<h3 style="margin-top:16px">Schedule Page URL</h3>';
        echo '<p>Set the URL of the page where you placed <code>[event_schedule]</code>. '
            . 'The search form uses this to redirect participants to their schedule.</p>';
        echo '<form method="post" action="' . $form_url . '">';
        echo '<input type="hidden" name="action" value="es_save_settings">';
        wp_nonce_field('es_save_settings', 'es_nonce');
        echo '<input type="url" name="schedule_page_url" value="' . esc_attr($schedule_url) . '" '
            . 'class="regular-text" placeholder="https://smscbern/my-schedule/">';
        echo ' <button type="submit" class="button">Save</button>';
        echo '</form>';
        echo '</div>';

        // Section 2: Slots manager
        echo '<div class="es-section">';
        echo '<h2>' . ($editing_slot ? 'Edit Slot' : 'Add New Slot') . '</h2>';
        echo '<p>Slots are fixed time blocks for workshops (e.g. "Slot 1", "Slot 2"). '
            . '"For everyone" events have their own time set directly on the event.</p>';

        $sv = $editing_slot;
        echo '<form method="post" action="' . $form_url . '">';
        echo '<input type="hidden" name="action" value="es_save_slot">';
        if ($sv)
            echo '<input type="hidden" name="slot_id" value="' . (int) $sv->id . '">';
        wp_nonce_field('es_save_slot', 'es_nonce');
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th><label for="es_slot_name">Slot name <span style="color:red">*</span></label></th>';
        echo '<td><input type="text" id="es_slot_name" name="slot_name" class="regular-text" required '
            . 'value="' . esc_attr($sv->name ?? '') . '" placeholder="e.g. Slot 1"></td></tr>';
        echo '<tr><th><label for="es_slot_date">Date</label></th>';
        echo '<td><input type="date" id="es_slot_date" name="slot_date" value="' . esc_attr($sv->slot_date ?? '') . '"></td></tr>';
        $sst = $sv ? substr($sv->start_time ?? '', 0, 5) : '';
        $set = $sv ? substr($sv->end_time ?? '', 0, 5) : '';
        echo '<tr><th><label for="es_slot_start">Start time</label></th>';
        echo '<td><input type="time" id="es_slot_start" name="start_time" value="' . esc_attr($sst) . '"></td></tr>';
        echo '<tr><th><label for="es_slot_end">End time</label></th>';
        echo '<td><input type="time" id="es_slot_end" name="end_time" value="' . esc_attr($set) . '"></td></tr>';
        echo '<tr><th><label for="es_slot_order">Sort order</label></th>';
        echo '</table>';
        if ($editing_slot) {
            echo '<p><button type="submit" class="button button-primary">Update Slot</button> ';
            echo '<a href="' . esc_url(admin_url('tools.php?page=event-schedules')) . '" class="button">Cancel</a></p>';
        } else {
            echo '<p><button type="submit" class="button button-primary">Add Slot</button></p>';
        }
        echo '</form>';

        if ($all_slots) {
            echo '<h3 style="margin-top:24px">All Slots (' . count($all_slots) . ')</h3>';
            echo '<table class="es-table"><thead><tr><th>Name</th><th>Date</th><th>Start</th><th>End</th><th>Actions</th></tr></thead><tbody>';
            foreach ($all_slots as $slot) {
                $row_cls = ($editing_slot && $editing_slot->id == $slot->id) ? ' class="es-edit-row"' : '';
                echo '<tr' . $row_cls . '><td><strong>' . esc_html($slot->name) . '</strong></td>';
                echo '<td>' . esc_html($slot->slot_date ?: '—') . '</td>';
                echo '<td>' . esc_html($slot->start_time ? substr($slot->start_time, 0, 5) : '—') . '</td>';
                echo '<td>' . esc_html($slot->end_time ? substr($slot->end_time, 0, 5) : '—') . '</td>';
                $edit_url = esc_url(add_query_arg(['page' => 'event-schedules', 'edit_slot' => $slot->id], admin_url('tools.php')));
                echo '<td style="white-space:nowrap"><a href="' . $edit_url . '" class="button button-small">Edit</a> ';
                echo '<form method="post" action="' . $form_url . '" style="display:inline" onsubmit="return confirm(\'Delete this slot?\');">';
                echo '<input type="hidden" name="action" value="es_delete_slot">';
                echo '<input type="hidden" name="slot_id" value="' . (int) $slot->id . '">';
                wp_nonce_field('es_delete_slot', 'es_del_slot_nonce_' . $slot->id);
                echo '<button type="submit" class="button button-small" style="color:#d63638">Delete</button></form></td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p style="color:#666;margin-top:12px">No slots yet.</p>';
        }
        echo '</div>';

        //Section 3: Events manager
        echo '<div class="es-section">';
        echo '<h2>' . ($editing_event ? 'Edit Event' : 'Add New Event / Session') . '</h2>';
        $event_slot_ids = [];
        if ($editing_event) {
            $es_table = self::table_name(self::TABLE_EVENT_SLOTS);
            $event_slot_ids = array_column(
                $wpdb->get_results($wpdb->prepare("SELECT slot_id FROM $es_table WHERE event_id=%d", $editing_event->id)),
                'slot_id'
            );
        }
        // Add/Edit form, when $editing is set, hidden field event_id tells
        // the handler to UPDATE; when it's null the handler INSERTs a new row.
        $v = $editing_event; // shorthand for easier access to event properties in the form
        echo '<form method="post" action="' . $form_url . '">';
        echo '<input type="hidden" name="action" value="es_save_event">';
        if ($v)
            echo '<input type="hidden" name="event_id" value="' . (int) $v->id . '">';
        wp_nonce_field('es_save_event', 'es_nonce');

        echo '<table class="form-table" role="presentation">';

        // Display name
        echo '<tr><th><label for"es_display_name">Display name <span style="color:red">*</span></label></th>';
        echo '<td><input type="text" id="es_display_name" name="display_name" class="regular-text" required '
            . 'value="' . esc_attr($v->display_name ?? '') . '">'
            . '<p class="description">What participants see on their schedule. '
            . 'E.g. "Lunch Break" or "Survival Kit for the First Year of Residency Workshop"</p></td></tr>';

        // For everyone: this checkbox controls which fields are shown via JS
        $fe_checked = ($v && $v->for_everyone) ? 'checked' : '';
        echo '<tr><th><label for="es_for_everyone">For everyone</label></th>';
        echo '<td><label><input type="checkbox" id="es_for_everyone" name="for_everyone" value="1" ' . $fe_checked . '> '
            . 'Show on every participant\'s schedule (meals, keynotes, etc.)</label>'
            . '<p class="description">When ticked: enter the event\'s own date and time below. '
            . 'When unticked: assign the event to slots.</p></td></tr>';

        // DATE/TIME rows (shown only when "for everyone" is ticked)
        // The class "es-datetime-row" is what the JS looks for to show/hide these rows
        $ev_date = $v ? ($v->event_date ?? '') : '';
        $ev_st = $v ? substr($v->start_time ?? '', 0, 5) : '';
        $ev_et = $v ? substr($v->end_time ?? '', 0, 5) : '';
        echo '<tr class="es-datetime-row"><th><label for="es_event_date">Date</label></th>';
        echo '<td><input type="date" id="es_event_date" name="event_date" value="' . esc_attr($ev_date) . '"></td></tr>';
        echo '<tr class="es-datetime-row"><th><label for="es_start_time">Start time</label></th>';
        echo '<td><input type="time" id="es_start_time" name="start_time" value="' . esc_attr($ev_st) . '"></td></tr>';
        echo '<tr class="es-datetime-row"><th><label for="es_end_time">End time</label></th>';
        echo '<td><input type="time" id="es_end_time" name="end_time" value="' . esc_attr($ev_et) . '"></td></tr>';

        // CSV NAME row (shown only for workshops)
        echo '<tr class="es-csvname-row"><th><label for="es_csv_name">CSV name</label></th>';
        echo '<td><input type="text" id="es_csv_name" name="csv_name" class="large-text" '
            . 'value="' . esc_attr($v->csv_name ?? '') . '">'
            . '<p class="description">Exact workshop name from the Buchungen CSV. Copy-paste to avoid typos.</p></td></tr>';

        // SLOT CHECKBOXES row (shown only for workshops)
        echo '<tr class="es-slots-row"><th>Slots</th><td>';
        if ($all_slots) {
            echo '<div class="es-slot-checks">';
            foreach ($all_slots as $slot) {
                $is_checked = in_array($slot->id, $event_slot_ids) ? 'checked' : '';
                $slot_label = esc_html($slot->name);
                if ($slot->slot_date)
                    $slot_label .= ' (' . esc_html($slot->slot_date);
                if ($slot->start_time)
                    $slot_label .= ' ' . esc_html(substr($slot->start_time, 0, 5));
                if ($slot->end_time)
                    $slot_label .= '–' . esc_html(substr($slot->end_time, 0, 5));
                if ($slot->slot_date)
                    $slot_label .= ')';
                echo '<label><input type="checkbox" name="slots[]" value="' . (int) $slot->id . '" ' . $is_checked . '>'
                    . $slot_label . '</label>';
            }
            echo '</div>';
            echo '<p class="description">Tick every slot this workshop runs in.</p>';
        } else {
            echo '<p style="color:#d63638">No slots defined yet. Add slots above first.</p>';
        }
        echo '</td></tr>';

        // Double slot checkbox
        $ds_checked = ($v && $v->double_slot) ? 'checked' : '';
        echo '<tr class="es-slots-row"><th><label for="es_double_slot">Double slot</label></th>';
        echo '<td><label><input type="checkbox" id="es_double_slot" name="double_slot" value="1" ' . $ds_checked . '> '
            . 'This workshop runs continuously across ALL ticked slots</label>'
            . '<p class="description">'
            . '<strong>Tick this</strong> if the workshop spans both slots without a break '
            . '(participant is enrolled in both automatically).<br>'
            . '<strong>Leave unticked</strong> if the same workshop is offered independently '
            . 'in each slot — the participant attends only the slot shown in their CSV column.'
            . '</p></td></tr>';

        // Type

        // Type
        $types = ['workshop', 'meal', 'keynote', 'break', 'social', 'other'];
        $cur_type = $v->type ?? 'workshop';
        echo '<tr><th><label for="es_type">Type</label></th><td><select id="es_type" name="type">';
        foreach ($types as $t) {
            echo '<option value="' . esc_attr($t) . '"' . selected($cur_type, $t, false) . '>' . ucfirst($t) . '</option>';
        }
        echo '</select></td></tr>';

        // Location
        echo '<tr><th><label for="es_location">Location</label></th>';
        echo '<td><input type="text" id="es_location" name="location" class="regular-text" '
            . 'value="' . esc_attr($v->location ?? '') . '">'
            . '<p class="description">E.g. "Room 1", "Main Hall", "Cafeteria"</p></td></tr>';

        // Description
        echo '<tr><th><label for="es_description">Description</label></th>';
        echo '<td><textarea id="es_description" name="description" class="large-text" rows="3">'
            . esc_textarea($v->description ?? '') . '</textarea>'
            . '<p class="description">Optional. Shown on the participant\'s schedule card.</p></td></tr>';

        echo '</table>';

        if ($editing_event) {
            echo '<p><button type="submit" class="button button-primary">Update Event</button> ';
            echo '<a href="' . esc_url(admin_url('tools.php?page=event-schedules')) . '" class="button">Cancel</a></p>';
        } else {
            echo '<p><button type="submit" class="button button-primary">Add Event</button></p>';
        }
        echo '</form>';

        // Events table
        $events = $wpdb->get_results("SELECT * FROM $et ORDER BY for_everyone DESC, display_name ASC");
        if ($events) {
            $es_table = self::table_name(self::TABLE_EVENT_SLOTS);
            $event_ids = array_column($events, 'id');
            $event_slots_map = [];
            if ($event_ids) {
                $id_list = implode(',', array_map('intval', $event_ids));
                $slot_links = $wpdb->get_results(
                    "SELECT es.event_id, s.name FROM $es_table es
           INNER JOIN $sl s ON s.id = es.slot_id
           WHERE es.event_id IN ($id_list) ORDER BY s.slot_date ASC, s.start_time ASC"
                );
                foreach ($slot_links as $link)
                    $event_slots_map[$link->event_id][] = $link->name;
            }

            echo '<h3 style="margin-top:28px">All Events (' . count($events) . ')</h3>';
            echo '<table class="es-table"><thead><tr>'
                . '<th>Display Name</th><th>Type</th><th>Visibility</th><th>Time / Slots</th>'
                . '<th>Location</th><th>Actions</th>'
                . '</tr></thead><tbody>';

            foreach ($events as $ev) {
                $row_cls = ($editing_event && $editing_event->id == $ev->id) ? ' class="es-edit-row"' : '';
                echo '<tr' . $row_cls . '>';
                echo '<td><strong>' . esc_html($ev->display_name) . '</strong>';
                if ($ev->description)
                    echo '<br><span style="color:#888;font-size:11px">' . esc_html(wp_trim_words($ev->description, 8)) . '</span>';
                echo '</td>';
                echo '<td>' . esc_html($ev->type) . '</td>';
                echo '<td>' . ($ev->for_everyone
                    ? '<span class="es-badge es-badge-green">Everyone</span>'
                    : '<span class="es-badge es-badge-blue">Enrolled</span>') . '</td>';

                // Show either own date/time (for everyone) or slot badges (workshop)
                if ($ev->for_everyone && $ev->event_date) {
                    $t = $ev->event_date;
                    if ($ev->start_time)
                        $t .= ' ' . substr($ev->start_time, 0, 5);
                    if ($ev->end_time)
                        $t .= '–' . substr($ev->end_time, 0, 5);
                    echo '<td style="font-size:12px">' . esc_html($t) . '</td>';
                } else {
                    $slot_names = $event_slots_map[$ev->id] ?? [];
                    $double_badge = $ev->double_slot
                        ? ' <span class="es-badge" style="background:#8b5cf6;color:#fff">Double</span>'
                        : '';
                    echo '<td>' . ($slot_names
                        ? implode(' ', array_map(fn($n) => '<span class="es-badge es-badge-grey">' . esc_html($n) . '</span>', $slot_names)) . $double_badge
                        : '<span style="color:#999">—</span>') . '</td>';
                }

                echo '<td>' . esc_html($ev->location ?: '—') . '</td>';
                $edit_url = esc_url(add_query_arg(['page' => 'event-schedules', 'edit_event' => $ev->id], admin_url('tools.php')));
                echo '<td style="white-space:nowrap"><a href="' . $edit_url . '" class="button button-small">Edit</a> ';
                echo '<form method="post" action="' . $form_url . '" style="display:inline" onsubmit="return confirm(\'Delete this event?\');">';
                echo '<input type="hidden" name="action" value="es_delete_event">';
                echo '<input type="hidden" name="event_id" value="' . (int) $ev->id . '">';
                wp_nonce_field('es_delete_event', 'es_del_nonce_' . $ev->id);
                echo '<button type="submit" class="button button-small" style="color:#d63638">Delete</button></form></td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p style="color:#666;margin-top:16px">No events yet.</p>';
        }
        echo '</div>';

        // Section 4: Import participants
        echo '<div class="es-section"><h2>Import Participants</h2>';
        echo '<p>All rows with a valid email are imported. Re-uploading is safe (upsert by email).</p>';
        echo '<p><strong>Required columns (prefix matched):</strong> <code>First Name</code>, <code>Last Name</code>, <code>E-Mail</code></p>';
        echo '<form method="post" action="' . $form_url . '" enctype="multipart/form-data">';
        echo '<input type="hidden" name="action" value="es_import_participants">';
        wp_nonce_field('es_import_participants', 'es_nonce');
        echo '<table class="form-table"><tr><th>Participants CSV</th><td><input type="file" name="participants_csv" accept=".csv" required></td></tr></table>';
        echo '<p><button type="submit" class="button button-primary">Import Participants</button></p></form></div>';



        // Section 5: Import enrollments from CSV
        echo '<div class="es-section">';
        echo '<h2>Import Enrollments (Buchungen CSV)</h2>';
        echo '<p>Upload the Buchungen CSV to enroll participants in workshops based on their selections. '
            . 'The plugin matches the workshop names in the CSV against the "CSV name" field of events to determine enrollments. '
            . 'First workshop column = first slot, second = second slot. Import participants first.</p>';
        echo '<p><strong>Required columns (prefix matched):</strong> <code>E-Mail</code>, <code>Status</code>, <code>Please select the workshop</code> (×2)</p>';

        echo '<form method="post" action="' . $form_url . '" enctype="multipart/form-data">';
        echo '<input type="hidden" name="action" value="es_import_buchungen">';
        wp_nonce_field('es_import_buchungen', 'es_nonce');
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th>Buchungen CSV</th>'
            . '<td><input type="file" name="buchungen_csv" accept=".csv" required></td></tr>';
        echo '</table>';
        echo '<p><button type="submit" class="button button-primary">Import Enrollments</button></p>';
        echo '</form>';
        echo '</div>';

        // Section 6: Reset everything
        echo '<div class="es-section es-danger">';
        echo '<h2>⚠️ Reset Database</h2>';
        echo '<p>Permanently deletes <strong>all participants, events, and enrollments</strong>. '
            . 'This cannot be undone.</p>';
        echo '<form method="post" action="' . $form_url . '" '
            . 'onsubmit="return confirm(\'Are you sure? This permanently deletes everything.\');">';
        echo '<input type="hidden" name="action" value="es_reset">';
        wp_nonce_field('es_reset', 'es_nonce');
        echo '<button type="submit" class="button" '
            . 'style="background:#d63638;color:#fff;border-color:#b32d2e;">'
            . 'Reset Everything</button>';
        echo '</form>';
        echo '</div>';

        echo '</div>'; // end .wrap
    }



    // Handler: save settings
    public static function handle_save_settings()
    {
        if (!current_user_can('manage_options'))
            wp_die('Not allowed');
        if (!wp_verify_nonce($_POST['es_nonce'] ?? '', 'es_save_settings'))
            wp_die('Nonce failed');

        // esc_url_raw() cleans a URL for storage (esc_url() is for output/display)
        $url = esc_url_raw(wp_unslash($_POST['schedule_page_url'] ?? ''));
        update_option('es_schedule_page_url', $url);

        self::set_flash([['type' => 'success', 'msg' => 'Settings saved.']]);
        self::redirect_admin();
    }


    // Hanlder: Save slot
    public static function handle_save_slot()
    {
        if (!current_user_can('manage_options'))
            wp_die('Not allowed');
        if (!wp_verify_nonce($_POST['es_nonce'] ?? '', 'es_save_slot'))
            wp_die('Nonce failed');

        global $wpdb;
        $sl = self::table_name(self::TABLE_SLOTS);

        $name = sanitize_text_field(wp_unslash($_POST['slot_name'] ?? ''));
        $slot_date = sanitize_text_field($_POST['slot_date'] ?? '');
        $start_time = sanitize_text_field($_POST['start_time'] ?? '');
        $end_time = sanitize_text_field($_POST['end_time'] ?? '');


        if (!$name) {
            self::set_flash([['type' => 'error', 'msg' => 'Slot name is required.']]);
            self::redirect_admin();
        }

        if ($slot_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $slot_date))
            $slot_date = '';
        if ($start_time && !preg_match('/^\d{2}:\d{2}$/', $start_time))
            $start_time = '';
        if ($end_time && !preg_match('/^\d{2}:\d{2}$/', $end_time))
            $end_time = '';

        $data = [
            'name' => $name,
            'slot_date' => $slot_date ?: null,
            'start_time' => $start_time ?: null,
            'end_time' => $end_time ?: null,
        ];

        $slot_id = (int) ($_POST['slot_id'] ?? 0);
        if ($slot_id) {
            $wpdb->update($sl, $data, ['id' => $slot_id]);
            self::set_flash([['type' => 'success', 'msg' => 'Slot updated.']]);
        } else {
            $wpdb->insert($sl, $data);
            self::set_flash([['type' => 'success', 'msg' => 'Slot added.']]);
        }
        self::redirect_admin();
    }

    // Handler: delete slot
    public static function handle_delete_slot()
    {
        if (!current_user_can('manage_options'))
            wp_die('Not allowed');
        $slot_id = (int) ($_POST['slot_id'] ?? 0);
        if (!$slot_id)
            self::redirect_admin();
        if (!wp_verify_nonce($_POST['es_del_slot_nonce_' . $slot_id] ?? '', 'es_delete_slot'))
            wp_die('Nonce failed');

        global $wpdb;
        $wpdb->delete(self::table_name(self::TABLE_ENROLLMENTS), ['slot_id' => $slot_id]);
        $wpdb->delete(self::table_name(self::TABLE_EVENT_SLOTS), ['slot_id' => $slot_id]);
        $wpdb->delete(self::table_name(self::TABLE_SLOTS), ['id' => $slot_id]);

        self::set_flash([['type' => 'success', 'msg' => 'Slot deleted.']]);
        self::redirect_admin();
    }

    // Handler: save event
    public static function handle_save_event()
    {
        if (!current_user_can('manage_options'))
            wp_die('Not allowed');
        if (!wp_verify_nonce($_POST['es_nonce'] ?? '', 'es_save_event'))
            wp_die('Nonce failed');

        global $wpdb;
        $et = self::table_name(self::TABLE_EVENTS);
        $es = self::table_name(self::TABLE_EVENT_SLOTS);

        $display_name = sanitize_text_field(wp_unslash($_POST['display_name'] ?? ''));
        $csv_name = sanitize_text_field(wp_unslash($_POST['csv_name'] ?? ''));
        $location = sanitize_text_field(wp_unslash($_POST['location'] ?? ''));
        // sanitize_textarea_field: like sanitize_text_field but preserves newlines
        $description = sanitize_textarea_field(wp_unslash($_POST['description'] ?? ''));
        $type = sanitize_key($_POST['type'] ?? 'workshop');
        $for_everyone = !empty($_POST['for_everyone']) ? 1 : 0;
        $double_slot = !empty($_POST['double_slot']) ? 1 : 0;
        $slot_ids = isset($_POST['slots']) ? array_map('intval', (array) $_POST['slots']) : [];

        // Date and time, only meaningful when for_everyone = 1
        $event_date = sanitize_text_field($_POST['event_date'] ?? '');
        $start_time = sanitize_text_field($_POST['start_time'] ?? '');
        $end_time = sanitize_text_field($_POST['end_time'] ?? '');
        if ($event_date && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $event_date))
            $event_date = '';
        if ($start_time && !preg_match('/^\d{2}:\d{2}$/', $start_time))
            $start_time = '';
        if ($end_time && !preg_match('/^\d{2}:\d{2}$/', $end_time))
            $end_time = '';

        if (!$display_name) {
            self::set_flash([['type' => 'error', 'msg' => 'Display name is required.']]);
            self::redirect_admin();
        }

        $data = [
            'display_name' => $display_name,
            'csv_name' => $csv_name ?: null,
            'location' => $location ?: null,
            'description' => $description ?: null,
            'event_date' => ($for_everyone && $event_date) ? $event_date : null,
            'start_time' => ($for_everyone && $start_time) ? $start_time : null,
            'end_time' => ($for_everyone && $end_time) ? $end_time : null,
            'type' => $type,
            'for_everyone' => $for_everyone,
            'double_slot' => $for_everyone ? 0 : $double_slot,
        ];

        $event_id = (int) ($_POST['event_id'] ?? 0);
        if ($event_id) {
            $wpdb->update($et, $data, ['id' => $event_id]);
        } else {
            $wpdb->insert($et, $data);
            $event_id = $wpdb->insert_id;
        }

        // Save slot assignments (only relevant for workshops, not for-everyone events)
        $wpdb->delete($es, ['event_id' => $event_id]);
        if (!$for_everyone) {
            foreach ($slot_ids as $sid) {
                if ($sid > 0)
                    $wpdb->insert($es, ['event_id' => $event_id, 'slot_id' => $sid]);
            }
        }

        self::set_flash([['type' => 'success', 'msg' => 'Event saved.']]);
        self::redirect_admin();
    }


    //  Handler: delete an event and all its enrollments
    public static function handle_delete_event()
    {
        if (!current_user_can('manage_options'))
            wp_die('Not allowed');

        // The nonce field name includes the event ID to prevent one delete form
        // from being used to delete a different event
        $event_id = (int) ($_POST['event_id'] ?? 0);
        if (!$event_id)
            self::redirect_admin();

        // Verify nonce, name must match what wp_nonce_field() used when rendering
        if (!wp_verify_nonce($_POST['es_del_nonce_' . $event_id] ?? '', 'es_delete_event')) {
            wp_die('Nonce failed');
        }

        global $wpdb;
        // Delete enrollments first (they reference the event ID)
        $wpdb->delete(self::table_name(self::TABLE_ENROLLMENTS), ['event_id' => $event_id]);
        $wpdb->delete(self::table_name(self::TABLE_EVENTS), ['id' => $event_id]);
        $wpdb->delete(self::table_name(self::TABLE_EVENT_SLOTS), ['event_id' => $event_id]);
        self::set_flash([['type' => 'success', 'msg' => 'Event and its enrollments deleted.']]);
        self::redirect_admin();
    }

    // Handler: Import participants CSV
    public static function handle_import_participants()
    {
        if (!current_user_can('manage_options'))
            wp_die('Not allowed');
        if (!wp_verify_nonce($_POST['es_nonce'] ?? '', 'es_import_participants'))
            wp_die('Nonce failed');

        $file = $_FILES['participants_csv'] ?? null;
        if (empty($file['tmp_name'])) {
            self::set_flash([['type' => 'error', 'msg' => 'No file uploaded.']]);
            self::redirect_admin();
        }

        global $wpdb;
        $pt = self::table_name(self::TABLE_PARTICIPANTS);
        [$headers, $rows] = self::read_csv($file['tmp_name']);

        $email_col = self::find_col_prefix($headers, 'E-Mail');
        $first_col = self::find_col_prefix($headers, 'First Name');
        $last_col = self::find_col_prefix($headers, 'Last Name');

        $missing = [];
        if (!$email_col)
            $missing[] = 'E-Mail';
        if (!$first_col)
            $missing[] = 'First Name';
        if (!$last_col)
            $missing[] = 'Last Name';
        if ($missing) {
            self::set_flash([['type' => 'error', 'msg' => 'Could not find columns: ' . implode(', ', $missing)]]);
            self::redirect_admin();
        }

        $imported = 0;
        $skip_no_email = 0;
        $wpdb->query('START TRANSACTION');
        try {
            foreach ($rows as $rd) {
                $r = $rd['assoc'];
                $email = strtolower(trim($r[$email_col] ?? ''));
                if (!$email || !str_contains($email, '@')) {
                    $skip_no_email++;
                    continue;
                }
                $first = sanitize_text_field(trim($r[$first_col] ?? ''));
                $last = sanitize_text_field(trim($r[$last_col] ?? ''));
                $exists = $wpdb->get_var($wpdb->prepare("SELECT email FROM $pt WHERE email=%s", $email));
                if ($exists) {
                    $wpdb->update($pt, ['first_name' => $first, 'last_name' => $last], ['email' => $email]);
                } else {
                    $wpdb->insert($pt, ['email' => $email, 'first_name' => $first, 'last_name' => $last, 'token' => bin2hex(random_bytes(16))]);
                }
                $imported++;
            }
            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            self::set_flash([['type' => 'error', 'msg' => 'Import failed: ' . esc_html($e->getMessage())]]);
            self::redirect_admin();
        }

        $msgs = [['type' => 'success', 'msg' => "<strong>$imported</strong> participants imported/updated."]];
        if ($skip_no_email > 0)
            $msgs[] = ['type' => 'warning', 'msg' => "<strong>$skip_no_email</strong> rows skipped — missing/invalid email."];
        self::set_flash($msgs);
        self::redirect_admin();
    }

    // Handler: Import enrollment from Buchungen CSV
    public static function handle_import_buchungen()
    {
        if (!current_user_can('manage_options'))
            wp_die('Not allowed');
        if (!wp_verify_nonce($_POST['es_nonce'] ?? '', 'es_import_buchungen'))
            wp_die('Nonce failed');

        $file = $_FILES['buchungen_csv'] ?? null;
        if (empty($file['tmp_name'])) {
            self::set_flash([['type' => 'error', 'msg' => 'No file uploaded.']]);
            self::redirect_admin();
        }

        global $wpdb;
        $pt = self::table_name(self::TABLE_PARTICIPANTS);
        $et = self::table_name(self::TABLE_EVENTS);
        $es = self::table_name(self::TABLE_EVENT_SLOTS);
        $nt = self::table_name(self::TABLE_ENROLLMENTS);
        $sl = self::table_name(self::TABLE_SLOTS);

        [$headers, $rows] = self::read_csv($file['tmp_name']);

        // Find E-Mail column
        $email_col = self::find_col_prefix($headers, 'E-Mail');
        $status_col = self::find_col_prefix($headers, 'Status');
        if (!$email_col || !$status_col) {
            self::set_flash([['type' => 'error', 'msg' => 'Could not find E-Mail or Status column.']]);
            self::redirect_admin();
        }

        $workshop_cols = self::find_all_cols_prefix($headers, 'Please select the workshop');
        if (empty($workshop_cols)) {
            self::set_flash([['type' => 'error', 'msg' => 'Could not find workshop columns.']]);
            self::redirect_admin();
        }

        $ordered_slots = array_values($wpdb->get_results("SELECT * FROM $sl ORDER BY slot_date ASC, start_time ASC"));
        $col_to_slot_id = [];
        foreach (array_keys($workshop_cols) as $i => $col_pos) {
            if (isset($ordered_slots[$i]))
                $col_to_slot_id[$col_pos] = (int) $ordered_slots[$i]->id;
        }

        $all_events = $wpdb->get_results("SELECT id, csv_name, double_slot FROM $et WHERE csv_name IS NOT NULL AND csv_name != ''");
        $csv_to_event = [];
        foreach ($all_events as $ev) {
            $ev->slot_ids = array_column(
                $wpdb->get_results($wpdb->prepare("SELECT slot_id FROM $es WHERE event_id=%d", $ev->id)),
                'slot_id'
            );
            $csv_to_event[strtolower(trim($ev->csv_name))] = $ev;
        }

        $enrolled = 0;
        $skip_status = 0;
        $skip_no_person = 0;
        $unmatched = [];
        $wpdb->query('START TRANSACTION');
        try {
            foreach ($rows as $rd) {
                $r = $rd['assoc'];
                $indexed = $rd['indexed'];
                if (strtolower(trim($r[$status_col] ?? '')) !== 'bestätigt') {
                    $skip_status++;
                    continue;
                }
                $email = strtolower(trim($r[$email_col] ?? ''));
                if (!$email || !str_contains($email, '@')) {
                    continue;
                }
                $p_email = $wpdb->get_var($wpdb->prepare("SELECT email FROM $pt WHERE email=%s", $email));
                if (!$p_email) {
                    $skip_no_person++;
                    continue;
                }

                $enrolled_ids = [];
                foreach ($col_to_slot_id as $col_pos => $csv_slot_id) {
                    $wname = trim($indexed[$col_pos] ?? '');
                    if ($wname === '' || strtolower($wname) === 'no workshop/ project fair')
                        continue;
                    $event = $csv_to_event[strtolower($wname)] ?? null;
                    if (!$event) {
                        $unmatched[$wname] = ($unmatched[$wname] ?? 0) + 1;
                        continue;
                    }
                    if (in_array($event->id, $enrolled_ids))
                        continue;

                    if ($event->double_slot) {
                        // Spans all slots continuously — enroll in every slot
                        foreach ($event->slot_ids as $sid) {
                            $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $nt (participant_email,event_id,slot_id) VALUES(%s,%d,%d)", $p_email, $event->id, (int) $sid));
                            $enrolled++;
                        }
                    } else {
                        // Single or independent slot — enroll only in the slot from the CSV column
                        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $nt (participant_email,event_id,slot_id) VALUES(%s,%d,%d)", $p_email, $event->id, $csv_slot_id));
                        $enrolled++;
                    }
                    $enrolled_ids[] = $event->id;
                }
            }
            $wpdb->query('COMMIT');
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            self::set_flash([['type' => 'error', 'msg' => 'Import failed: ' . esc_html($e->getMessage())]]);
            self::redirect_admin();
        }

        $msgs = [['type' => 'success', 'msg' => "<strong>$enrolled</strong> enrollments added."]];
        if ($skip_status > 0)
            $msgs[] = ['type' => 'info', 'msg' => "<strong>$skip_status</strong> rows skipped (not bestätigt)."];
        if ($skip_no_person > 0)
            $msgs[] = ['type' => 'warning', 'msg' => "<strong>$skip_no_person</strong> rows skipped — email not in participants DB."];
        if (!empty($unmatched)) {
            $list = implode('', array_map(fn($n, $c) => '<li>' . esc_html($n) . ' (' . $c . ' person(s))</li>', array_keys($unmatched), $unmatched));
            $msgs[] = ['type' => 'warning', 'msg' => "Unmatched workshop names:<ul>$list</ul>Check CSV name fields on your events."];
        }
        self::set_flash($msgs);
        self::redirect_admin();
    }

    // Handler: Reset => wipe all tables
    public static function handle_reset()
    {
        if (!current_user_can('manage_options'))
            wp_die('Not allowed');
        if (!wp_verify_nonce($_POST['es_nonce'] ?? '', 'es_reset'))
            wp_die('Nonce failed');

        global $wpdb;
        // TRUNCATE removes all rows and resets the AUTO_INCREMENT counter.
        // Delete enrollments first (it has FK-style references to the other tables).
        $wpdb->query('TRUNCATE TABLE ' . self::table_name(self::TABLE_ENROLLMENTS));
        $wpdb->query('TRUNCATE TABLE ' . self::table_name(self::TABLE_EVENT_SLOTS));
        $wpdb->query('TRUNCATE TABLE ' . self::table_name(self::TABLE_PARTICIPANTS));
        $wpdb->query('TRUNCATE TABLE ' . self::table_name(self::TABLE_EVENTS));
        $wpdb->query('TRUNCATE TABLE ' . self::table_name(self::TABLE_SLOTS));

        self::set_flash([['type' => 'success', 'msg' => 'All data has been reset. The database is now empty.']]);
        self::redirect_admin();
    }

    // CSV Helper: read a CSV file intoa n array of row objects
    private static function read_csv($path)
    {
        $fh = fopen($path, 'r');
        if (!$fh)
            return [[], []];

        // Read first line as raw text to detect delimiter
        $first_line = fgets($fh);
        if (!$first_line) {
            fclose($fh);
            return [[], []];
        }
        // Count commas vs semicolons, whichever appears more is the delimiter
        $delimiter = substr_count($first_line, ';') > substr_count($first_line, ',') ? ';' : ',';
        // Rewind and re-read properly with the detected delimiter
        rewind($fh);
        $headers = fgetcsv($fh, 0, $delimiter);
        if (!$headers) {
            fclose($fh);
            return [[], []];
        }
        $headers = array_map('trim', $headers);

        $rows = [];
        while (($line = fgetcsv($fh, 0, $delimiter)) !== false) {
            // Build the assoc row: header[i] -> line[i]
            $assoc = [];
            foreach ($headers as $i => $h) {
                $assoc[$h] = trim($line[$i] ?? '');
            }
            // Skip completely blank lines
            if (!array_filter($assoc, fn($v) => $v !== ''))
                continue;

            $rows[] = ['assoc' => $assoc, 'indexed' => $line];
        }

        fclose($fh);
        return [$headers, $rows];
    }



    // Query vars
    public static function register_query_vars()
    {
        add_filter('query_vars', function ($vars) {
            $vars[] = 'event_ics';
            return $vars; // filters MUST return the value
        });
    }

    // shortcode: [event_search]
    // renders an email search form. On submit:
    // 1. looks up the participant by email in the DB
    // 2. Redirects them to the schedule page with their token in the URL
    // the shortcode can be placed on the "Find My Schedule" page
    public static function shortcode_search()
    {
        global $wpdb;
        $pt = self::table_name(self::TABLE_PARTICIPANTS);
        $error = '';

        // The form submits via GET so we can read the value from $_GET
        if (!empty($_GET['es_search'])) {
            $email = strtolower(trim(wp_unslash($_GET['es_email'] ?? '')));

            if (!$email || !str_contains($email, '@')) {
                $error = 'Please enter a valid email address.';
            } else {
                // Look up participant token by email
                $token = $wpdb->get_var(
                    $wpdb->prepare("SELECT token FROM $pt WHERE email = %s", $email)
                );

                if ($token) {
                    // Redirect to the schedule page with the token in the URL
                    $base_url = get_option('es_schedule_page_url', home_url('/my-schedule/'));
                    $redirect_url = add_query_arg('t', $token, $base_url);
                    wp_redirect($redirect_url);
                    exit;
                } else {
                    $error = 'No schedule found for that email address. '
                        . 'Please check the spelling and try again, or contact us.';
                }
            }
        }


        // Render the search form
        $out = '<div class="es-search">';

        if ($error) {
            $out .= '<div style="padding:12px 16px; background:#fce8e8; border-left:4px solid #d63638; '
                . 'margin-bottom:16px; border-radius:2px;">' . esc_html($error) . '</div>';
        }

        // The form uses GET (not POST) so the email appears in the URL while searching.
        // action="" means the form submits to the current page.
        $out .= '<form method="get" action="" style="max-width:460px">';
        $out .= '<input type="hidden" name="es_search" value="1">';
        $out .= '<p>';
        $out .= '<label for="es_email_input" style="display:block; font-weight:600; margin-bottom:6px;">'
            . 'Enter your email address to find your schedule:</label>';
        $out .= '<input type="email" id="es_email_input" name="es_email" required '
            . 'placeholder="your@email.com" '
            . 'value="' . esc_attr($_GET['es_email'] ?? '') . '" '
            . 'style="width:100%; padding:10px; font-size:15px; border:1px solid ' . colorMain . '; '
            . 'border-radius:4px; box-sizing:border-box;">';
        $out .= '</p>';
        $out .= '<button type="submit" style="padding:10px 24px; font-size:15px; cursor:pointer; '
            . 'background:' . colorMain . '; color:' . colorTextBG . '; border:none; border-radius:4px;">'
            . 'Find My Schedule</button>';
        $out .= '</form>';
        $out .= '</div>';

        return $out;
    }

    // shortcode: [event_schedule]
    // Reads ?t=TOKEN from the URL and displays the participant's full schedule.
    // Shows all "for everyone" events plus their personally enrolled sessions.
    //
    // Put this shortcode on a page called something like "My Schedule".
    public static function shortcode_schedule()
    {
        // sanitize_key: lowercase alphanumeric + hyphens/underscores, safe for our hex token
        $token = isset($_GET['t']) ? sanitize_key(wp_unslash($_GET['t'])) : '';

        if (!$token) {
            return '<p>No schedule token provided. Please <a href="' . esc_url(home_url('/find-my-schedule/')) . '">search for your schedule</a>.</p>';
        }

        global $wpdb;
        $pt = self::table_name(self::TABLE_PARTICIPANTS);

        // Look up participant by their token
        $p = $wpdb->get_row($wpdb->prepare("SELECT * FROM $pt WHERE token = %s", $token));
        if (!$p) {
            return '<p>Schedule not found. Please check your link or <a href="' . esc_url(home_url('/find-my-schedule/')) . '">search again</a>.</p>';
        }

        $sessions = self::get_sessions_for_participant($p->email);

        // Build the ICS download link
        $ics_url = add_query_arg(['event_ics' => '1', 't' => $token], home_url('/'));

        // Group sessions by date 
        $by_date = [];
        foreach ($sessions as $s) {
            $by_date[$s->date ?: 'no-date'][] = $s;
        }
        // Sort days chronologically
        ksort($by_date);

        // CSS (inline so no separate file needed) 
        $css = '
    <style>
    .es-schedule { font-family: inherit; }
    .es-schedule h2 { margin-bottom: 4px; }
    .es-schedule .es-meta { color:#666; margin-top:0; font-size:14px; }
    .es-cal { display:grid; gap:16px; margin-top:24px; }
    /* Desktop: one column per day, side by side */
    @media (min-width:640px) {
      .es-cal { grid-template-columns: repeat(' . max(1, count($by_date)) . ', 1fr); }
    }
    .es-day { min-width:0; }
    .es-day-header { background:' . colorMain . '; color:' . colorTextBG . '; padding:10px 14px; border-radius:6px 6px 0 0;
      font-weight:700; font-size:15px; }
    .es-day-body { border:1px solid ' . colorLight . '; border-top:none; border-radius:0 0 6px 6px; overflow:hidden; }
    .es-card { padding:14px; border-bottom:1px solid ' . colorLight2 . '; background:' . colorTextBG . '; }
    .es-card:last-child { border-bottom:none; }
    .es-card:hover { background:' . color_hover . '; }
    .es-card-time { font-weight:700; color:' . colorMain . '; font-size:13px; margin-bottom:4px; }
    .es-card-name { font-size:15px; font-weight:600; margin-bottom:4px; line-height:1.3; }
    .es-card-slot { font-size:11px; color:#888; margin-left:4px; }
    .es-card-location { font-size:13px; color:#555; margin-bottom:4px; }
    .es-card-location::before { content:"📍 "; }
    .es-card-desc { font-size:13px; color:#444; margin-top:6px; line-height:1.5; }
    .es-card-badge { display:inline-block; font-size:11px; font-weight:600;
      background:' . color_workshopType . '; color:' . colorMain . '; padding:2px 7px; border-radius:10px; margin-top:4px; }
    .es-no-date { border:1px solid ' . color_workshopType . '; border-radius:6px; overflow:hidden; }
    /* Mobile: flat list, full width */
    @media (max-width:639px) {
      .es-cal { grid-template-columns:1fr; }
      .es-day-header { border-radius:6px 6px 0 0; }
    }
    </style>';

        $out = $css;
        $out .= '<div class="es-schedule">';
        $out .= '<h2 style="margin-bottom:4px">' . esc_html($p->first_name . ' ' . $p->last_name) . '</h2>';
        $out .= '<p class="es-meta">' . esc_html($p->email) . '</p>';
        $out .= '<p><a href="' . esc_url($ics_url) . '" style="display:inline-block;padding:8px 18px;'
            . 'background:' . colorMain . ';color:' . colorTextBG . ';border-radius:4px;text-decoration:none;font-size:14px;">'
            . '&#128197; Add to Calendar (.ics)</a></p>';

        if (empty($sessions)) {
            return $out . '<p>No sessions scheduled yet. Check back later.</p></div>';
        }

        $out .= '<div class="es-cal">';

        foreach ($by_date as $date_key => $day_sessions) {

            // Day column heading
            if ($date_key === 'no-date') {
                $heading = 'Date TBC';
            } else {
                $heading = date_i18n('l, j F Y', strtotime($date_key));
            }

            $out .= '<div class="es-day">';
            $out .= '<div class="es-day-header">' . esc_html($heading) . '</div>';
            $out .= '<div class="es-day-body">';

            foreach ($day_sessions as $s) {

                // Format the time range
                $time = '';
                if ($s->start_time) {
                    $time = substr($s->start_time, 0, 5);
                    if ($s->end_time)
                        $time .= '–' . substr($s->end_time, 0, 5);
                }

                $out .= '<div class="es-card">';

                // Time
                if ($time) {
                    $out .= '<div class="es-card-time">' . esc_html($time) . '</div>';
                }

                // Name + optional slot label
                $out .= '<div class="es-card-name">' . esc_html($s->display_name);
                if ($s->slot_name) {
                    $out .= '<span class="es-card-slot">(' . esc_html($s->slot_name) . ')</span>';
                }
                $out .= '</div>';

                // Location
                if ($s->location) {
                    $out .= '<div class="es-card-location">' . esc_html($s->location) . '</div>';
                }

                // Description
                if ($s->description) {
                    // nl2br converts newlines to <br> tags so multi-line descriptions render correctly
                    $out .= '<div class="es-card-desc">' . nl2br(esc_html($s->description)) . '</div>';
                }

                // Type badge
                $out .= '<span class="es-card-badge">' . esc_html(ucfirst($s->type)) . '</span>';

                $out .= '</div>'; // end .es-card
            }

            $out .= '</div>'; // end .es-day-body
            $out .= '</div>'; // end .es-day
        }

        $out .= '</div>'; // end .es-cal
        $out .= '</div>'; // end .es-schedule
        return $out;
    }

    // shared: fetch all sessions a participant should see
    // Combines:
    //   a) Events marked for_everyone = 1
    //   b) Events the participant is enrolled in (via enrollments table)
    //
    // Deduplicates (in case a for_everyone event also has an enrollment row)
    // and sorts by date then start time.
    private static function get_sessions_for_participant(string $email): array
    {
        global $wpdb;
        $et = self::table_name(self::TABLE_EVENTS);
        $sl = self::table_name(self::TABLE_SLOTS);
        $es = self::table_name(self::TABLE_EVENT_SLOTS);
        $nt = self::table_name(self::TABLE_ENROLLMENTS);

        // "For everyone" events — use the event's OWN date and time
        // slot_name is NULL since these events aren't tied to a slot
        $everyone = $wpdb->get_results(
            "SELECT e.id AS event_id, NULL AS slot_id, e.display_name, e.location,
              e.description, e.type, e.for_everyone,
              e.event_date AS date, e.start_time, e.end_time,
              NULL AS slot_name
       FROM $et e
       WHERE e.for_everyone = 1
       ORDER BY e.event_date ASC, e.start_time ASC"
        );

        // Workshop enrollments — use the SLOT's date and time
        // slot_name is included so the schedule can show e.g. "(Slot 1)"
        $personal = $wpdb->get_results($wpdb->prepare(
            "SELECT e.id AS event_id, s.id AS slot_id, e.display_name, e.location,
              e.description, e.type, e.for_everyone,
              s.slot_date AS date, s.start_time, s.end_time,
              s.name AS slot_name
       FROM $et e
       INNER JOIN $nt n  ON n.event_id = e.id
       INNER JOIN $sl s  ON s.id = n.slot_id
       WHERE n.participant_email = %s
       ORDER BY s.slot_date ASC, s.start_time ASC",
            $email
        ));

        // Deduplicate: for-everyone events keyed by event_id only (no slot),
        // workshop events keyed by event_id + slot_id
        $by_key = [];
        foreach ($everyone as $s)
            $by_key['e' . $s->event_id] = $s;
        foreach ($personal as $s)
            $by_key['e' . $s->event_id . '_s' . $s->slot_id] = $s;

        $all = array_values($by_key);

        // Sort by date then time
        usort($all, function ($a, $b) {
            $d = strcmp($a->date ?? '', $b->date ?? '');
            return $d !== 0 ? $d : strcmp($a->start_time ?? '', $b->start_time ?? '');
        });

        return $all;
    }

    // ICS download endpoint
    // outputs an iCalendar file and exists
    public static function maybe_serve_ics()
    {
        if (!get_query_var('event_ics'))
            return;

        $token = isset($_GET['t']) ? sanitize_key(wp_unslash($_GET['t'])) : '';
        if (!$token) {
            status_header(400);
            echo 'Missing token.';
            exit;
        }

        global $wpdb;
        $pt = self::table_name(self::TABLE_PARTICIPANTS);
        $p = $wpdb->get_row($wpdb->prepare("SELECT * FROM $pt WHERE token=%s", $token));
        if (!$p) {
            status_header(404);
            echo 'Not found.';
            exit;
        }

        $sessions = self::get_sessions_for_participant($p->email);
        $site_name = get_bloginfo('name');
        $tz = wp_timezone();
        $now_utc = gmdate('Ymd\THis\Z');

        $lines = [];
        $lines[] = 'BEGIN:VCALENDAR';
        $lines[] = 'VERSION:2.0';
        $lines[] = 'PRODID:-//' . self::ics_escape($site_name) . '//Event Schedule//EN';
        $lines[] = 'CALSCALE:GREGORIAN';
        $lines[] = 'METHOD:PUBLISH';

        foreach ($sessions as $s) {
            if (!$s->date || !$s->start_time)
                continue;

            // For for-everyone events slot_id is NULL, so we use 0 in the UID
            $slot_part = $s->slot_id ? $s->slot_id : '0';
            $uid = self::ics_escape($p->token . '-' . $s->event_id . '-' . $slot_part . '@' . parse_url(home_url(), PHP_URL_HOST));

            $start_dt = new DateTime($s->date . ' ' . $s->start_time, $tz);
            $end_dt = $s->end_time
                ? new DateTime($s->date . ' ' . $s->end_time, $tz)
                : (clone $start_dt)->modify('+1 hour');

            $start_dt->setTimezone(new DateTimeZone('UTC'));
            $end_dt->setTimezone(new DateTimeZone('UTC'));

            // Append slot name to calendar entry title for workshops (e.g. "Trauma Artistry (Slot 1)")
            $summary = $s->display_name . ($s->slot_name ? ' (' . $s->slot_name . ')' : '');

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $uid;
            $lines[] = 'DTSTAMP:' . $now_utc;
            $lines[] = 'DTSTART:' . $start_dt->format('Ymd\THis\Z');
            $lines[] = 'DTEND:' . $end_dt->format('Ymd\THis\Z');
            $lines[] = 'SUMMARY:' . self::ics_escape($summary);
            if ($s->location)
                $lines[] = 'LOCATION:' . self::ics_escape($s->location);
            if ($s->description)
                $lines[] = 'DESCRIPTION:' . self::ics_escape($s->description);
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="schedule-' . preg_replace('/[^a-z0-9_-]/i', '', $p->token) . '.ics"');
        echo self::ics_fold(implode("\r\n", $lines));
        exit;
    }

    // RFC 5545 character escaping for ICS values
    private static function ics_escape(string $s): string
    {
        $s = str_replace("\\", "\\\\", $s); // backslash first
        $s = str_replace(";", "\\;", $s);
        $s = str_replace(",", "\\,", $s);
        $s = str_replace(["\r\n", "\r", "\n"], "\\n", $s); // newlines
        return $s;
    }

    // RFC 5545 §3.1: fold lines at 75 octets with CRLF + space continuation
    private static function ics_fold(string $text): string
    {
        $out = '';
        foreach (explode("\r\n", $text) as $line) {
            while (strlen($line) > 75) { // strlen counts bytes (octets), not characters
                $out .= substr($line, 0, 75) . "\r\n ";
                $line = substr($line, 75);
            }
            $out .= $line . "\r\n";
        }
        return $out;
    }
}

// Bootstrap: wire up all hooks
Event_Schedules::init();













