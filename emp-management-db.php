<?php
if (!defined('ABSPATH')) {
    exit; // Impedisce l'accesso diretto
}

// Funzione per creare o aggiornare le tabelle del database
function emp_create_db() {
    global $wpdb;

    $leave_table_name = $wpdb->prefix . "emp_leave_requests";
    $docs_table_name = $wpdb->prefix . "emp_documents";
    $charset_collate = $wpdb->get_charset_collate();

    // Codice SQL per creare o aggiornare le tabelle
    $leave_sql = "CREATE TABLE $leave_table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        leave_type varchar(255) NOT NULL,
        start_date date NOT NULL,
        end_date date NOT NULL,
        notes text,
        status varchar(50) NOT NULL DEFAULT 'pending',
        PRIMARY KEY  (id),
        INDEX user_id_idx (user_id),
        INDEX status_idx (status)
    ) $charset_collate;";

    $docs_sql = "CREATE TABLE $docs_table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        document_name varchar(255) NOT NULL,
        file_path varchar(255) NOT NULL,
        assigned_to bigint(20) NOT NULL,
        upload_date datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id),
        INDEX assigned_to_idx (assigned_to)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    // Crea o aggiorna le tabelle
    dbDelta($leave_sql);
    dbDelta($docs_sql);

    // Aggiorna la versione del database
    update_option('emp_db_version', EMP_DB_VERSION);
}
