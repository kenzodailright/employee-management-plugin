<?php
/*
Plugin Name: Employee Management Plugin
Description: Plugin per la gestione dei dipendenti, ferie, permessi e documenti riservati.
Version: 1.2
Author: Primo Group
*/

if (!defined('ABSPATH')) {
    exit; // Impedisce l'accesso diretto
}

// Definisci la versione del database
define('EMP_DB_VERSION', '1.2');

// Carica le funzioni principali
include_once(plugin_dir_path(__FILE__) . 'includes/emp-management-functions.php');
include_once(plugin_dir_path(__FILE__) . 'includes/emp-management-db.php');

// Funzione da eseguire all'attivazione del plugin
function emp_management_activate() {
    emp_create_db(); // Creazione o aggiornamento delle tabelle del database
    emp_register_roles(); // Registra i ruoli personalizzati
    emp_add_capabilities(); // Aggiungi le capacità ai ruoli
}
register_activation_hook(__FILE__, 'emp_management_activate');

// Aggiungi voci di menu nell'area amministrativa
function emp_create_menu() {
    add_menu_page(
        'Gestione Dipendenti',
        'Gestione Dipendenti',
        'read',
        'emp-management',
        'emp_display_dashboard',
        'dashicons-groups',
        26
    );

    add_submenu_page(
        'emp-management',
        'Richieste Ferie',
        'Richieste Ferie',
        'read',
        'emp-leave-requests',
        'emp_display_leave_requests_admin'
    );

    add_submenu_page(
        'emp-management',
        'Gestione Richieste',
        'Gestione Richieste',
        'manage_emp_requests',
        'emp-manage-requests',
        'emp_manage_requests_page'
    );

    add_submenu_page(
        'emp-management',
        'Carica Documenti',
        'Carica Documenti',
        'manage_emp_requests',
        'emp-upload-document',
        'emp_display_upload_document_page'
    );

    add_submenu_page(
        'emp-management',
        'Documenti Riservati',
        'Documenti Riservati',
        'read',
        'emp-assigned-documents',
        'emp_display_assigned_documents_admin'
    );

    // Aggiungi la pagina per le impostazioni
    add_submenu_page(
        'emp-management',
        'Impostazioni',
        'Impostazioni',
        'manage_options',
        'emp-settings',
        'emp_display_settings_page'
    );

    // Aggiungi la pagina per la disponibilità dei dipendenti
    add_submenu_page(
        'emp-management',
        'Disponibilità Dipendenti',
        'Disponibilità Dipendenti',
        'manage_emp_requests',
        'emp-employee-availability',
        'emp_display_employee_availability_page'
    );
}
add_action('admin_menu', 'emp_create_menu');
