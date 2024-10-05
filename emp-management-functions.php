<?php
if (!defined('ABSPATH')) {
    exit; // Impedisce l'accesso diretto
}

// **Sezione A: Definizione dei Ruoli e Capacità**

// Funzione per registrare i ruoli personalizzati
function emp_register_roles() {
    add_role('employee', 'Dipendente', array(
        'read' => true,
    ));

    add_role('responsabile', 'Responsabile', array(
        'read' => true,
        'manage_emp_requests' => true,
    ));
}
add_action('init', 'emp_register_roles');

// Funzione per aggiungere capacità al ruolo 'responsabile'
function emp_add_capabilities() {
    $role = get_role('responsabile');
    if ($role) {
        $role->add_cap('manage_emp_requests');
    }
}
add_action('init', 'emp_add_capabilities', 11);

// **Sezione B: Classe Personalizzata per la Lista delle Richieste**

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Emp_Requests_List_Table extends WP_List_Table {
    // Costruttore
    public function __construct() {
        parent::__construct(array(
            'singular' => 'richiesta',
            'plural'   => 'richieste',
            'ajax'     => false
        ));
    }

    // Definisce le colonne della tabella
    public function get_columns() {
        $columns = array(
            'cb'            => '<input type="checkbox" />',
            'id'            => 'ID Richiesta',
            'user'          => 'Utente',
            'leave_type'    => 'Tipo',
            'start_date'    => 'Data Inizio',
            'end_date'      => 'Data Fine',
            'notes'         => 'Note',
            'status'        => 'Stato',
        );
        return $columns;
    }

    // Colonne ordinabili
    protected function get_sortable_columns() {
        $sortable_columns = array(
            'id'         => array('id', false),
            'user'       => array('user', false),
            'leave_type' => array('leave_type', false),
            'start_date' => array('start_date', false),
            'end_date'   => array('end_date', false),
            'status'     => array('status', false),
        );
        return $sortable_columns;
    }

    // Recupera i dati per la tabella
    public function prepare_items() {
        global $wpdb;
        $table_name = $wpdb->prefix . "emp_leave_requests";

        $per_page = 10; // Numero di elementi per pagina

        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();

        $this->_column_headers = array($columns, $hidden, $sortable);

        // Controllo per l'ordinamento
        $orderby = !empty($_GET['orderby']) ? sanitize_sql_orderby($_GET['orderby']) : 'id';
        $order = !empty($_GET['order']) ? sanitize_text_field($_GET['order']) : 'DESC';

        // Filtro per la ricerca
        $search = isset($_REQUEST['s']) ? trim($_REQUEST['s']) : '';

        $sql = "SELECT id, user_id, leave_type, start_date, end_date, notes, status FROM $table_name";

        if ($search) {
            $sql .= $wpdb->prepare(" WHERE leave_type LIKE %s OR notes LIKE %s", '%' . $wpdb->esc_like($search) . '%', '%' . $wpdb->esc_like($search) . '%');
        }

        $sql .= " ORDER BY $orderby $order";

        // Paginazione
        $current_page = $this->get_pagenum();
        $total_items = $wpdb->get_var("SELECT COUNT(*) FROM ($sql) AS sub");

        $offset = ($current_page - 1) * $per_page;
        $sql .= $wpdb->prepare(" LIMIT %d OFFSET %d", $per_page, $offset);

        $data = $wpdb->get_results($sql, ARRAY_A);

        $this->items = $data;

        // Imposta gli elementi totali e per pagina
        $this->set_pagination_args(array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page)
        ));
    }

    // Rende le colonne
    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'id':
            case 'leave_type':
            case 'start_date':
            case 'end_date':
            case 'notes':
            case 'status':
                return esc_html($item[$column_name]);
            case 'user':
                $user_info = get_userdata($item['user_id']);
                return $user_info ? esc_html($user_info->display_name) : 'Utente non trovato';
            default:
                return print_r($item, true);
        }
    }

    // Colonna con le azioni
    public function column_id($item) {
        $actions = array(
            'approve' => sprintf(
                '<a href="%s">Approva</a>',
                wp_nonce_url(admin_url('admin.php?page=emp-manage-requests&approve=' . $item['id']), 'approve_request_' . $item['id'])
            ),
            'reject' => sprintf(
                '<a href="%s">Rifiuta</a>',
                wp_nonce_url(admin_url('admin.php?page=emp-manage-requests&reject=' . $item['id']), 'reject_request_' . $item['id'])
            ),
        );

        return sprintf('%1$s %2$s', esc_html($item['id']), $this->row_actions($actions));
    }

    // Colonna delle checkbox
    public function column_cb($item) {
        return sprintf(
            '<input type="checkbox" name="request[]" value="%s" />',
            esc_attr($item['id'])
        );
    }

    // Azioni di massa
    protected function get_bulk_actions() {
        $actions = array(
            'bulk-approve' => 'Approva',
            'bulk-reject'  => 'Rifiuta'
        );
        return $actions;
    }

    // Gestisce le azioni di massa
    public function process_bulk_action() {
        if ('bulk-approve' === $this->current_action()) {
            $request_ids = $_GET['request'];
            foreach ($request_ids as $request_id) {
                emp_approve_request($request_id);
            }
        }

        if ('bulk-reject' === $this->current_action()) {
            $request_ids = $_GET['request'];
            foreach ($request_ids as $request_id) {
                emp_reject_request($request_id);
            }
        }
    }

    // Metodo per la ricerca
    public function search_box($text, $input_id) {
        if (empty($_REQUEST['s']) && !$this->has_items()) {
            return;
        }

        $input_id = $input_id . '-search-input';

        echo '<p class="search-box">';
        echo '<label class="screen-reader-text" for="' . esc_attr($input_id) . '">' . esc_html($text) . ':</label>';
        echo '<input type="search" id="' . esc_attr($input_id) . '" name="s" value="' . esc_attr(isset($_REQUEST['s']) ? $_REQUEST['s'] : '') . '" />';
        submit_button($text, 'button', false, false, array('id' => 'search-submit'));
        echo '</p>';
    }
}

// **Sezione C: Funzioni per la Dashboard e Visualizzazione delle Richieste**

function emp_display_dashboard() {
    echo '<h1>Dashboard Gestione Dipendenti</h1>';

    if (current_user_can('employee')) {
        // Visualizza le richieste del dipendente
        echo do_shortcode('[emp_user_leave_requests]');
    } elseif (current_user_can('responsabile')) {
        // Visualizza un messaggio per i responsabili
        echo '<p>Benvenuto nella dashboard dei responsabili. Puoi gestire le richieste di ferie e permessi dal menu laterale.</p>';
    } else {
        echo '<p>Benvenuto nella gestione dipendenti.</p>';
    }
}

function emp_display_leave_requests_admin() {
    echo '<h1>Richieste di Ferie</h1>';
    echo do_shortcode('[emp_leave_request_form]');
}

// **Sezione D: Funzione per Visualizzare il Form di Richiesta Ferie con Calendario e Dashboard**

function emp_display_leave_requests() {
    // Verifica che l'utente sia loggato e abbia il ruolo di dipendente
    if (!is_user_logged_in() || !current_user_can('employee')) {
        echo '<p>Devi essere loggato come dipendente per inviare una richiesta.</p>';
        return;
    }

    // Gestisci l'invio del form
    if (isset($_POST['submit_leave_request'])) {
        emp_handle_leave_requests_frontend();
    }

    // Carica le impostazioni dei giorni lavorativi per disabilitare le date non lavorative nel datepicker
    $working_days = get_option('emp_working_days', array('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'));
    $working_days_numbers = array();
    $days_map = array('Sunday' => 0, 'Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6);
    foreach ($working_days as $day) {
        $working_days_numbers[] = $days_map[$day];
    }
    $disabled_days = array_values(array_diff(range(0,6), $working_days_numbers));

    // Recupera le informazioni sulle ferie e permessi dell'utente
    $user_id = get_current_user_id();
    $leave_data = emp_get_user_leave_data($user_id);

    $total_leave_days = $leave_data['total_leave_days'];
    $used_leave_days = $leave_data['used_leave_days'];
    $remaining_leave_days = $leave_data['remaining_leave_days'];

    $total_permission_hours = $leave_data['total_permission_hours'];
    $used_permission_hours = $leave_data['used_permission_hours'];
    $remaining_permission_hours = $leave_data['remaining_permission_hours'];

    // Visualizza le informazioni in modo esteticamente gradevole
    ?>
    <h2>Il Tuo Stato di Ferie e Permessi</h2>
    <div class="emp-progress-container">
        <h3>Ferie</h3>
        <div class="emp-progress-bar">
            <div class="emp-progress" style="width: <?php echo ($total_leave_days > 0) ? ($used_leave_days / $total_leave_days) * 100 : 0; ?>%;"></div>
        </div>
        <p><?php echo $used_leave_days; ?> di <?php echo $total_leave_days; ?> giorni utilizzati</p>
        <p>Giorni rimanenti: <?php echo $remaining_leave_days; ?></p>
    </div>

    <div class="emp-progress-container">
        <h3>Permessi</h3>
        <div class="emp-progress-bar">
            <div class="emp-progress" style="width: <?php echo ($total_permission_hours > 0) ? ($used_permission_hours / $total_permission_hours) * 100 : 0; ?>%;"></div>
        </div>
        <p><?php echo $used_permission_hours; ?> di <?php echo $total_permission_hours; ?> ore utilizzate</p>
        <p>Ore rimanenti: <?php echo $remaining_permission_hours; ?></p>
    </div>

    <h2>Calendario Ferie e Permessi</h2>
    <div id="emp-calendar"></div>

    <h2>Invia una Richiesta di Ferie o Permesso</h2>
    <form method="post" action="" class="emp-form" id="emp-leave-request-form">
        <?php wp_nonce_field('emp_leave_request'); ?>
        <div class="emp-form-group">
            <label for="leave_type">Tipo di Richiesta:</label>
            <select id="leave_type" name="leave_type" required>
                <option value="">-- Seleziona --</option>
                <option value="ferie">Ferie</option>
                <option value="permesso">Permesso</option>
            </select>
        </div>
        <div class="emp-form-group">
            <label for="start_date">Data Inizio:</label>
            <input type="text" id="start_date" name="start_date" required>
        </div>
        <div class="emp-form-group">
            <label for="end_date">Data Fine:</label>
            <input type="text" id="end_date" name="end_date" required>
        </div>
        <div class="emp-form-group">
            <label for="notes">Motivo:</label>
            <textarea id="notes" name="notes"></textarea>
        </div>
        <div class="emp-form-group">
            <input type="submit" name="submit_leave_request" value="Invia Richiesta" class="emp-button">
            <div id="emp-loading" style="display: none;">Invio in corso...</div>
        </div>
    </form>

    <!-- Script per il datepicker -->
    <?php
    // Carica gli script solo se non sono già stati caricati
    if (!wp_script_is('flatpickr', 'enqueued')) {
        wp_enqueue_script('flatpickr', 'https://cdn.jsdelivr.net/npm/flatpickr', array('jquery'), null, true);
    }
    if (!wp_script_is('flatpickr-it', 'enqueued')) {
        wp_enqueue_script('flatpickr-it', 'https://npmcdn.com/flatpickr@4.6.13/dist/l10n/it.js', array('flatpickr'), null, true);
    }
    if (!wp_style_is('flatpickr', 'enqueued')) {
        wp_enqueue_style('flatpickr', 'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css');
    }
    ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const disabledDays = <?php echo json_encode($disabled_days); ?>;

            const config = {
                dateFormat: "d/m/Y",
                minDate: "today",
                locale: "it",
                disable: [
                    function(date) {
                        return disabledDays.includes(date.getDay());
                    },
                    <?php
                    // Aggiungi i giorni festivi alla lista delle date disabilitate
                    $holidays = get_option('emp_holidays', array());
                    $disabled_dates = array();
                    foreach ($holidays as $holiday) {
                        // Converti la data nel formato 'd/m/Y'
                        $date = DateTime::createFromFormat('Y-m-d', $holiday);
                        if ($date) {
                            $disabled_dates[] = $date->format('d/m/Y');
                        }
                    }
                    if (!empty($disabled_dates)) {
                        echo '"' . implode('","', $disabled_dates) . '"';
                    }
                    ?>
                ]
            };

            flatpickr("#start_date", config);
            flatpickr("#end_date", config);

            // Validazione lato client
            const form = document.getElementById('emp-leave-request-form');
            form.addEventListener('submit', function(e) {
                let errors = [];
                const leaveType = document.getElementById('leave_type').value;
                const startDate = document.getElementById('start_date').value;
                const endDate = document.getElementById('end_date').value;

                if (!leaveType) {
                    errors.push('Per favore, seleziona il tipo di richiesta.');
                }
                if (!startDate) {
                    errors.push('Per favore, inserisci la data di inizio.');
                }
                if (!endDate) {
                    errors.push('Per favore, inserisci la data di fine.');
                }

                if (errors.length > 0) {
                    e.preventDefault();
                    Swal.fire({
                        icon: 'error',
                        title: 'Si sono verificati degli errori',
                        html: errors.join('<br>'),
                    });
                } else {
                    document.getElementById('emp-loading').style.display = 'block';
                }
            });

            // Inizializza il calendario
            var calendarEl = document.getElementById('emp-calendar');
            var calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: 'dayGridMonth',
                locale: 'it',
                events: empCalendarData.events,
                eventDidMount: function(info) {
                    // Aggiungi un tooltip con il titolo dell'evento
                    $(info.el).tooltipster({
                        content: info.event.title,
                        theme: 'tooltipster-light'
                    });
                }
            });
            calendar.render();
        });
    </script>
    <!-- Script per SweetAlert2 -->
    <?php
    if (!wp_script_is('sweetalert2', 'enqueued')) {
        wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', array('jquery'), null, true);
    }
    ?>
    <?php
}
add_shortcode('emp_leave_request_form', 'emp_display_leave_requests');

// **Sezione E: Funzioni Aggiuntive per Gestire Ferie e Calendario**

function emp_get_user_leave_data($user_id) {
    $total_leave_days = get_user_meta($user_id, 'emp_total_leave_days', true);
    $used_leave_days = get_user_meta($user_id, 'emp_used_leave_days', true);
    $remaining_leave_days = max(0, $total_leave_days - $used_leave_days);

    $total_permission_hours = get_user_meta($user_id, 'emp_total_permission_hours', true);
    $used_permission_hours = get_user_meta($user_id, 'emp_used_permission_hours', true);
    $remaining_permission_hours = max(0, $total_permission_hours - $used_permission_hours);

    return array(
        'total_leave_days' => intval($total_leave_days),
        'used_leave_days' => intval($used_leave_days),
        'remaining_leave_days' => $remaining_leave_days,
        'total_permission_hours' => intval($total_permission_hours),
        'used_permission_hours' => intval($used_permission_hours),
        'remaining_permission_hours' => $remaining_permission_hours,
    );
}

function emp_get_approved_requests_for_calendar() {
    global $wpdb;
    $table_name = $wpdb->prefix . "emp_leave_requests";

    $requests = $wpdb->get_results(
        "SELECT lr.id, lr.user_id, lr.leave_type, lr.start_date, lr.end_date, u.display_name
         FROM $table_name lr
         JOIN $wpdb->users u ON lr.user_id = u.ID
         WHERE lr.status = 'approved'"
    );

    $events = array();

    foreach ($requests as $request) {
        $events[] = array(
            'title' => $request->display_name . ' - ' . ucfirst($request->leave_type),
            'start' => $request->start_date,
            'end' => date('Y-m-d', strtotime($request->end_date . ' +1 day')), // FullCalendar non include il giorno di fine
            'color' => ($request->leave_type == 'ferie') ? '#0073aa' : '#00a32a', // Colore diverso per ferie e permessi
        );
    }

    return $events;
}

function emp_localize_calendar_script() {
    if (is_page() && has_shortcode(get_post()->post_content, 'emp_leave_request_form')) {
        $events = emp_get_approved_requests_for_calendar();
        wp_localize_script('fullcalendar-js', 'empCalendarData', array(
            'events' => $events,
        ));
    }
}
add_action('wp_enqueue_scripts', 'emp_localize_calendar_script', 20);

// **Sezione F: Funzione per Gestire l'Invio delle Richieste**

function emp_handle_leave_requests_frontend() {
    if (!is_user_logged_in() || !current_user_can('employee')) {
        return;
    }

    // Verifica nonce per la sicurezza
    if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'emp_leave_request')) {
        echo '<p class="emp-error">Errore di sicurezza. Riprovare.</p>';
        return;
    }

    // Array per accumulare gli errori
    $errors = array();

    // Sanitize input data
    $leave_type = sanitize_text_field($_POST['leave_type']);
    $start_date_input = sanitize_text_field($_POST['start_date']);
    $end_date_input = sanitize_text_field($_POST['end_date']);
    $notes = sanitize_textarea_field($_POST['notes']);

    // Converti le date dal formato 'd/m/Y' al formato 'Y-m-d'
    $start_date = DateTime::createFromFormat('d/m/Y', $start_date_input);
    $end_date = DateTime::createFromFormat('d/m/Y', $end_date_input);

    if (!$start_date || !$end_date) {
        $errors[] = 'Formato data non valido. Per favore, utilizza il formato gg/mm/aaaa.';
    } else {
        $start_date_str = $start_date->format('Y-m-d');
        $end_date_str = $end_date->format('Y-m-d');
    }

    // Validazione dei campi obbligatori
    if (empty($leave_type)) {
        $errors[] = 'Per favore, seleziona il tipo di richiesta.';
    }

    if (empty($start_date_input)) {
        $errors[] = 'Per favore, inserisci la data di inizio.';
    }

    if (empty($end_date_input)) {
        $errors[] = 'Per favore, inserisci la data di fine.';
    }

    // Se ci sono errori finora, mostra gli errori e termina
    if (!empty($errors)) {
        emp_display_errors($errors);
        return;
    }

    // Converti le date in timestamp per il confronto
    $start_timestamp = strtotime($start_date_str);
    $end_timestamp = strtotime($end_date_str);
    $today_timestamp = strtotime(date('Y-m-d'));

    // Controllo: Data di fine non può essere precedente alla data di inizio
    if ($end_timestamp < $start_timestamp) {
        $errors[] = 'La data di fine non può essere precedente alla data di inizio.';
    }

    // Controllo: Data di inizio non può essere nel passato
    if ($start_timestamp < $today_timestamp) {
        $errors[] = 'Non puoi inserire una data di inizio nel passato.';
    }

    // Carica le impostazioni dei giorni lavorativi
    $working_days = get_option('emp_working_days', array('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'));
    $holidays = get_option('emp_holidays', array());

    // Calcola i giorni lavorativi richiesti
    $requested_days = emp_get_working_days($start_date_str, $end_date_str, $working_days, $holidays);

    if (empty($requested_days)) {
        $errors[] = 'Le date selezionate non includono giorni lavorativi.';
    }

    // Controllo: Durata massima
    $max_duration = 14; // Durata massima in giorni lavorativi
    if (count($requested_days) > $max_duration) {
        $errors[] = 'Non puoi richiedere più di ' . $max_duration . ' giorni lavorativi consecutivi.';
    }

    // Controllo: Disponibilità del dipendente
    $user_id = get_current_user_id();
    if ($leave_type == 'ferie') {
        $total_leave_days = get_user_meta($user_id, 'emp_total_leave_days', true);
        $used_leave_days = get_user_meta($user_id, 'emp_used_leave_days', true);

        $available_leave_days = $total_leave_days - $used_leave_days;
        if (count($requested_days) > $available_leave_days) {
            $errors[] = 'Non hai abbastanza giorni di ferie disponibili. Disponibili: ' . $available_leave_days . ' giorni.';
        }
    } elseif ($leave_type == 'permesso') {
        // Implementazione per i permessi (considerando le ore)
        $total_permission_hours = get_user_meta($user_id, 'emp_total_permission_hours', true);
        $used_permission_hours = get_user_meta($user_id, 'emp_used_permission_hours', true);

        $available_permission_hours = $total_permission_hours - $used_permission_hours;
        $requested_hours = count($requested_days) * 8; // Considerando 8 ore lavorative al giorno

        if ($requested_hours > $available_permission_hours) {
            $errors[] = 'Non hai abbastanza ore di permesso disponibili. Disponibili: ' . $available_permission_hours . ' ore.';
        }
    }

    // Controllo: Sovrapposizione con altre richieste approvate di altri dipendenti
    global $wpdb;
    $table_name = $wpdb->prefix . "emp_leave_requests";

    $overlap = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE status = 'approved' AND user_id != %d AND (
                (start_date <= %s AND end_date >= %s)
            )",
            $user_id,
            $end_date_str,
            $start_date_str
        )
    );

    if ($overlap > 0) {
        $errors[] = 'Le date selezionate non sono disponibili. Un altro dipendente ha già richiesto ferie in quel periodo.';
    }

    // Se ci sono errori, mostra gli errori e termina
    if (!empty($errors)) {
        emp_display_errors($errors);
        return;
    }

    // Se tutti i controlli sono superati, inserisci la richiesta nel database
    $data = array(
        'user_id' => $user_id,
        'leave_type' => $leave_type,
        'start_date' => $start_date_str,
        'end_date' => $end_date_str,
        'notes' => $notes,
        'status' => 'pending'
    );

    $wpdb->insert($table_name, $data);

    echo "<script>
        jQuery(function($) {
            Swal.fire({
                icon: 'success',
                title: 'Richiesta Inviata',
                text: 'La tua richiesta è stata inviata con successo!',
            });
        });
    </script>";

    // Invia notifica al dipendente
    $subject = 'Conferma Richiesta di ' . ucfirst($leave_type);
    $message = 'La tua richiesta di ' . $leave_type . ' dal ' . $start_date_input . ' al ' . $end_date_input . ' è stata inviata ed è in attesa di approvazione.';
    emp_send_email_notification($user_id, $subject, $message);

    // Invia notifica ai responsabili
    $managers = get_users(array(
        'role' => 'responsabile',
        'fields' => array('ID'),
        'cache_results' => true,
    ));
    $employee_info = get_userdata($user_id);
    $employee_name = $employee_info->display_name;

    $subject_manager = 'Nuova Richiesta di ' . ucfirst($leave_type) . ' da ' . $employee_name;
    $message_manager = 'Ciao, ' . $employee_name . ' ha inviato una nuova richiesta di ' . $leave_type . ' dal ' . $start_date_input . ' al ' . $end_date_input . '. Accedi al sistema per gestirla.';

    foreach ($managers as $manager) {
        emp_send_email_notification($manager->ID, $subject_manager, $message_manager);
    }
}

// Funzione per inviare email di notifica
function emp_send_email_notification($user_id, $subject, $message) {
    $user_info = get_userdata($user_id);
    $to = $user_info->user_email;
    $headers = array('Content-Type: text/html; charset=UTF-8');

    // Carica il template dell'email
    $email_template = file_get_contents(plugin_dir_path(__FILE__) . '../templates/email-template.html');

    // Sostituisci i placeholder con i dati reali
    $email_content = str_replace(
        array('{subject}', '{name}', '{message}'),
        array($subject, $user_info->display_name, nl2br($message)),
        $email_template
    );

    wp_mail($to, $subject, $email_content, $headers);
}

// Funzione per visualizzare gli errori
function emp_display_errors($errors) {
    $errors_str = implode('<br>', array_map('esc_js', $errors));
    echo "<script>
        jQuery(function($) {
            Swal.fire({
                icon: 'error',
                title: 'Si sono verificati degli errori',
                html: '{$errors_str}',
            });
        });
    </script>";
}

// Funzione per calcolare i giorni lavorativi tra due date
function emp_get_working_days($start_date, $end_date, $working_days, $holidays) {
    $begin = new DateTime($start_date);
    $end = new DateTime($end_date);
    $end->modify('+1 day'); // Include la data di fine

    $interval = new DateInterval('P1D');
    $daterange = new DatePeriod($begin, $interval, $end);

    $working_days_array = array();

    foreach ($daterange as $date) {
        $day_name = $date->format('l');
        $date_str = $date->format('Y-m-d');

        if (in_array($day_name, $working_days) && !in_array($date_str, $holidays)) {
            $working_days_array[] = $date_str;
        }
    }

    return $working_days_array;
}

// **Sezione G: Enqueue degli Script e Stili**

function emp_enqueue_calendar_assets() {
    if (is_page() && has_shortcode(get_post()->post_content, 'emp_leave_request_form')) {
        // Enqueue FullCalendar CSS and JS
        wp_enqueue_style('fullcalendar-css', 'https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/5.11.0/main.min.css', array(), '5.11.0');
        wp_enqueue_script('fullcalendar-js', 'https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/5.11.0/main.min.js', array('jquery'), '5.11.0', true);

        // Enqueue Tooltipster (opzionale)
        wp_enqueue_style('tooltipster-css', 'https://cdnjs.cloudflare.com/ajax/libs/tooltipster/4.2.8/css/tooltipster.bundle.min.css', array(), '4.2.8');
        wp_enqueue_script('tooltipster-js', 'https://cdnjs.cloudflare.com/ajax/libs/tooltipster/4.2.8/js/tooltipster.bundle.min.js', array('jquery'), '4.2.8', true);
    }
}
add_action('wp_enqueue_scripts', 'emp_enqueue_calendar_assets');

// Enqueue degli stili personalizzati
function emp_enqueue_styles() {
    if (is_page() && (has_shortcode(get_post()->post_content, 'emp_leave_request_form') || has_shortcode(get_post()->post_content, 'emp_user_leave_requests') || has_shortcode(get_post()->post_content, 'emp_assigned_documents'))) {
        wp_enqueue_style('emp-styles', plugins_url('../assets/css/emp-styles.css', __FILE__));
        // Aggiungi Tooltipster CSS se non già incluso
        if (!wp_style_is('tooltipster-css', 'enqueued')) {
            wp_enqueue_style('tooltipster-css', 'https://cdnjs.cloudflare.com/ajax/libs/tooltipster/4.2.8/css/tooltipster.bundle.min.css', array(), '4.2.8');
        }
    }
}
add_action('wp_enqueue_scripts', 'emp_enqueue_styles');

// Enqueue degli script per SweetAlert2
function emp_enqueue_scripts() {
    if (is_page() && (has_shortcode(get_post()->post_content, 'emp_leave_request_form') || has_shortcode(get_post()->post_content, 'emp_user_leave_requests'))) {
        wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', array('jquery'), null, true);
    }
}
add_action('wp_enqueue_scripts', 'emp_enqueue_scripts');

// **Sezione H: Altre Funzioni Necessarie**

/* Aggiungi qui le altre funzioni necessarie per il funzionamento completo del plugin */

// Shortcode per visualizzare le richieste di ferie dell'utente nel frontend
function emp_user_leave_requests_shortcode() {
    if (!is_user_logged_in() || !current_user_can('employee')) {
        return '<p>Devi essere loggato come dipendente per vedere le tue richieste.</p>';
    }

    global $wpdb;
    $table_name = $wpdb->prefix . "emp_leave_requests";
    $user_id = get_current_user_id();

    $per_page = 10; // Numero di richieste per pagina
    $current_page = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
    $offset = ($current_page - 1) * $per_page;

    // Conta il numero totale di richieste
    $total_requests = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE user_id = %d",
        $user_id
    ));

    // Recupera le richieste per la pagina corrente
    $requests = $wpdb->get_results($wpdb->prepare(
        "SELECT id, leave_type, start_date, end_date, status FROM $table_name WHERE user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
        $user_id, $per_page, $offset
    ));

    ob_start();

    if ($requests) {
        echo '<h2>Le tue Richieste di Ferie/Permessi</h2>';
        echo '<table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Tipo</th>
                    <th>Data Inizio</th>
                    <th>Data Fine</th>
                    <th>Stato</th>
                </tr>
            </thead>
            <tbody>';
        foreach ($requests as $request) {
            echo '<tr>
                <td data-label="ID">' . esc_html($request->id) . '</td>
                <td data-label="Tipo">' . esc_html($request->leave_type) . '</td>
                <td data-label="Data Inizio">' . date_i18n('d/m/Y', strtotime($request->start_date)) . '</td>
                <td data-label="Data Fine">' . date_i18n('d/m/Y', strtotime($request->end_date)) . '</td>
                <td data-label="Stato">' . esc_html($request->status) . '</td>
            </tr>';
        }
        echo '</tbody></table>';

        // Aggiungi la paginazione
        $total_pages = ceil($total_requests / $per_page);

        if ($total_pages > 1) {
            echo '<div class="emp-pagination">';
            for ($i = 1; $i <= $total_pages; $i++) {
                if ($i == $current_page) {
                    echo '<span class="emp-page-number emp-current-page">' . $i . '</span>';
                } else {
                    echo '<a class="emp-page-number" href="' . esc_url(add_query_arg('paged', $i)) . '">' . $i . '</a>';
                }
            }
            echo '</div>';
        }

    } else {
        echo '<p>Non hai ancora inviato richieste di ferie o permessi.</p>';
    }

    return ob_get_clean();
}
add_shortcode('emp_user_leave_requests', 'emp_user_leave_requests_shortcode');

// Funzione per visualizzare e gestire le richieste di ferie/permessi nel backend
function emp_manage_requests_page() {
    // Controllo dei permessi: solo i responsabili o gli amministratori possono accedere
    if (!current_user_can('manage_emp_requests') && !current_user_can('administrator')) {
        wp_die('Non hai il permesso di accedere a questa pagina.');
    }

    // Crea un'istanza della classe personalizzata
    $requestListTable = new Emp_Requests_List_Table();
    $requestListTable->process_bulk_action();

    // Gestione delle azioni di approvazione/rifiuto
    if (isset($_GET['approve'])) {
        $request_id = intval($_GET['approve']);
        if (wp_verify_nonce($_GET['_wpnonce'], 'approve_request_' . $request_id)) {
            emp_approve_request($request_id);
            wp_redirect(admin_url('admin.php?page=emp-manage-requests&message=approved'));
            exit;
        } else {
            echo "<div class='error'><p>Nonce non valido. Azione non eseguita.</p></div>";
        }
    }

    if (isset($_GET['reject'])) {
        $request_id = intval($_GET['reject']);
        if (wp_verify_nonce($_GET['_wpnonce'], 'reject_request_' . $request_id)) {
            emp_reject_request($request_id);
            wp_redirect(admin_url('admin.php?page=emp-manage-requests&message=rejected'));
            exit;
        } else {
            echo "<div class='error'><p>Nonce non valido. Azione non eseguita.</p></div>";
        }
    }

    $requestListTable->prepare_items();

    // Mostra messaggi di conferma
    if (isset($_GET['message'])) {
        if ($_GET['message'] == 'approved') {
            echo "<div class='updated'><p>Richiesta approvata!</p></div>";
        } elseif ($_GET['message'] == 'rejected') {
            echo "<div class='updated'><p>Richiesta rifiutata!</p></div>";
        }
    }

    // Visualizza la tabella delle richieste
    echo '<div class="wrap">
        <h1>Gestione delle Richieste di Ferie/Permessi</h1>';

    echo '<form method="get">';
    echo '<input type="hidden" name="page" value="' . esc_attr($_REQUEST['page']) . '" />';
    $requestListTable->search_box('Cerca Richieste', 'search');
    $requestListTable->display();
    echo '</form>';

    echo '</div>';
}

// Funzione per approvare una richiesta
function emp_approve_request($request_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . "emp_leave_requests";

    $wpdb->update($table_name, array('status' => 'approved'), array('id' => $request_id));

    // Invia notifica al dipendente
    $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $request_id));

    // Aggiorna le disponibilità del dipendente
    $user_id = $request->user_id;
    $start_date = $request->start_date;
    $end_date = $request->end_date;
    $leave_type = $request->leave_type;

    $requested_days = emp_get_working_days($start_date, $end_date, get_option('emp_working_days', array('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday')), get_option('emp_holidays', array()));
    $days_count = count($requested_days);

    if ($leave_type == 'ferie') {
        $used_leave_days = get_user_meta($user_id, 'emp_used_leave_days', true);
        $used_leave_days += $days_count;
        update_user_meta($user_id, 'emp_used_leave_days', $used_leave_days);
    } elseif ($leave_type == 'permesso') {
        $used_permission_hours = get_user_meta($user_id, 'emp_used_permission_hours', true);
        $used_permission_hours += $days_count * 8; // Considerando 8 ore al giorno
        update_user_meta($user_id, 'emp_used_permission_hours', $used_permission_hours);
    }

    $subject = 'Richiesta di ' . ucfirst($leave_type) . ' Approvata';
    $message = 'La tua richiesta di ' . $leave_type . ' dal ' . date_i18n('d/m/Y', strtotime($start_date)) . ' al ' . date_i18n('d/m/Y', strtotime($end_date)) . ' è stata approvata.';
    emp_send_email_notification($request->user_id, $subject, $message);
}

// Funzione per rifiutare una richiesta
function emp_reject_request($request_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . "emp_leave_requests";

    $wpdb->update($table_name, array('status' => 'rejected'), array('id' => $request_id));

    // Invia notifica al dipendente
    $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $request_id));
    $subject = 'Richiesta di ' . ucfirst($request->leave_type) . ' Rifiutata';
    $message = 'La tua richiesta è stata rifiutata.';
    emp_send_email_notification($request->user_id, $subject, $message);
}

// Funzione per visualizzare il form di upload dei documenti riservati
function emp_display_upload_document_page() {
    // Controllo dei permessi
    if (!current_user_can('manage_emp_requests') && !current_user_can('administrator')) {
        wp_die('Non hai il permesso di accedere a questa pagina.');
    }

    if (isset($_POST['submit_document']) && !empty($_FILES['document_file']['name'])) {
        // Verifica nonce per la sicurezza
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'emp_upload_document')) {
            echo '<p>Errore di sicurezza. Riprovare.</p>';
            return;
        }

        global $wpdb;
        $docs_table_name = $wpdb->prefix . "emp_documents";

        // Imposta il percorso di upload al di fuori della root web
        $upload_dir = dirname(ABSPATH) . '/emp_documents/';

        if (!file_exists($upload_dir)) {
            wp_mkdir_p($upload_dir);
        }

        $uploadedfile = $_FILES['document_file'];
        $filename = wp_unique_filename($upload_dir, $uploadedfile['name']);
        $target_file = $upload_dir . $filename;

        // Muovi il file caricato nella nuova directory
        if (move_uploaded_file($uploadedfile['tmp_name'], $target_file)) {
            // Inserisci nel database
            $data = array(
                'document_name' => sanitize_text_field($_POST['document_name']),
                'file_path' => $target_file,
                'assigned_to' => intval($_POST['assigned_to']),
            );

            $wpdb->insert($docs_table_name, $data);
            echo "<div class='updated'><p>Documento caricato con successo!</p></div>";

            // Invia notifica al dipendente
            $assigned_user_id = intval($_POST['assigned_to']);
            $subject = 'Nuovo Documento Assegnato: ' . sanitize_text_field($_POST['document_name']);
            $message = 'Un nuovo documento è stato assegnato a te. Accedi al sistema per visualizzarlo.';
            emp_send_email_notification($assigned_user_id, $subject, $message);

        } else {
            echo "<div class='error'><p>Errore nel caricamento del file.</p></div>";
        }
    }

    // Recupera tutti gli utenti con ruolo 'employee'
    $employees = get_users(array(
        'role'   => 'employee',
        'orderby' => 'display_name',
        'order'   => 'ASC',
        'fields'  => array('ID', 'display_name', 'user_email'),
        'cache_results' => true,
    ));

    // Form di upload
    ?>
    <h1>Carica Documento Riservato</h1>
    <form method="post" action="" enctype="multipart/form-data">
        <?php wp_nonce_field('emp_upload_document'); ?>
        <label for="document_name">Nome Documento:</label>
        <input type="text" id="document_name" name="document_name" required><br><br>

        <label for="document_file">File:</label>
        <input type="file" id="document_file" name="document_file" required><br><br>

        <label for="assigned_to">Assegna a Utente:</label>
        <select id="assigned_to" name="assigned_to" required>
            <option value="">-- Seleziona un Dipendente --</option>
            <?php
            foreach ($employees as $employee) {
                echo '<option value="' . esc_attr($employee->ID) . '">' . esc_html($employee->display_name) . ' (' . esc_html($employee->user_email) . ')</option>';
            }
            ?>
        </select><br><br>

        <input type="submit" name="submit_document" value="Carica Documento">
    </form>
    <?php
}

// Funzione per visualizzare i documenti riservati nel backend
function emp_display_assigned_documents_admin() {
    emp_display_assigned_documents();
}

// Funzione per visualizzare i documenti riservati nel frontend
function emp_display_assigned_documents() {
    if (!is_user_logged_in() || !current_user_can('employee')) {
        echo '<p>Devi essere loggato come dipendente per vedere i tuoi documenti.</p>';
        return;
    }

    global $wpdb;
    $docs_table_name = $wpdb->prefix . "emp_documents";
    $user_id = get_current_user_id();

    $documents = $wpdb->get_results($wpdb->prepare("SELECT id, document_name, upload_date FROM $docs_table_name WHERE assigned_to = %d", $user_id));

    echo '<h2>I Tuoi Documenti Riservati</h2>';

    if ($documents) {
        echo '<table>
            <thead>
                <tr>
                    <th>Nome Documento</th>
                    <th>Data Caricamento</th>
                    <th>Azione</th>
                </tr>
            </thead>
            <tbody>';
        foreach ($documents as $doc) {
            $download_url = add_query_arg(array(
                'emp-action' => 'download_document',
                'doc_id' => $doc->id,
            ), site_url('/'));

            echo '<tr>
                <td data-label="Nome Documento">' . esc_html($doc->document_name) . '</td>
                <td data-label="Data Caricamento">' . date_i18n('d/m/Y', strtotime($doc->upload_date)) . '</td>
                <td data-label="Azione"><a href="' . esc_url($download_url) . '">Scarica</a></td>
            </tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p>Nessun documento disponibile.</p>';
    }
}

// Shortcode per visualizzare i documenti riservati nel frontend
add_shortcode('emp_assigned_documents', 'emp_display_assigned_documents');

// Funzione per gestire il download dei documenti nel frontend
function emp_download_document_frontend() {
    if (!isset($_GET['doc_id']) || !is_user_logged_in()) {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return new WP_Error('forbidden', 'Accesso non autorizzato', array('status' => 403));
        } else {
            wp_die('Accesso non autorizzato.', 'Errore', array('response' => 403));
        }
    }

    global $wpdb;
    $docs_table_name = $wpdb->prefix . "emp_documents";
    $doc_id = intval($_GET['doc_id']);
    $user_id = get_current_user_id();

    $document = $wpdb->get_row($wpdb->prepare("SELECT * FROM $docs_table_name WHERE id = %d AND assigned_to = %d", $doc_id, $user_id));

    if ($document) {
        $file_path = $document->file_path;
        $file_name = basename($file_path);

        if (file_exists($file_path)) {
            // Serve il file in modo sicuro
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $file_name . '"');
            header('Content-Length: ' . filesize($file_path));
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            flush();
            readfile($file_path);
            exit;
        } else {
            if (defined('REST_REQUEST') && REST_REQUEST) {
                return new WP_Error('not_found', 'Il file non esiste.', array('status' => 404));
            } else {
                wp_die('Il file non esiste.', 'Errore', array('response' => 404));
            }
        }
    } else {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return new WP_Error('forbidden', 'Non hai il permesso di accedere a questo documento.', array('status' => 403));
        } else {
            wp_die('Non hai il permesso di accedere a questo documento.', 'Errore', array('response' => 403));
        }
    }
}

// Funzione per gestire l'azione di download senza interferire con le richieste REST
function emp_handle_download_action() {
    if (isset($_GET['emp-action']) && $_GET['emp-action'] === 'download_document') {
        // Esegui solo se non è una richiesta REST o AJAX
        if (!defined('REST_REQUEST') && !defined('DOING_AJAX')) {
            emp_download_document_frontend();
            exit; // Termina l'esecuzione dello script
        }
    }
}
add_action('init', 'emp_handle_download_action');

// Funzione per visualizzare la pagina di impostazioni
function emp_display_settings_page() {
    // Controllo dei permessi
    if (!current_user_can('manage_options')) {
        wp_die('Non hai il permesso di accedere a questa pagina.');
    }

    // Salva le impostazioni se il form è stato inviato
    if (isset($_POST['emp_save_settings'])) {
        // Verifica nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'emp_save_settings')) {
            echo '<div class="error"><p>Errore di sicurezza. Impostazioni non salvate.</p></div>';
        } else {
            // Salva i giorni lavorativi
            $working_days = isset($_POST['working_days']) ? array_map('sanitize_text_field', $_POST['working_days']) : array();
            update_option('emp_working_days', $working_days);

            // Salva i giorni festivi
            $holidays_input = isset($_POST['holidays']) ? sanitize_textarea_field($_POST['holidays']) : '';
            $holidays_array = array_filter(array_map('trim', explode("\n", $holidays_input)));

            // Converti le date dal formato 'gg/mm/aaaa' al formato 'Y-m-d'
            $holidays = array();
            foreach ($holidays_array as $holiday) {
                $date = DateTime::createFromFormat('d/m/Y', $holiday);
                if ($date) {
                    $holidays[] = $date->format('Y-m-d');
                }
            }
            update_option('emp_holidays', $holidays);

            echo '<div class="updated"><p>Impostazioni salvate con successo!</p></div>';
        }
    }

    // Recupera le impostazioni
    $working_days = get_option('emp_working_days', array('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'));
    $holidays_array = get_option('emp_holidays', array());

    // Converti le date dei giorni festivi nel formato 'gg/mm/aaaa' per visualizzarle nel form
    $holidays_input = '';
    foreach ($holidays_array as $holiday) {
        $date = DateTime::createFromFormat('Y-m-d', $holiday);
        if ($date) {
            $holidays_input .= $date->format('d/m/Y') . "\n";
        }
    }

    // Form delle impostazioni
    ?>
    <div class="wrap">
        <h1>Impostazioni Gestione Dipendenti</h1>
        <form method="post" action="">
            <?php wp_nonce_field('emp_save_settings'); ?>
            <h2>Giorni Lavorativi</h2>
            <p>Seleziona i giorni della settimana considerati lavorativi:</p>
            <?php
            $days_of_week = array('Monday' => 'Lunedì', 'Tuesday' => 'Martedì', 'Wednesday' => 'Mercoledì', 'Thursday' => 'Giovedì', 'Friday' => 'Venerdì', 'Saturday' => 'Sabato', 'Sunday' => 'Domenica');
            foreach ($days_of_week as $day_key => $day_name) {
                $checked = in_array($day_key, $working_days) ? 'checked' : '';
                echo '<label><input type="checkbox" name="working_days[]" value="' . esc_attr($day_key) . '" ' . $checked . '> ' . esc_html($day_name) . '</label><br>';
            }
            ?>
            <h2>Giorni Festivi</h2>
            <p>Inserisci le date dei giorni festivi (formato gg/mm/aaaa), una per riga:</p>
            <textarea name="holidays" rows="10" cols="50"><?php echo esc_textarea($holidays_input); ?></textarea><br><br>
            <input type="submit" name="emp_save_settings" class="button button-primary" value="Salva Impostazioni">
        </form>
    </div>
    <?php
}

// Funzione per visualizzare la pagina di disponibilità dei dipendenti
function emp_display_employee_availability_page() {
    // Controllo dei permessi
    if (!current_user_can('manage_emp_requests') && !current_user_can('administrator')) {
        wp_die('Non hai il permesso di accedere a questa pagina.');
    }

    // Gestione del salvataggio delle disponibilità
    if (isset($_POST['emp_save_availability'])) {
        // Verifica nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'emp_save_availability')) {
            echo '<div class="error"><p>Errore di sicurezza. Disponibilità non salvate.</p></div>';
        } else {
            // Salva le disponibilità per ciascun dipendente
            foreach ($_POST['employee'] as $user_id => $data) {
                update_user_meta($user_id, 'emp_total_leave_days', intval($data['total_leave_days']));
                update_user_meta($user_id, 'emp_used_leave_days', intval($data['used_leave_days']));
                update_user_meta($user_id, 'emp_total_permission_hours', intval($data['total_permission_hours']));
                update_user_meta($user_id, 'emp_used_permission_hours', intval($data['used_permission_hours']));
            }
            echo '<div class="updated"><p>Disponibilità salvate con successo!</p></div>';
        }
    }

    // Recupera tutti i dipendenti
    $employees = get_users(array(
        'role'   => 'employee',
        'orderby' => 'display_name',
        'order'   => 'ASC',
        'fields'  => array('ID', 'display_name'),
        'cache_results' => true,
    ));

    // Form per visualizzare e modificare le disponibilità
    ?>
    <div class="wrap">
        <h1>Disponibilità Dipendenti</h1>
        <form method="post" action="">
            <?php wp_nonce_field('emp_save_availability'); ?>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Dipendente</th>
                        <th>Ferie Totali (Giorni)</th>
                        <th>Ferie Utilizzate (Giorni)</th>
                        <th>Permessi Totali (Ore)</th>
                        <th>Permessi Utilizzati (Ore)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($employees as $employee) {
                        $user_id = $employee->ID;
                        $total_leave_days = get_user_meta($user_id, 'emp_total_leave_days', true);
                        $used_leave_days = get_user_meta($user_id, 'emp_used_leave_days', true);
                        $total_permission_hours = get_user_meta($user_id, 'emp_total_permission_hours', true);
                        $used_permission_hours = get_user_meta($user_id, 'emp_used_permission_hours', true);
                    ?>
                    <tr>
                        <td><?php echo esc_html($employee->display_name); ?></td>
                        <td><input type="number" name="employee[<?php echo esc_attr($user_id); ?>][total_leave_days]" value="<?php echo esc_attr($total_leave_days); ?>"></td>
                        <td><input type="number" name="employee[<?php echo esc_attr($user_id); ?>][used_leave_days]" value="<?php echo esc_attr($used_leave_days); ?>"></td>
                        <td><input type="number" name="employee[<?php echo esc_attr($user_id); ?>][total_permission_hours]" value="<?php echo esc_attr($total_permission_hours); ?>"></td>
                        <td><input type="number" name="employee[<?php echo esc_attr($user_id); ?>][used_permission_hours]" value="<?php echo esc_attr($used_permission_hours); ?>"></td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
            <br>
            <input type="submit" name="emp_save_availability" class="button button-primary" value="Salva Disponibilità">
        </form>
    </div>
    <?php
}
