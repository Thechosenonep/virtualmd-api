<?php

namespace VirtualMD\PartnerAPI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Repository {
    private $schema;

    public function __construct( Schema $schema ) {
        $this->schema = $schema;
    }

    public function dependency_status() {
        global $wpdb;

        $required_tables = [
            $wpdb->prefix . 'amelia_categories',
            $wpdb->prefix . 'amelia_services',
            $wpdb->prefix . 'amelia_users',
            $wpdb->prefix . 'amelia_providers_to_services',
            $wpdb->prefix . 'amelia_appointments',
            $wpdb->prefix . 'amelia_customer_bookings',
        ];
        $missing = [];
        foreach ( $required_tables as $table ) {
            $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
            if ( $found !== $table ) {
                $missing[] = $table;
            }
        }

        return [
            'ready'               => empty( $missing )
                && defined( 'AMELIA_API_KEY' )
                && (string) AMELIA_API_KEY !== ''
                && function_exists( '\\VirtualMD\\HeroBooking\\vm_amelia_get_slots_data' )
                && function_exists( '\\VirtualMD\\HeroBooking\\vm_paypal_prepare_customer_for_booking' ),
            'amelia_tables'       => empty( $missing ),
            'amelia_api_key'      => defined( 'AMELIA_API_KEY' ) && (string) AMELIA_API_KEY !== '',
            'availability_engine' => function_exists( '\\VirtualMD\\HeroBooking\\vm_amelia_get_slots_data' ),
            'isolated_identity'   => function_exists( '\\VirtualMD\\HeroBooking\\vm_paypal_prepare_customer_for_booking' ),
        ];
    }

    public function get_catalog() {
        global $wpdb;

        $cache_key = 'vmdpapi_catalog_v1';
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $categories = $wpdb->get_results(
            "SELECT id, name FROM {$wpdb->prefix}amelia_categories WHERE status = 'visible' ORDER BY position ASC",
            ARRAY_A
        ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $hidden = function_exists( '\\VirtualMD\\HeroBooking\\vmhb_get_hidden_category_ids' )
            ? array_map( 'intval', (array) \VirtualMD\HeroBooking\vmhb_get_hidden_category_ids() )
            : [];
        $output = [];

        foreach ( $categories as $category ) {
            $category_id = (int) $category['id'];
            if ( in_array( $category_id, $hidden, true ) ) {
                continue;
            }

            $services = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, name, price, duration FROM {$wpdb->prefix}amelia_services
                 WHERE categoryId = %d AND status = 'visible' ORDER BY position ASC",
                $category_id
            ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

            $clean_services = [];
            foreach ( $services as $service ) {
                $service_id = (int) $service['id'];
                if (
                    function_exists( '\\VirtualMD\\HeroBooking\\vm_amelia_service_has_availability' )
                    && ! \VirtualMD\HeroBooking\vm_amelia_service_has_availability( $service_id )
                ) {
                    continue;
                }
                $clean_services[] = [
                    'id'               => $service_id,
                    'name'             => sanitize_text_field( $service['name'] ),
                    'mode'             => 'Videoconsulta',
                    'duration_minutes' => max( 1, (int) ceil( (int) $service['duration'] / 60 ) ),
                    'list_price'       => (float) $service['price'],
                    'currency'         => 'MXN',
                ];
            }

            if ( ! empty( $clean_services ) ) {
                $output[] = [
                    'id'       => $category_id,
                    'name'     => sanitize_text_field( $category['name'] ),
                    'services' => $clean_services,
                ];
            }
        }

        set_transient( $cache_key, $output, 5 * MINUTE_IN_SECONDS );
        return $output;
    }

    public function get_service( $service_id ) {
        global $wpdb;

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT s.id, s.name, s.price, s.duration, s.categoryId, c.name AS categoryName
             FROM {$wpdb->prefix}amelia_services s
             INNER JOIN {$wpdb->prefix}amelia_categories c ON c.id = s.categoryId
             WHERE s.id = %d AND s.status = 'visible' AND c.status = 'visible' LIMIT 1",
            absint( $service_id )
        ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public function get_doctors( $service_id = 0 ) {
        global $wpdb;

        $service_id = absint( $service_id );
        $cache_key  = 'vmdpapi_doctors_v1_' . $service_id;
        $cached     = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        if ( $service_id ) {
            $providers = $wpdb->get_results( $wpdb->prepare(
                "SELECT DISTINCT u.id, u.firstName, u.lastName
                 FROM {$wpdb->prefix}amelia_users u
                 INNER JOIN {$wpdb->prefix}amelia_providers_to_services ps ON ps.userId = u.id
                 WHERE u.type = 'provider' AND u.status = 'visible' AND ps.serviceId = %d
                 ORDER BY u.firstName, u.lastName",
                $service_id
            ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        } else {
            $providers = $wpdb->get_results(
                "SELECT id, firstName, lastName FROM {$wpdb->prefix}amelia_users
                 WHERE type = 'provider' AND status = 'visible' ORDER BY firstName, lastName",
                ARRAY_A
            ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }

        $available           = [];
        $filter_availability = $service_id && function_exists( '\\VirtualMD\\HeroBooking\\vm_amelia_get_available_provider_ids_for_service' );
        if ( $filter_availability ) {
            $available = array_map( 'intval', \VirtualMD\HeroBooking\vm_amelia_get_available_provider_ids_for_service( $service_id ) );
        }

        $team_map = $this->team_member_map();
        $output   = [];
        foreach ( $providers as $provider ) {
            $provider_id = (int) $provider['id'];
            if ( $filter_availability && ! in_array( $provider_id, $available, true ) ) {
                continue;
            }

            $specialties = $wpdb->get_col( $wpdb->prepare(
                "SELECT s.name FROM {$wpdb->prefix}amelia_providers_to_services ps
                 INNER JOIN {$wpdb->prefix}amelia_services s ON s.id = ps.serviceId
                 WHERE ps.userId = %d AND s.status = 'visible' ORDER BY s.name",
                $provider_id
            ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $name = trim( $provider['firstName'] . ' ' . $provider['lastName'] );
            $team = isset( $team_map[ $this->normalize_name( $name ) ] ) ? $team_map[ $this->normalize_name( $name ) ] : [];

            $output[] = [
                'id'           => $provider_id,
                'name'         => sanitize_text_field( $name ),
                'specialties'  => array_values( array_unique( array_map( 'sanitize_text_field', $specialties ) ) ),
                'image_url'    => isset( $team['image_url'] ) ? $team['image_url'] : '',
                'profile_url'  => isset( $team['profile_url'] ) ? $team['profile_url'] : '',
                'title'        => isset( $team['title'] ) ? $team['title'] : '',
                'summary'      => isset( $team['summary'] ) ? $team['summary'] : '',
            ];
        }

        set_transient( $cache_key, $output, 5 * MINUTE_IN_SECONDS );
        return $output;
    }

    private function team_member_map() {
        if ( ! post_type_exists( 'ctshowcase_member' ) ) {
            return [];
        }
        $query = new \WP_Query( [
            'post_type'      => 'ctshowcase_member',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'no_found_rows'  => true,
        ] );
        $map = [];
        foreach ( $query->posts as $post ) {
            $map[ $this->normalize_name( $post->post_title ) ] = [
                'image_url'   => esc_url_raw( get_the_post_thumbnail_url( $post->ID, 'medium' ) ?: '' ),
                'profile_url' => esc_url_raw( get_permalink( $post->ID ) ),
                'title'       => sanitize_text_field( get_post_meta( $post->ID, 'ctshowcase_job_title', true ) ),
                'summary'     => sanitize_text_field( has_excerpt( $post->ID ) ? get_the_excerpt( $post->ID ) : wp_trim_words( wp_strip_all_tags( $post->post_content ), 32 ) ),
            ];
        }
        wp_reset_postdata();
        return $map;
    }

    private function normalize_name( $name ) {
        $name = preg_replace( '/^(Dr\\.?|Dra\\.?|Lic\\.?|Mtro\\.?)\s*/i', '', (string) $name );
        return strtolower( trim( remove_accents( preg_replace( '/\s+/', ' ', $name ) ) ) );
    }

    public function provider_serves_service( $provider_id, $service_id ) {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}amelia_providers_to_services ps
             INNER JOIN {$wpdb->prefix}amelia_users u ON u.id = ps.userId
             WHERE ps.userId = %d AND ps.serviceId = %d AND u.type = 'provider' AND u.status = 'visible' LIMIT 1",
            absint( $provider_id ),
            absint( $service_id )
        ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public function find_key_by_public_id( $public_id ) {
        global $wpdb;
        $table = $this->schema->table( 'keys' );
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE public_id = %s LIMIT 1", $public_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public function touch_key( $key_id ) {
        global $wpdb;
        $cache_key = 'vmdpapi_touch_' . absint( $key_id );
        if ( get_transient( $cache_key ) ) {
            return;
        }
        $wpdb->update( $this->schema->table( 'keys' ), [ 'last_used_at' => current_time( 'mysql', true ) ], [ 'id' => absint( $key_id ) ], [ '%s' ], [ '%d' ] );
        set_transient( $cache_key, 1, 5 * MINUTE_IN_SECONDS );
    }

    public function create_key( $name, $scopes, $rate_limit = 120, $allowed_ips = '', $daily_limit = 100, $monthly_limit = 1000, $reschedule_limit = 3 ) {
        global $wpdb;
        $name = trim( sanitize_text_field( $name ) );
        if ( '' === $name ) {
            return new \WP_Error( 'vmdpapi_name_required', 'El nombre del proveedor es obligatorio.' );
        }
        $public_id = bin2hex( random_bytes( 8 ) );
        $secret    = $this->base64url( random_bytes( 32 ) );
        $hash      = hash_hmac( 'sha256', $secret, wp_salt( 'auth' ) );
        $scopes    = $this->normalize_scopes( $scopes );
        if ( empty( $scopes ) ) {
            return new \WP_Error( 'vmdpapi_scopes_required', 'Selecciona al menos un permiso.' );
        }
        $inserted = $wpdb->insert( $this->schema->table( 'keys' ), [
            'public_id'   => $public_id,
            'name'        => $name,
            'secret_hash' => $hash,
            'scopes'      => implode( ',', $scopes ),
            'allowed_ips' => sanitize_textarea_field( $allowed_ips ),
            'rate_limit'  => max( 10, min( 5000, absint( $rate_limit ) ) ),
            'appointment_daily_limit'   => min( 100000, absint( $daily_limit ) ),
            'appointment_monthly_limit' => min( 1000000, absint( $monthly_limit ) ),
            'reschedule_limit'           => min( 1000, absint( $reschedule_limit ) ),
            'status'      => 'active',
            'created_at'  => current_time( 'mysql', true ),
        ], [ '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' ] );
        if ( ! $inserted ) {
            return new \WP_Error( 'vmdpapi_key_create_failed', 'No se pudo crear la credencial.' );
        }
        return [
            'id'         => (int) $wpdb->insert_id,
            'name'       => $name,
            'credential' => 'vmd_live_' . $public_id . '.' . $secret,
            'scopes'     => $scopes,
        ];
    }

    public function list_keys() {
        global $wpdb;
        $table = $this->schema->table( 'keys' );
        return $wpdb->get_results( "SELECT id, public_id, name, scopes, allowed_ips, rate_limit, appointment_daily_limit, appointment_monthly_limit, reschedule_limit, status, created_at, last_used_at, revoked_at FROM {$table} ORDER BY id DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public function update_key_settings( $key_id, $scopes, $allowed_ips, $rate_limit, $daily_limit, $monthly_limit, $reschedule_limit ) {
        global $wpdb;
        $scopes = $this->normalize_scopes( $scopes );
        if ( empty( $scopes ) ) {
            return new \WP_Error( 'vmdpapi_scopes_required', 'Selecciona al menos un permiso.' );
        }
        return false !== $wpdb->update( $this->schema->table( 'keys' ), [
            'scopes'                    => implode( ',', $scopes ),
            'allowed_ips'               => sanitize_textarea_field( $allowed_ips ),
            'rate_limit'                => max( 10, min( 5000, absint( $rate_limit ) ) ),
            'appointment_daily_limit'   => min( 100000, absint( $daily_limit ) ),
            'appointment_monthly_limit' => min( 1000000, absint( $monthly_limit ) ),
            'reschedule_limit'          => min( 1000, absint( $reschedule_limit ) ),
        ], [ 'id' => absint( $key_id ) ], [ '%s', '%s', '%d', '%d', '%d', '%d' ], [ '%d' ] );
    }

    public function appointment_creation_limit( $partner ) {
        global $wpdb;
        $table       = $this->schema->table( 'appointments' );
        $partner_id  = absint( $partner['id'] );
        $daily_limit = absint( $partner['appointment_daily_limit'] ?? 0 );
        $month_limit = absint( $partner['appointment_monthly_limit'] ?? 0 );
        $now         = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );

        if ( $daily_limit ) {
            $day_start = $now->setTime( 0, 0 )->format( 'Y-m-d H:i:s' );
            $daily     = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE partner_key_id = %d AND created_at >= %s",
                $partner_id,
                $day_start
            ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ( $daily >= $daily_limit ) {
                return new \WP_Error( 'vmdpapi_daily_booking_limit', 'La credencial alcanzó su límite diario de citas.', [ 'status' => 429 ] );
            }
        }

        if ( $month_limit ) {
            $month_start = $now->modify( 'first day of this month' )->setTime( 0, 0 )->format( 'Y-m-d H:i:s' );
            $monthly     = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE partner_key_id = %d AND created_at >= %s",
                $partner_id,
                $month_start
            ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ( $monthly >= $month_limit ) {
                return new \WP_Error( 'vmdpapi_monthly_booking_limit', 'La credencial alcanzó su límite mensual de citas.', [ 'status' => 429 ] );
            }
        }

        return true;
    }

    public function revoke_key( $key_id ) {
        global $wpdb;
        return false !== $wpdb->update( $this->schema->table( 'keys' ), [
            'status'     => 'revoked',
            'revoked_at' => current_time( 'mysql', true ),
        ], [ 'id' => absint( $key_id ) ], [ '%s', '%s' ], [ '%d' ] );
    }

    public function normalize_scopes( $scopes ) {
        $allowed = [ 'catalog:read', 'doctors:read', 'availability:read', 'appointments:write', 'appointments:read', 'appointments:reschedule' ];
        if ( is_string( $scopes ) ) {
            $scopes = preg_split( '/[\s,]+/', $scopes, -1, PREG_SPLIT_NO_EMPTY );
        }
        return array_values( array_intersect( $allowed, array_unique( array_map( 'sanitize_text_field', (array) $scopes ) ) ) );
    }

    private function base64url( $bytes ) {
        return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
    }

    public function claim_idempotency( $partner_id, $key, $request_hash ) {
        global $wpdb;
        $table = $this->schema->table( 'idempotency' );
        $now   = current_time( 'mysql', true );
        $insert_result = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$table}
             (partner_key_id,idempotency_key,request_hash,state,created_at,updated_at,expires_at)
             VALUES (%d,%s,%s,'processing',%s,%s,%s)",
            absint( $partner_id ),
            $key,
            $request_hash,
            $now,
            $now,
            gmdate( 'Y-m-d H:i:s', time() + 48 * HOUR_IN_SECONDS )
        ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $claimed = 1 === (int) $insert_result;
        $row     = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE partner_key_id = %d AND idempotency_key = %s LIMIT 1",
            absint( $partner_id ),
            $key
        ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        return [ 'claimed' => $claimed, 'row' => $row ];
    }

    public function complete_idempotency( $row_id, $state, $status, $response ) {
        global $wpdb;
        $wpdb->update( $this->schema->table( 'idempotency' ), [
            'state'         => sanitize_key( $state ),
            'http_status'   => absint( $status ),
            'response_json' => wp_json_encode( $response ),
            'updated_at'    => current_time( 'mysql', true ),
        ], [ 'id' => absint( $row_id ) ], [ '%s', '%d', '%s', '%s' ], [ '%d' ] );
    }

    public function release_idempotency( $row_id ) {
        global $wpdb;
        $wpdb->delete( $this->schema->table( 'idempotency' ), [ 'id' => absint( $row_id ) ], [ '%d' ] );
    }

    public function create_appointment_link( $data ) {
        global $wpdb;
        $inserted = $wpdb->insert( $this->schema->table( 'appointments' ), [
            'partner_key_id'      => absint( $data['partner_key_id'] ),
            'external_reference'  => sanitize_text_field( $data['external_reference'] ),
            'request_hash'        => $data['request_hash'],
            'patient_reference'   => sanitize_text_field( $data['patient_reference'] ),
            'amelia_booking_id'   => absint( $data['amelia_booking_id'] ),
            'amelia_appointment_id' => absint( $data['amelia_appointment_id'] ),
            'service_id'          => absint( $data['service_id'] ),
            'provider_id'         => absint( $data['provider_id'] ),
            'starts_at'           => $data['starts_at'],
            'status'              => sanitize_key( $data['status'] ),
            'created_at'          => current_time( 'mysql', true ),
            'updated_at'          => current_time( 'mysql', true ),
        ], [ '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ] );
        return $inserted ? (int) $wpdb->insert_id : 0;
    }

    public function find_appointment_link( $partner_id, $external_reference ) {
        global $wpdb;
        $table = $this->schema->table( 'appointments' );
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE partner_key_id = %d AND external_reference = %s LIMIT 1",
            absint( $partner_id ),
            $external_reference
        ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public function update_appointment_after_reschedule( $link_id, $starts_at, $provider_id, $appointment_id ) {
        global $wpdb;
        return false !== $wpdb->query( $wpdb->prepare(
            'UPDATE ' . $this->schema->table( 'appointments' ) . '
             SET starts_at = %s, provider_id = %d, amelia_appointment_id = %d,
                 reschedule_count = reschedule_count + 1, last_rescheduled_at = %s, updated_at = %s
             WHERE id = %d',
            $starts_at,
            absint( $provider_id ),
            absint( $appointment_id ),
            current_time( 'mysql', true ),
            current_time( 'mysql', true ),
            absint( $link_id )
        ) );
    }

    public function record_appointment_event( $link_id, $partner_id, $event_type, $request_id, $old_start = null, $new_start = null, $old_provider_id = 0, $new_provider_id = 0 ) {
        global $wpdb;
        return (bool) $wpdb->insert( $this->schema->table( 'events' ), [
            'appointment_link_id' => absint( $link_id ),
            'partner_key_id'      => absint( $partner_id ),
            'event_type'          => sanitize_key( $event_type ),
            'old_starts_at'       => $old_start,
            'new_starts_at'       => $new_start,
            'old_provider_id'     => absint( $old_provider_id ),
            'new_provider_id'     => absint( $new_provider_id ),
            'request_id'          => sanitize_text_field( $request_id ),
            'created_at'          => current_time( 'mysql', true ),
        ], [ '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ] );
    }

    public function count_appointments_for_admin( $partner_id = 0 ) {
        global $wpdb;
        $table = $this->schema->table( 'appointments' );
        if ( $partner_id ) {
            return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE partner_key_id = %d", absint( $partner_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public function list_appointments_for_admin( $partner_id = 0, $limit = 50, $offset = 0 ) {
        global $wpdb;
        $appointments = $this->schema->table( 'appointments' );
        $keys         = $this->schema->table( 'keys' );
        $where        = '';
        if ( $partner_id ) {
            $where = $wpdb->prepare( 'WHERE a.partner_key_id = %d', absint( $partner_id ) );
        }
        $limit = max( 1, min( 1000, absint( $limit ) ) );
        $offset = absint( $offset );
        return $wpdb->get_results(
            "SELECT a.*, k.name AS partner_name, s.name AS service_name,
                    CONCAT(u.firstName, ' ', u.lastName) AS provider_name,
                    COALESCE(cb.status, aa.status, a.status) AS current_status
             FROM {$appointments} a
             INNER JOIN {$keys} k ON k.id = a.partner_key_id
             LEFT JOIN {$wpdb->prefix}amelia_services s ON s.id = a.service_id
             LEFT JOIN {$wpdb->prefix}amelia_users u ON u.id = a.provider_id
             LEFT JOIN {$wpdb->prefix}amelia_appointments aa ON aa.id = a.amelia_appointment_id
             LEFT JOIN {$wpdb->prefix}amelia_customer_bookings cb ON cb.id = a.amelia_booking_id
             {$where}
             ORDER BY a.created_at DESC, a.id DESC LIMIT {$limit} OFFSET {$offset}",
            ARRAY_A
        ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public function list_appointment_events_for_admin( $appointment_link_id ) {
        global $wpdb;
        $table = $this->schema->table( 'events' );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE appointment_link_id = %d ORDER BY id DESC",
            absint( $appointment_link_id )
        ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    public function appointment_status( $link ) {
        global $wpdb;
        $status = $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(cb.status, a.status)
             FROM {$wpdb->prefix}amelia_appointments a
             LEFT JOIN {$wpdb->prefix}amelia_customer_bookings cb ON cb.appointmentId = a.id AND cb.id = %d
             WHERE a.id = %d LIMIT 1",
            absint( $link['amelia_booking_id'] ),
            absint( $link['amelia_appointment_id'] )
        ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $status ? sanitize_key( $status ) : sanitize_key( $link['status'] );
    }

    public function audit( $partner_id, $request_id, $action, $status, $remote_ip, $reference = '' ) {
        global $wpdb;
        $wpdb->insert( $this->schema->table( 'audit' ), [
            'partner_key_id'    => absint( $partner_id ),
            'request_id'        => sanitize_text_field( $request_id ),
            'action'            => sanitize_key( $action ),
            'resource_reference'=> sanitize_text_field( $reference ),
            'http_status'       => absint( $status ),
            'ip_hash'           => hash_hmac( 'sha256', (string) $remote_ip, wp_salt( 'nonce' ) ),
            'created_at'        => current_time( 'mysql', true ),
        ], [ '%d', '%s', '%s', '%s', '%d', '%s', '%s' ] );
    }
}
