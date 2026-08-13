<?php

namespace VirtualMD\PartnerAPI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Schema {
    const DB_VERSION = '1.1.0';

    public function table( $suffix ) {
        global $wpdb;
        return $wpdb->prefix . 'vmd_partner_api_' . $suffix;
    }

    public static function activate() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $self            = new self();
        $charset_collate = $wpdb->get_charset_collate();
        $keys            = $self->table( 'keys' );
        $idempotency     = $self->table( 'idempotency' );
        $appointments    = $self->table( 'appointments' );
        $events          = $self->table( 'events' );
        $audit           = $self->table( 'audit' );

        dbDelta( "CREATE TABLE {$keys} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            public_id varchar(32) NOT NULL,
            name varchar(191) NOT NULL,
            secret_hash char(64) NOT NULL,
            scopes text NOT NULL,
            allowed_ips text NULL,
            rate_limit int(10) unsigned NOT NULL DEFAULT 120,
            appointment_daily_limit int(10) unsigned NOT NULL DEFAULT 100,
            appointment_monthly_limit int(10) unsigned NOT NULL DEFAULT 1000,
            reschedule_limit int(10) unsigned NOT NULL DEFAULT 3,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL,
            last_used_at datetime NULL,
            revoked_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY public_id (public_id),
            KEY status (status)
        ) {$charset_collate};" );

        dbDelta( "CREATE TABLE {$idempotency} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            partner_key_id bigint(20) unsigned NOT NULL,
            idempotency_key varchar(128) NOT NULL,
            request_hash char(64) NOT NULL,
            state varchar(20) NOT NULL DEFAULT 'processing',
            http_status smallint(5) unsigned NULL,
            response_json longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY partner_idempotency (partner_key_id,idempotency_key),
            KEY expires_at (expires_at)
        ) {$charset_collate};" );

        dbDelta( "CREATE TABLE {$appointments} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            partner_key_id bigint(20) unsigned NOT NULL,
            external_reference varchar(100) NOT NULL,
            request_hash char(64) NOT NULL,
            patient_reference varchar(100) NULL,
            amelia_booking_id bigint(20) unsigned NOT NULL DEFAULT 0,
            amelia_appointment_id bigint(20) unsigned NOT NULL DEFAULT 0,
            service_id bigint(20) unsigned NOT NULL,
            provider_id bigint(20) unsigned NOT NULL,
            starts_at datetime NOT NULL,
            status varchar(32) NOT NULL DEFAULT 'pending',
            reschedule_count int(10) unsigned NOT NULL DEFAULT 0,
            last_rescheduled_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY partner_reference (partner_key_id,external_reference),
            KEY amelia_booking (amelia_booking_id),
            KEY created_at (created_at)
        ) {$charset_collate};" );

        dbDelta( "CREATE TABLE {$events} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            appointment_link_id bigint(20) unsigned NOT NULL,
            partner_key_id bigint(20) unsigned NOT NULL,
            event_type varchar(32) NOT NULL,
            old_starts_at datetime NULL,
            new_starts_at datetime NULL,
            old_provider_id bigint(20) unsigned NOT NULL DEFAULT 0,
            new_provider_id bigint(20) unsigned NOT NULL DEFAULT 0,
            request_id varchar(36) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY appointment_event (appointment_link_id,id),
            KEY partner_created (partner_key_id,created_at),
            KEY event_type (event_type)
        ) {$charset_collate};" );

        dbDelta( "CREATE TABLE {$audit} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            partner_key_id bigint(20) unsigned NOT NULL DEFAULT 0,
            request_id varchar(36) NOT NULL,
            action varchar(64) NOT NULL,
            resource_reference varchar(100) NULL,
            http_status smallint(5) unsigned NOT NULL,
            ip_hash char(64) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY partner_created (partner_key_id,created_at),
            KEY request_id (request_id),
            KEY created_at (created_at)
        ) {$charset_collate};" );

        update_option( 'vmdpapi_db_version', self::DB_VERSION, false );

        if ( ! wp_next_scheduled( 'vmdpapi_daily_cleanup' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'vmdpapi_daily_cleanup' );
        }
    }

    public static function maybe_upgrade() {
        if ( get_option( 'vmdpapi_db_version', '' ) !== self::DB_VERSION ) {
            self::activate();
        }
    }

    public static function deactivate() {
        $timestamp = wp_next_scheduled( 'vmdpapi_daily_cleanup' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'vmdpapi_daily_cleanup' );
        }
    }

    /** Tables are intentionally retained on deactivation. */
    public function cleanup() {
        global $wpdb;

        $now   = current_time( 'mysql', true );
        $audit = $this->table( 'audit' );
        $idem  = $this->table( 'idempotency' );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$idem} WHERE expires_at < %s", $now ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$audit} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
}
