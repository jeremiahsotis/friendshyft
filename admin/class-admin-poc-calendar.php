<?php
if (!defined('ABSPATH')) exit;

/**
 * POC Calendar View
 * Calendar interface for POC opportunity management
 */
class FS_Admin_POC_Calendar {

    public static function init() {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_scripts'));
        add_action('wp_ajax_fs_poc_get_calendar_events', array(__CLASS__, 'ajax_get_calendar_events'));
        add_action('admin_post_fs_export_poc_ical', array(__CLASS__, 'export_ical'));
    }

    public static function enqueue_scripts($hook) {
        if ($hook !== 'friendshyft_page_fs-poc-calendar') {
            return;
        }

        // Enqueue FullCalendar
        wp_enqueue_style('fullcalendar', 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.css', array(), '6.1.10');
        wp_enqueue_script('fullcalendar', 'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js', array(), '6.1.10', true);
    }

    public static function render_page() {
        $user_id = get_current_user_id();

        if (!FS_POC_Role::current_user_is_poc()) {
            wp_die('You do not have permission to access this page.');
        }

        ?>
        <div class="wrap">
            <h1>My Calendar</h1>
            <p>View and manage your assigned opportunities in calendar format.</p>

            <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <button id="prev-month" class="button">&larr; Previous</button>
                        <button id="today" class="button">Today</button>
                        <button id="next-month" class="button">Next &rarr;</button>
                        <h2 id="calendar-title" style="margin: 0; padding: 0; font-size: 18px; font-weight: 600;"></h2>
                    </div>
                    <div>
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                            <?php wp_nonce_field('fs_export_poc_ical', '_wpnonce_ical'); ?>
                            <input type="hidden" name="action" value="fs_export_poc_ical">
                            <button type="submit" class="button">
                                <span class="dashicons dashicons-download" style="margin-top: 3px;"></span> Export to iCal
                            </button>
                        </form>
                        <button id="change-view" class="button" data-view="timeGridWeek">Switch to Week View</button>
                    </div>
                </div>

                <div id="calendar" style="max-width: 1200px;"></div>
            </div>

            <!-- Event Details Modal -->
            <div id="event-modal" style="display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); z-index: 10000; max-width: 500px; width: 90%;">
                <h2 id="event-title" style="margin-top: 0;"></h2>
                <div id="event-details"></div>
                <div style="margin-top: 20px; text-align: right;">
                    <button class="button" onclick="closeEventModal()">Close</button>
                    <a id="event-view-signups" href="#" class="button button-primary">View Signups</a>
                </div>
            </div>
            <div id="modal-overlay" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999;" onclick="closeEventModal()"></div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const calendarEl = document.getElementById('calendar');
            let currentView = 'dayGridMonth';

            // Function to update calendar title
            function updateCalendarTitle() {
                const currentDate = calendar.getDate();
                const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
                    'July', 'August', 'September', 'October', 'November', 'December'];
                const month = monthNames[currentDate.getMonth()];
                const year = currentDate.getFullYear();
                document.getElementById('calendar-title').textContent = month + ' ' + year;
            }

            const calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: currentView,
                headerToolbar: false, // We're using custom controls
                events: function(info, successCallback, failureCallback) {
                    fetch(ajaxurl + '?action=fs_poc_get_calendar_events&start=' + info.startStr + '&end=' + info.endStr + '&_wpnonce=<?php echo wp_create_nonce('fs_poc_calendar'); ?>')
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            successCallback(data.data);
                        } else {
                            failureCallback(data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error loading events:', error);
                        failureCallback(error);
                    });
                },
                eventClick: function(info) {
                    showEventDetails(info.event);
                },
                eventColor: '#0073aa',
                height: 'auto',
                datesSet: function() {
                    updateCalendarTitle();
                }
            });

            calendar.render();
            updateCalendarTitle();

            // Navigation controls
            document.getElementById('prev-month').addEventListener('click', function() {
                calendar.prev();
            });

            document.getElementById('today').addEventListener('click', function() {
                calendar.today();
            });

            document.getElementById('next-month').addEventListener('click', function() {
                calendar.next();
            });

            // View switcher
            document.getElementById('change-view').addEventListener('click', function() {
                if (currentView === 'dayGridMonth') {
                    currentView = 'timeGridWeek';
                    this.textContent = 'Switch to Month View';
                    this.setAttribute('data-view', 'dayGridMonth');
                } else {
                    currentView = 'dayGridMonth';
                    this.textContent = 'Switch to Week View';
                    this.setAttribute('data-view', 'timeGridWeek');
                }
                calendar.changeView(currentView);
            });

            // Modal functions
            window.showEventDetails = function(event) {
                document.getElementById('event-title').textContent = event.title;

                let details = '<p><strong>Date:</strong> ' + event.start.toLocaleDateString() + '</p>';

                if (event.extendedProps.location) {
                    details += '<p><strong>Location:</strong> ' + event.extendedProps.location + '</p>';
                }

                if (event.extendedProps.description) {
                    details += '<p><strong>Description:</strong> ' + event.extendedProps.description + '</p>';
                }

                details += '<p><strong>Signups:</strong> ' + event.extendedProps.spots_filled + ' / ' + event.extendedProps.spots_available + '</p>';

                document.getElementById('event-details').innerHTML = details;
                document.getElementById('event-view-signups').href = '<?php echo admin_url('admin.php?page=fs-manage-signups&opportunity_id='); ?>' + event.extendedProps.opportunity_id;

                document.getElementById('event-modal').style.display = 'block';
                document.getElementById('modal-overlay').style.display = 'block';
            };

            window.closeEventModal = function() {
                document.getElementById('event-modal').style.display = 'none';
                document.getElementById('modal-overlay').style.display = 'none';
            };
        });
        </script>

        <style>
            .fc-event {
                cursor: pointer;
            }
            .fc-event:hover {
                opacity: 0.8;
            }
            #event-modal h2 {
                color: #0073aa;
                border-bottom: 2px solid #0073aa;
                padding-bottom: 10px;
            }
        </style>
        <?php
    }

    /**
     * AJAX handler to get calendar events
     */
    public static function ajax_get_calendar_events() {
        check_ajax_referer('fs_poc_calendar');

        if (!FS_POC_Role::current_user_is_poc()) {
            wp_send_json_error('Unauthorized');
            return;
        }

        $user_id = get_current_user_id();
        $start = isset($_GET['start']) ? sanitize_text_field($_GET['start']) : date('Y-m-01');
        $end = isset($_GET['end']) ? sanitize_text_field($_GET['end']) : date('Y-m-t');

        global $wpdb;

        // Get opportunities for this POC in the date range
        $opportunities = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities
             WHERE point_of_contact_id = %d
             AND event_date BETWEEN %s AND %s
             ORDER BY event_date ASC",
            $user_id,
            $start,
            $end
        ));

        $events = array();
        foreach ($opportunities as $opp) {
            $color = '#0073aa'; // Default blue

            // Color code by status
            if ($opp->status === 'cancelled') {
                $color = '#dc3545'; // Red
            } elseif ($opp->spots_filled >= $opp->spots_available) {
                $color = '#28a745'; // Green for full
            } elseif ($opp->status === 'draft') {
                $color = '#ffc107'; // Yellow for draft
            }

            $events[] = array(
                'id' => $opp->id,
                'title' => $opp->title . ' (' . $opp->spots_filled . '/' . $opp->spots_available . ')',
                'start' => $opp->event_date,
                'allDay' => true,
                'color' => $color,
                'extendedProps' => array(
                    'opportunity_id' => $opp->id,
                    'location' => $opp->location,
                    'description' => $opp->description,
                    'spots_filled' => $opp->spots_filled,
                    'spots_available' => $opp->spots_available,
                    'status' => $opp->status
                )
            );
        }

        wp_send_json_success($events);
    }

    /**
     * Export POC opportunities to iCal format
     */
    public static function export_ical() {
        check_admin_referer('fs_export_poc_ical', '_wpnonce_ical');

        if (!FS_POC_Role::current_user_is_poc()) {
            wp_die('Unauthorized');
        }

        $user_id = get_current_user_id();

        global $wpdb;

        // Get all future opportunities for this POC
        $opportunities = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities
             WHERE point_of_contact_id = %d
             AND event_date >= CURDATE()
             ORDER BY event_date ASC",
            $user_id
        ));

        // Build iCal file
        $ical = "BEGIN:VCALENDAR\r\n";
        $ical .= "VERSION:2.0\r\n";
        $ical .= "PRODID:-//FriendShyft//Volunteer Calendar//EN\r\n";
        $ical .= "CALSCALE:GREGORIAN\r\n";
        $ical .= "METHOD:PUBLISH\r\n";
        $ical .= "X-WR-CALNAME:My Volunteer Opportunities\r\n";
        $ical .= "X-WR-TIMEZONE:UTC\r\n";

        foreach ($opportunities as $opp) {
            $ical .= "BEGIN:VEVENT\r\n";
            $ical .= "UID:" . md5($opp->id . '@friendshyft') . "\r\n";
            $ical .= "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
            $ical .= "DTSTART;VALUE=DATE:" . date('Ymd', strtotime($opp->event_date)) . "\r\n";
            $ical .= "DTEND;VALUE=DATE:" . date('Ymd', strtotime($opp->event_date . ' +1 day')) . "\r\n";
            $ical .= "SUMMARY:" . self::escape_ical_text($opp->title) . "\r\n";

            if ($opp->location) {
                $ical .= "LOCATION:" . self::escape_ical_text($opp->location) . "\r\n";
            }

            if ($opp->description) {
                $ical .= "DESCRIPTION:" . self::escape_ical_text($opp->description) . "\r\n";
            }

            $ical .= "STATUS:CONFIRMED\r\n";
            $ical .= "END:VEVENT\r\n";
        }

        $ical .= "END:VCALENDAR\r\n";

        // Send headers
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="friendshyft-opportunities.ics"');
        header('Content-Length: ' . strlen($ical));

        echo $ical;
        exit;
    }

    /**
     * Escape text for iCal format
     */
    private static function escape_ical_text($text) {
        $text = strip_tags($text);
        $text = str_replace(array("\r\n", "\n", "\r"), '\\n', $text);
        $text = str_replace(array(',', ';', '\\'), array('\\,', '\\;', '\\\\'), $text);
        return $text;
    }
}
