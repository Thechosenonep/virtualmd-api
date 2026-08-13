<?php

namespace VirtualMD\PartnerAPI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Rest_Controller {
    private $repository;
    private $auth;
    private $amelia;

    public function __construct( Repository $repository, Auth $auth, Amelia_Gateway $amelia ) {
        $this->repository = $repository;
        $this->auth       = $auth;
        $this->amelia     = $amelia;
    }

    public function register_routes() {
        register_rest_route( VMDPAPI_REST_NAMESPACE, '/health', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'health' ],
            'permission_callback' => $this->auth->permission( 'catalog:read' ),
        ] );
        register_rest_route( VMDPAPI_REST_NAMESPACE, '/catalog', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'catalog' ],
            'permission_callback' => $this->auth->permission( 'catalog:read' ),
        ] );
        register_rest_route( VMDPAPI_REST_NAMESPACE, '/doctors', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'doctors' ],
            'permission_callback' => $this->auth->permission( 'doctors:read' ),
        ] );
        register_rest_route( VMDPAPI_REST_NAMESPACE, '/availability', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'availability' ],
            'permission_callback' => $this->auth->permission( 'availability:read' ),
        ] );
        register_rest_route( VMDPAPI_REST_NAMESPACE, '/appointments', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'create_appointment' ],
            'permission_callback' => $this->auth->permission( 'appointments:write' ),
        ] );
        register_rest_route( VMDPAPI_REST_NAMESPACE, '/appointments/(?P<external_reference>[A-Za-z0-9._:-]{1,100})', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_appointment' ],
            'permission_callback' => $this->auth->permission( 'appointments:read' ),
        ] );
        register_rest_route( VMDPAPI_REST_NAMESPACE, '/appointments/(?P<external_reference>[A-Za-z0-9._:-]{1,100})/reschedule', [
            'methods'             => 'PATCH',
            'callback'            => [ $this, 'reschedule_appointment' ],
            'permission_callback' => $this->auth->permission( 'appointments:reschedule' ),
        ] );
    }

    public function health( $request ) {
        $status = $this->repository->dependency_status();
        return $this->respond( $request, [
            'status'       => $status['ready'] ? 'ready' : 'degraded',
            'version'      => VMDPAPI_VERSION,
            'dependencies' => $status,
        ], $status['ready'] ? 200 : 503, 'health' );
    }

    public function catalog( $request ) {
        if ( ! $this->repository->dependency_status()['amelia_tables'] ) {
            return $this->error_response( $request, 'vmdpapi_amelia_unavailable', 'Amelia no está disponible.', 503, 'catalog' );
        }
        return $this->respond( $request, $this->repository->get_catalog(), 200, 'catalog' );
    }

    public function doctors( $request ) {
        if ( null !== $request->get_param( 'service_id' ) && ! is_scalar( $request->get_param( 'service_id' ) ) ) {
            return $this->error_response( $request, 'vmdpapi_invalid_parameter', 'service_id debe ser un entero.', 400, 'doctors' );
        }
        if ( null !== $request->get_param( 'service_id' ) && '' !== (string) $request->get_param( 'service_id' ) && ! preg_match( '/^[1-9]\d*$/D', (string) $request->get_param( 'service_id' ) ) ) {
            return $this->error_response( $request, 'vmdpapi_invalid_parameter', 'service_id debe ser un entero positivo.', 400, 'doctors' );
        }
        $service_id = absint( $request->get_param( 'service_id' ) );
        if ( $service_id && ! $this->repository->get_service( $service_id ) ) {
            return $this->error_response( $request, 'vmdpapi_service_not_found', 'Servicio no encontrado.', 404, 'doctors' );
        }
        return $this->respond( $request, $this->repository->get_doctors( $service_id ), 200, 'doctors' );
    }

    public function availability( $request ) {
        foreach ( [ 'service_id', 'provider_id', 'from', 'to' ] as $parameter ) {
            if ( null !== $request->get_param( $parameter ) && ! is_scalar( $request->get_param( $parameter ) ) ) {
                return $this->error_response( $request, 'vmdpapi_invalid_parameter', $parameter . ' tiene un formato inválido.', 400, 'availability' );
            }
        }
        if ( ! preg_match( '/^[1-9]\d*$/D', (string) $request->get_param( 'service_id' ) ) ) {
            return $this->error_response( $request, 'vmdpapi_invalid_parameter', 'service_id debe ser un entero positivo.', 400, 'availability' );
        }
        if ( null !== $request->get_param( 'provider_id' ) && '' !== (string) $request->get_param( 'provider_id' ) && ! preg_match( '/^[1-9]\d*$/D', (string) $request->get_param( 'provider_id' ) ) ) {
            return $this->error_response( $request, 'vmdpapi_invalid_parameter', 'provider_id debe ser un entero positivo.', 400, 'availability' );
        }
        $service_id  = absint( $request->get_param( 'service_id' ) );
        $provider_id = absint( $request->get_param( 'provider_id' ) );
        if ( ! $service_id ) {
            return $this->error_response( $request, 'vmdpapi_service_required', 'service_id es obligatorio.', 400, 'availability' );
        }
        if ( ! $this->repository->get_service( $service_id ) ) {
            return $this->error_response( $request, 'vmdpapi_service_not_found', 'Servicio no encontrado.', 404, 'availability' );
        }
        if ( $provider_id && ! $this->repository->provider_serves_service( $provider_id, $service_id ) ) {
            return $this->error_response( $request, 'vmdpapi_provider_not_found', 'El doctor no atiende este servicio.', 404, 'availability' );
        }

        $timezone = wp_timezone();
        $today    = new \DateTimeImmutable( 'today', $timezone );
        $from     = $request->get_param( 'from' ) ?: $today->format( 'Y-m-d' );
        $to       = $request->get_param( 'to' ) ?: $today->modify( '+6 days' )->format( 'Y-m-d' );
        $from_dt  = $this->strict_date( $from );
        $to_dt    = $this->strict_date( $to );
        $maximum  = $today->modify( '+3 months' );

        if ( ! $from_dt || ! $to_dt || $from_dt < $today || $to_dt < $from_dt || $to_dt > $maximum || $from_dt->diff( $to_dt )->days > 30 ) {
            return $this->error_response(
                $request,
                'vmdpapi_invalid_date_range',
                'Usa fechas YYYY-MM-DD, hasta 31 días dentro de los próximos 3 meses.',
                400,
                'availability'
            );
        }

        $result = $this->amelia->availability( $service_id, $provider_id, $from_dt->format( 'Y-m-d' ), $to_dt->format( 'Y-m-d' ) );
        if ( is_wp_error( $result ) ) {
            return $this->wp_error_response( $request, $result, 'availability' );
        }
        return $this->respond( $request, $result, 200, 'availability' );
    }

    public function create_appointment( $request ) {
        $partner   = $this->attribute( $request, 'vmdpapi_partner' );
        $request_id= $this->request_id( $request );
        $input     = $this->normalize_booking_input( $request->get_json_params() );
        if ( is_wp_error( $input ) ) {
            return $this->wp_error_response( $request, $input, 'appointment_create' );
        }

        $idempotency_key = trim( (string) $request->get_header( 'Idempotency-Key' ) );
        if ( ! preg_match( '/^[A-Za-z0-9._:-]{16,128}$/D', $idempotency_key ) ) {
            return $this->error_response(
                $request,
                'vmdpapi_idempotency_required',
                'Idempotency-Key es obligatorio (16 a 128 caracteres seguros).',
                400,
                'appointment_create',
                $input['external_reference']
            );
        }

        $hash_data             = $input;
        $hash_data['starts_at']= $input['starts_at']->format( \DateTimeInterface::ATOM );
        $request_hash          = hash( 'sha256', wp_json_encode( $this->canonicalize( $hash_data ) ) );
        $claim                 = $this->repository->claim_idempotency( (int) $partner['id'], $idempotency_key, $request_hash );
        $idem_row              = $claim['row'];

        if ( ! $claim['claimed'] ) {
            if ( ! hash_equals( $idem_row['request_hash'], $request_hash ) ) {
                return $this->error_response( $request, 'vmdpapi_idempotency_conflict', 'La clave de idempotencia ya fue usada con otros datos.', 409, 'appointment_create', $input['external_reference'] );
            }
            if ( 'processing' === $idem_row['state'] ) {
                return $this->error_response( $request, 'vmdpapi_request_processing', 'Una solicitud idéntica sigue en proceso.', 409, 'appointment_create', $input['external_reference'] );
            }
            $stored   = json_decode( (string) $idem_row['response_json'], true );
            $response = new \WP_REST_Response( is_array( $stored ) ? $stored : [], (int) $idem_row['http_status'] );
            $response->header( 'Idempotency-Replayed', 'true' );
            $response->header( 'X-Request-Id', $request_id );
            $response->header( 'Cache-Control', 'no-store' );
            $this->repository->audit( (int) $partner['id'], $request_id, 'appointment_replay', (int) $idem_row['http_status'], $this->auth->remote_ip(), $input['external_reference'] );
            return $response;
        }

        $existing = $this->repository->find_appointment_link( (int) $partner['id'], $input['external_reference'] );
        if ( $existing ) {
            if ( ! hash_equals( $existing['request_hash'], $request_hash ) ) {
                return $this->finish_idempotent_error( $request, $idem_row, 'vmdpapi_external_reference_conflict', 'external_reference ya existe con otros datos.', 409, $input['external_reference'] );
            }
            $data = $this->appointment_resource( $existing );
            return $this->finish_idempotent_success( $request, $idem_row, $data, 200, $input['external_reference'] );
        }

        $partner_lock = $this->amelia->acquire_partner_create_lock( $partner['id'] );
        if ( ! $partner_lock ) {
            return $this->finish_idempotent_retryable_error( $request, $idem_row, 'vmdpapi_partner_busy', 'La integración está procesando otra cita. Reintenta con la misma Idempotency-Key.', 409, $input['external_reference'] );
        }

        try {

        $existing = $this->repository->find_appointment_link( (int) $partner['id'], $input['external_reference'] );
        if ( $existing ) {
            if ( ! hash_equals( $existing['request_hash'], $request_hash ) ) {
                return $this->finish_idempotent_error( $request, $idem_row, 'vmdpapi_external_reference_conflict', 'external_reference ya existe con otros datos.', 409, $input['external_reference'] );
            }
            return $this->finish_idempotent_success( $request, $idem_row, $this->appointment_resource( $existing ), 200, $input['external_reference'] );
        }

        $creation_limit = $this->repository->appointment_creation_limit( $partner );
        if ( is_wp_error( $creation_limit ) ) {
            return $this->finish_idempotent_wp_error( $request, $idem_row, $creation_limit, $input['external_reference'] );
        }

        $service = $this->repository->get_service( $input['service_id'] );
        if ( ! $service ) {
            return $this->finish_idempotent_error( $request, $idem_row, 'vmdpapi_service_not_found', 'Servicio no encontrado.', 404, $input['external_reference'] );
        }
        if ( $input['provider_id'] && ! $this->repository->provider_serves_service( $input['provider_id'], $input['service_id'] ) ) {
            return $this->finish_idempotent_error( $request, $idem_row, 'vmdpapi_provider_not_found', 'El doctor no atiende este servicio.', 404, $input['external_reference'] );
        }

        $provider_id = $this->amelia->provider_for_slot( $input['service_id'], $input['provider_id'], $input['starts_at'] );
        if ( is_wp_error( $provider_id ) ) {
            return $this->finish_idempotent_wp_error( $request, $idem_row, $provider_id, $input['external_reference'] );
        }

        $lock = $this->amelia->acquire_slot_lock( $input['service_id'], $provider_id, $input['starts_at'] );
        if ( ! $lock ) {
            return $this->finish_idempotent_retryable_error( $request, $idem_row, 'vmdpapi_slot_busy', 'El horario está siendo reservado. Reintenta con la misma Idempotency-Key.', 409, $input['external_reference'] );
        }

        try {
            $existing = $this->repository->find_appointment_link( (int) $partner['id'], $input['external_reference'] );
            if ( $existing ) {
                if ( ! hash_equals( $existing['request_hash'], $request_hash ) ) {
                    return $this->finish_idempotent_error( $request, $idem_row, 'vmdpapi_external_reference_conflict', 'external_reference ya existe con otros datos.', 409, $input['external_reference'] );
                }
                return $this->finish_idempotent_success( $request, $idem_row, $this->appointment_resource( $existing ), 200, $input['external_reference'] );
            }

            $confirmed_provider = $this->amelia->provider_for_slot( $input['service_id'], $provider_id, $input['starts_at'] );
            if ( is_wp_error( $confirmed_provider ) ) {
                return $this->finish_idempotent_wp_error( $request, $idem_row, $confirmed_provider, $input['external_reference'] );
            }

            $created = $this->amelia->create_booking( $input, $service, $provider_id, $partner );
            if ( is_wp_error( $created ) ) {
                return $this->finish_idempotent_wp_error( $request, $idem_row, $created, $input['external_reference'] );
            }

            $link_id = $this->repository->create_appointment_link( [
                'partner_key_id'       => (int) $partner['id'],
                'external_reference'   => $input['external_reference'],
                'request_hash'         => $request_hash,
                'patient_reference'    => $input['patient_reference'],
                'amelia_booking_id'    => $created['booking_id'],
                'amelia_appointment_id'=> $created['appointment_id'],
                'service_id'           => $input['service_id'],
                'provider_id'          => $provider_id,
                'starts_at'            => $input['starts_at']->format( 'Y-m-d H:i:s' ),
                'status'               => $created['status'],
            ] );
            if ( ! $link_id ) {
                return $this->finish_idempotent_error(
                    $request,
                    $idem_row,
                    'vmdpapi_registry_error',
                    'La cita fue enviada a Amelia, pero falló el registro local. Contacta a VirtualMD con X-Request-Id; no reintentes con otra referencia.',
                    500,
                    $input['external_reference'],
                    'indeterminate'
                );
            }

            $this->repository->record_appointment_event(
                $link_id,
                (int) $partner['id'],
                'created',
                $request_id,
                null,
                $input['starts_at']->format( 'Y-m-d H:i:s' ),
                0,
                $provider_id
            );

            $link = $this->repository->find_appointment_link( (int) $partner['id'], $input['external_reference'] );
            return $this->finish_idempotent_success( $request, $idem_row, $this->appointment_resource( $link ), 201, $input['external_reference'] );
        } finally {
            $this->amelia->release_slot_lock( $lock );
        }
        } finally {
            $this->amelia->release_slot_lock( $partner_lock );
        }
    }

    public function get_appointment( $request ) {
        $partner   = $this->attribute( $request, 'vmdpapi_partner' );
        $reference = sanitize_text_field( $request->get_param( 'external_reference' ) );
        $link      = $this->repository->find_appointment_link( (int) $partner['id'], $reference );
        if ( ! $link ) {
            return $this->error_response( $request, 'vmdpapi_appointment_not_found', 'Cita no encontrada.', 404, 'appointment_read', $reference );
        }
        return $this->respond( $request, $this->appointment_resource( $link ), 200, 'appointment_read', $reference );
    }

    public function reschedule_appointment( $request ) {
        $partner    = $this->attribute( $request, 'vmdpapi_partner' );
        $reference  = sanitize_text_field( $request->get_param( 'external_reference' ) );
        $request_id = $this->request_id( $request );
        $input      = $this->normalize_reschedule_input( $request->get_json_params() );
        if ( is_wp_error( $input ) ) {
            return $this->wp_error_response( $request, $input, 'appointment_reschedule', $reference );
        }

        $idempotency_key = trim( (string) $request->get_header( 'Idempotency-Key' ) );
        if ( ! preg_match( '/^[A-Za-z0-9._:-]{16,128}$/D', $idempotency_key ) ) {
            return $this->error_response( $request, 'vmdpapi_idempotency_required', 'Idempotency-Key es obligatorio (16 a 128 caracteres seguros).', 400, 'appointment_reschedule', $reference );
        }

        $hash_data = [
            'action'             => 'reschedule',
            'external_reference' => $reference,
            'starts_at'          => $input['starts_at']->format( \DateTimeInterface::ATOM ),
            'provider_id'        => $input['provider_id'],
        ];
        $request_hash = hash( 'sha256', wp_json_encode( $this->canonicalize( $hash_data ) ) );
        $claim        = $this->repository->claim_idempotency( (int) $partner['id'], $idempotency_key, $request_hash );
        $idem_row     = $claim['row'];

        if ( ! $claim['claimed'] ) {
            if ( ! hash_equals( $idem_row['request_hash'], $request_hash ) ) {
                return $this->error_response( $request, 'vmdpapi_idempotency_conflict', 'La clave de idempotencia ya fue usada con otros datos.', 409, 'appointment_reschedule', $reference );
            }
            if ( 'processing' === $idem_row['state'] ) {
                return $this->error_response( $request, 'vmdpapi_request_processing', 'Una reprogramación idéntica sigue en proceso.', 409, 'appointment_reschedule', $reference );
            }
            $stored   = json_decode( (string) $idem_row['response_json'], true );
            $response = new \WP_REST_Response( is_array( $stored ) ? $stored : [], (int) $idem_row['http_status'] );
            $response->header( 'Idempotency-Replayed', 'true' );
            $response->header( 'X-Request-Id', $request_id );
            $response->header( 'Cache-Control', 'no-store' );
            $this->repository->audit( (int) $partner['id'], $request_id, 'reschedule_replay', (int) $idem_row['http_status'], $this->auth->remote_ip(), $reference );
            return $response;
        }

        $link = $this->repository->find_appointment_link( (int) $partner['id'], $reference );
        if ( ! $link ) {
            return $this->finish_idempotent_error( $request, $idem_row, 'vmdpapi_appointment_not_found', 'Cita no encontrada.', 404, $reference, 'failed', 'appointment_reschedule' );
        }

        $current_status = $this->repository->appointment_status( $link );
        if ( in_array( $current_status, [ 'canceled', 'cancelled', 'rejected' ], true ) ) {
            return $this->finish_idempotent_error( $request, $idem_row, 'vmdpapi_appointment_not_reschedulable', 'La cita ya no se puede reprogramar por su estado actual.', 409, $reference, 'failed', 'appointment_reschedule' );
        }
        $old_start = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $link['starts_at'], wp_timezone() );
        if ( ! $old_start || $old_start <= new \DateTimeImmutable( 'now', wp_timezone() ) ) {
            return $this->finish_idempotent_error( $request, $idem_row, 'vmdpapi_appointment_in_past', 'Una cita pasada no se puede reprogramar.', 409, $reference, 'failed', 'appointment_reschedule' );
        }

        $reschedule_limit = absint( $partner['reschedule_limit'] ?? 0 );
        if ( $reschedule_limit && absint( $link['reschedule_count'] ?? 0 ) >= $reschedule_limit ) {
            return $this->finish_idempotent_error( $request, $idem_row, 'vmdpapi_reschedule_limit', 'La cita alcanzó el límite de reprogramaciones de esta credencial.', 429, $reference, 'failed', 'appointment_reschedule' );
        }
        if (
            $old_start->format( 'Y-m-d H:i' ) === $input['starts_at']->format( 'Y-m-d H:i' )
            && ( ! $input['provider_id'] || absint( $link['provider_id'] ) === $input['provider_id'] )
        ) {
            return $this->finish_idempotent_error( $request, $idem_row, 'vmdpapi_no_reschedule_change', 'El nuevo horario y doctor son iguales a los actuales.', 409, $reference, 'failed', 'appointment_reschedule' );
        }

        $appointment_lock = $this->amelia->acquire_appointment_lock( $link['amelia_booking_id'] );
        if ( ! $appointment_lock ) {
            return $this->finish_idempotent_retryable_error( $request, $idem_row, 'vmdpapi_appointment_busy', 'La cita está siendo modificada. Reintenta con la misma Idempotency-Key.', 409, $reference, 'appointment_reschedule' );
        }

        try {
            // Re-read mutable state after acquiring the per-appointment lock.
            $link           = $this->repository->find_appointment_link( (int) $partner['id'], $reference );
            $current_status = $this->repository->appointment_status( $link );
            $old_start      = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $link['starts_at'], wp_timezone() );
            if ( in_array( $current_status, [ 'canceled', 'cancelled', 'rejected' ], true ) || ! $old_start || $old_start <= new \DateTimeImmutable( 'now', wp_timezone() ) ) {
                return $this->finish_idempotent_error( $request, $idem_row, 'vmdpapi_appointment_not_reschedulable', 'La cita ya no se puede reprogramar por su estado actual.', 409, $reference, 'failed', 'appointment_reschedule' );
            }
            if ( $reschedule_limit && absint( $link['reschedule_count'] ?? 0 ) >= $reschedule_limit ) {
                return $this->finish_idempotent_error( $request, $idem_row, 'vmdpapi_reschedule_limit', 'La cita alcanzó el límite de reprogramaciones de esta credencial.', 429, $reference, 'failed', 'appointment_reschedule' );
            }
            if (
                $old_start->format( 'Y-m-d H:i' ) === $input['starts_at']->format( 'Y-m-d H:i' )
                && ( ! $input['provider_id'] || absint( $link['provider_id'] ) === $input['provider_id'] )
            ) {
                return $this->finish_idempotent_error( $request, $idem_row, 'vmdpapi_no_reschedule_change', 'El nuevo horario y doctor son iguales a los actuales.', 409, $reference, 'failed', 'appointment_reschedule' );
            }

            if ( $input['provider_id'] && ! $this->repository->provider_serves_service( $input['provider_id'], $link['service_id'] ) ) {
                return $this->finish_idempotent_error( $request, $idem_row, 'vmdpapi_provider_not_found', 'El doctor no atiende este servicio.', 404, $reference, 'failed', 'appointment_reschedule' );
            }
            $provider_id = $this->amelia->provider_for_slot( $link['service_id'], $input['provider_id'], $input['starts_at'] );
            if ( is_wp_error( $provider_id ) ) {
                return $this->finish_idempotent_wp_error( $request, $idem_row, $provider_id, $reference, 'appointment_reschedule' );
            }

            $lock = $this->amelia->acquire_slot_lock( $link['service_id'], $provider_id, $input['starts_at'] );
            if ( ! $lock ) {
                return $this->finish_idempotent_retryable_error( $request, $idem_row, 'vmdpapi_slot_busy', 'El horario está siendo reservado. Reintenta con la misma Idempotency-Key.', 409, $reference, 'appointment_reschedule' );
            }

            try {
                $confirmed_provider = $this->amelia->provider_for_slot( $link['service_id'], $provider_id, $input['starts_at'] );
                if ( is_wp_error( $confirmed_provider ) ) {
                    return $this->finish_idempotent_wp_error( $request, $idem_row, $confirmed_provider, $reference, 'appointment_reschedule' );
                }
                $result = $this->amelia->reassign_booking( $link, $input['starts_at'], $provider_id );
                if ( is_wp_error( $result ) ) {
                    return $this->finish_idempotent_wp_error( $request, $idem_row, $result, $reference, 'appointment_reschedule' );
                }

                $updated = $this->repository->update_appointment_after_reschedule(
                $link['id'],
                $input['starts_at']->format( 'Y-m-d H:i:s' ),
                $result['provider_id'],
                $result['appointment_id']
            );
                if ( ! $updated ) {
                    return $this->finish_idempotent_error(
                    $request,
                    $idem_row,
                    'vmdpapi_registry_error',
                    'Amelia confirmó el cambio, pero falló el registro local. Contacta a VirtualMD con X-Request-Id y no uses otra referencia.',
                    500,
                    $reference,
                    'indeterminate',
                    'appointment_reschedule'
                    );
                }

                $this->repository->record_appointment_event(
                $link['id'],
                (int) $partner['id'],
                'rescheduled',
                $request_id,
                $old_start->format( 'Y-m-d H:i:s' ),
                $input['starts_at']->format( 'Y-m-d H:i:s' ),
                $link['provider_id'],
                $result['provider_id']
                );
                $updated_link = $this->repository->find_appointment_link( (int) $partner['id'], $reference );
                return $this->finish_idempotent_success( $request, $idem_row, $this->appointment_resource( $updated_link ), 200, $reference, 'appointment_reschedule' );
            } finally {
                $this->amelia->release_slot_lock( $lock );
            }
        } finally {
            $this->amelia->release_slot_lock( $appointment_lock );
        }
    }

    private function normalize_reschedule_input( $body ) {
        if ( ! is_array( $body ) ) {
            return new \WP_Error( 'vmdpapi_invalid_json', 'El cuerpo debe ser JSON válido.', [ 'status' => 400 ] );
        }
        if ( array_diff( array_keys( $body ), [ 'starts_at', 'provider_id' ] ) ) {
            return new \WP_Error( 'vmdpapi_unknown_fields', 'El cuerpo contiene campos no permitidos.', [ 'status' => 400 ] );
        }
        if ( ! isset( $body['starts_at'] ) || ! is_scalar( $body['starts_at'] ) ) {
            return new \WP_Error( 'vmdpapi_invalid_start', 'starts_at es obligatorio.', [ 'status' => 400 ] );
        }
        if ( isset( $body['provider_id'] ) && ( ! is_scalar( $body['provider_id'] ) || ! preg_match( '/^[1-9]\d*$/D', (string) $body['provider_id'] ) ) ) {
            return new \WP_Error( 'vmdpapi_invalid_provider', 'provider_id debe ser un entero positivo.', [ 'status' => 400 ] );
        }
        $starts_at = trim( (string) $body['starts_at'] );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})$/D', $starts_at ) ) {
            return new \WP_Error( 'vmdpapi_invalid_start', 'starts_at debe ser RFC 3339 e incluir zona horaria.', [ 'status' => 400 ] );
        }
        try {
            $start = ( new \DateTimeImmutable( $starts_at ) )->setTimezone( wp_timezone() );
        } catch ( \Exception $exception ) {
            return new \WP_Error( 'vmdpapi_invalid_start', 'starts_at no es válido.', [ 'status' => 400 ] );
        }
        $now     = new \DateTimeImmutable( 'now', wp_timezone() );
        $maximum = new \DateTimeImmutable( '+3 months', wp_timezone() );
        if ( '00' !== $start->format( 's' ) || $start <= $now || $start > $maximum ) {
            return new \WP_Error( 'vmdpapi_invalid_start', 'starts_at debe ser un minuto futuro dentro de los próximos 3 meses.', [ 'status' => 400 ] );
        }
        return [
            'starts_at'   => $start,
            'provider_id' => isset( $body['provider_id'] ) ? absint( $body['provider_id'] ) : 0,
        ];
    }

    private function normalize_booking_input( $body ) {
        if ( ! is_array( $body ) ) {
            return new \WP_Error( 'vmdpapi_invalid_json', 'El cuerpo debe ser JSON válido.', [ 'status' => 400 ] );
        }
        $allowed = [ 'external_reference', 'patient_reference', 'service_id', 'provider_id', 'starts_at', 'patient', 'consent', 'reason' ];
        if ( array_diff( array_keys( $body ), $allowed ) ) {
            return new \WP_Error( 'vmdpapi_unknown_fields', 'El cuerpo contiene campos no permitidos.', [ 'status' => 400 ] );
        }
        foreach ( [ 'external_reference', 'patient_reference', 'service_id', 'provider_id', 'starts_at', 'reason' ] as $field ) {
            if ( isset( $body[ $field ] ) && ! is_scalar( $body[ $field ] ) ) {
                return new \WP_Error( 'vmdpapi_invalid_field_type', $field . ' tiene un tipo inválido.', [ 'status' => 400 ] );
            }
        }

        $external_reference = isset( $body['external_reference'] ) ? trim( (string) $body['external_reference'] ) : '';
        if ( ! preg_match( '/^[A-Za-z0-9._:-]{1,100}$/D', $external_reference ) ) {
            return new \WP_Error( 'vmdpapi_invalid_reference', 'external_reference es inválida.', [ 'status' => 400 ] );
        }
        $patient_reference = isset( $body['patient_reference'] ) ? trim( (string) $body['patient_reference'] ) : '';
        if ( strlen( $patient_reference ) > 100 ) {
            return new \WP_Error( 'vmdpapi_invalid_patient_reference', 'patient_reference excede 100 caracteres.', [ 'status' => 400 ] );
        }

        if ( ! isset( $body['service_id'] ) || ! preg_match( '/^[1-9]\d*$/D', (string) $body['service_id'] ) ) {
            return new \WP_Error( 'vmdpapi_service_required', 'service_id es obligatorio.', [ 'status' => 400 ] );
        }
        if ( isset( $body['provider_id'] ) && ! preg_match( '/^[1-9]\d*$/D', (string) $body['provider_id'] ) ) {
            return new \WP_Error( 'vmdpapi_invalid_provider', 'provider_id debe ser un entero positivo.', [ 'status' => 400 ] );
        }
        $service_id  = absint( $body['service_id'] );
        $provider_id = isset( $body['provider_id'] ) ? absint( $body['provider_id'] ) : 0;

        $starts_at = isset( $body['starts_at'] ) ? trim( (string) $body['starts_at'] ) : '';
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})$/D', $starts_at ) ) {
            return new \WP_Error( 'vmdpapi_invalid_start', 'starts_at debe ser RFC 3339 e incluir zona horaria.', [ 'status' => 400 ] );
        }
        try {
            $start = ( new \DateTimeImmutable( $starts_at ) )->setTimezone( wp_timezone() );
        } catch ( \Exception $exception ) {
            return new \WP_Error( 'vmdpapi_invalid_start', 'starts_at no es válido.', [ 'status' => 400 ] );
        }
        $now     = new \DateTimeImmutable( 'now', wp_timezone() );
        $maximum = new \DateTimeImmutable( '+3 months', wp_timezone() );
        if ( '00' !== $start->format( 's' ) || $start <= $now || $start > $maximum ) {
            return new \WP_Error( 'vmdpapi_invalid_start', 'starts_at debe ser un minuto futuro dentro de los próximos 3 meses.', [ 'status' => 400 ] );
        }

        $patient = isset( $body['patient'] ) && is_array( $body['patient'] ) ? $body['patient'] : [];
        if ( array_diff( array_keys( $patient ), [ 'first_name', 'last_name', 'email', 'phone', 'country_phone_iso', 'birth_date' ] ) ) {
            return new \WP_Error( 'vmdpapi_invalid_patient', 'patient contiene campos no permitidos.', [ 'status' => 400 ] );
        }
        foreach ( $patient as $field => $value ) {
            if ( ! is_scalar( $value ) ) {
                return new \WP_Error( 'vmdpapi_invalid_patient', 'patient.' . $field . ' tiene un tipo inválido.', [ 'status' => 400 ] );
            }
        }
        $first_name = isset( $patient['first_name'] ) ? trim( sanitize_text_field( $patient['first_name'] ) ) : '';
        $last_name  = isset( $patient['last_name'] ) ? trim( sanitize_text_field( $patient['last_name'] ) ) : '';
        $email      = isset( $patient['email'] ) ? sanitize_email( $patient['email'] ) : '';
        $phone      = isset( $patient['phone'] ) ? trim( (string) $patient['phone'] ) : '';
        $country    = isset( $patient['country_phone_iso'] ) ? strtoupper( trim( (string) $patient['country_phone_iso'] ) ) : 'MX';
        if ( '' === $first_name || '' === $last_name || strlen( $first_name ) > 80 || strlen( $last_name ) > 80 ) {
            return new \WP_Error( 'vmdpapi_invalid_patient_name', 'Nombre y apellidos son obligatorios (máximo 80 caracteres).', [ 'status' => 400 ] );
        }
        if ( ! is_email( $email ) ) {
            return new \WP_Error( 'vmdpapi_invalid_email', 'El correo del paciente no es válido.', [ 'status' => 400 ] );
        }
        if ( ! preg_match( '/^\+[1-9]\d{7,14}$/D', $phone ) ) {
            return new \WP_Error( 'vmdpapi_invalid_phone', 'El teléfono debe usar formato E.164, por ejemplo +525512345678.', [ 'status' => 400 ] );
        }
        if ( ! preg_match( '/^[A-Z]{2}$/D', $country ) ) {
            return new \WP_Error( 'vmdpapi_invalid_country', 'country_phone_iso debe tener dos letras.', [ 'status' => 400 ] );
        }

        $birth_date = isset( $patient['birth_date'] ) ? trim( (string) $patient['birth_date'] ) : '';
        if ( '' !== $birth_date ) {
            $birth = $this->strict_date( $birth_date );
            if ( ! $birth || $birth >= new \DateTimeImmutable( 'today', wp_timezone() ) ) {
                return new \WP_Error( 'vmdpapi_invalid_birth_date', 'birth_date debe ser una fecha pasada YYYY-MM-DD.', [ 'status' => 400 ] );
            }
        }

        $consent = isset( $body['consent'] ) && is_array( $body['consent'] ) ? $body['consent'] : [];
        if ( array_diff( array_keys( $consent ), [ 'privacy_accepted', 'booking_authorized', 'accepted_at' ] ) ) {
            return new \WP_Error( 'vmdpapi_invalid_consent', 'consent contiene campos no permitidos.', [ 'status' => 400 ] );
        }
        if ( true !== ( $consent['privacy_accepted'] ?? false ) || true !== ( $consent['booking_authorized'] ?? false ) ) {
            return new \WP_Error( 'vmdpapi_consent_required', 'Debes confirmar privacidad y autorización del paciente.', [ 'status' => 400 ] );
        }
        if ( isset( $consent['accepted_at'] ) && ! is_scalar( $consent['accepted_at'] ) ) {
            return new \WP_Error( 'vmdpapi_invalid_consent_time', 'consent.accepted_at tiene un tipo inválido.', [ 'status' => 400 ] );
        }
        $accepted_at = isset( $consent['accepted_at'] ) ? trim( (string) $consent['accepted_at'] ) : '';
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?(?:Z|[+-]\d{2}:\d{2})$/D', $accepted_at ) ) {
            return new \WP_Error( 'vmdpapi_invalid_consent_time', 'consent.accepted_at debe ser RFC 3339 e incluir zona horaria.', [ 'status' => 400 ] );
        }
        try {
            $accepted = new \DateTimeImmutable( $accepted_at );
        } catch ( \Exception $exception ) {
            return new \WP_Error( 'vmdpapi_invalid_consent_time', 'consent.accepted_at debe ser RFC 3339.', [ 'status' => 400 ] );
        }
        if ( abs( time() - $accepted->getTimestamp() ) > 30 * DAY_IN_SECONDS ) {
            return new \WP_Error( 'vmdpapi_invalid_consent_time', 'consent.accepted_at está fuera del rango permitido.', [ 'status' => 400 ] );
        }

        $reason = isset( $body['reason'] ) ? trim( sanitize_textarea_field( $body['reason'] ) ) : '';
        if ( strlen( $reason ) > 500 ) {
            return new \WP_Error( 'vmdpapi_reason_too_long', 'reason excede 500 caracteres.', [ 'status' => 400 ] );
        }

        return [
            'external_reference' => $external_reference,
            'patient_reference'  => sanitize_text_field( $patient_reference ),
            'service_id'         => $service_id,
            'provider_id'        => $provider_id,
            'starts_at'          => $start,
            'patient'            => [
                'first_name'       => $first_name,
                'last_name'        => $last_name,
                'email'            => $email,
                'phone'            => $phone,
                'country_phone_iso'=> $country,
                'birth_date'       => $birth_date,
            ],
            'consent'            => [
                'privacy_accepted'  => true,
                'booking_authorized'=> true,
                'accepted_at'       => $accepted->format( \DateTimeInterface::ATOM ),
            ],
            'reason'             => $reason,
        ];
    }

    private function appointment_resource( $link ) {
        $start = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $link['starts_at'], wp_timezone() );
        return [
            'external_reference' => $link['external_reference'],
            'patient_reference'  => $link['patient_reference'] ?: null,
            'status'             => $this->repository->appointment_status( $link ),
            'service_id'         => (int) $link['service_id'],
            'provider_id'        => (int) $link['provider_id'],
            'starts_at'          => $start ? $start->format( \DateTimeInterface::ATOM ) : $link['starts_at'],
            'reschedule_count'   => (int) ( $link['reschedule_count'] ?? 0 ),
            'created_at'         => ( new \DateTimeImmutable( $link['created_at'], new \DateTimeZone( 'UTC' ) ) )->format( \DateTimeInterface::ATOM ),
        ];
    }

    private function strict_date( $value ) {
        if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $value ) ) {
            return false;
        }
        $date   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
        $errors = \DateTimeImmutable::getLastErrors();
        return $date && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) && $date->format( 'Y-m-d' ) === $value ? $date : false;
    }

    private function canonicalize( $value ) {
        if ( ! is_array( $value ) ) {
            return $value;
        }
        if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
            ksort( $value );
        }
        foreach ( $value as $key => $item ) {
            $value[ $key ] = $this->canonicalize( $item );
        }
        return $value;
    }

    private function finish_idempotent_success( $request, $row, $data, $status, $reference, $action = 'appointment_create' ) {
        $payload = $this->payload( $request, $data );
        $this->repository->complete_idempotency( $row['id'], 'complete', $status, $payload );
        return $this->respond_payload( $request, $payload, $status, $action, $reference );
    }

    private function finish_idempotent_error( $request, $row, $code, $message, $status, $reference, $state = 'failed', $action = 'appointment_create' ) {
        $payload = $this->error_payload( $request, $code, $message );
        $this->repository->complete_idempotency( $row['id'], $state, $status, $payload );
        return $this->respond_payload( $request, $payload, $status, $action, $reference );
    }

    private function finish_idempotent_retryable_error( $request, $row, $code, $message, $status, $reference, $action = 'appointment_create' ) {
        $this->repository->release_idempotency( $row['id'] );
        return $this->error_response( $request, $code, $message, $status, $action, $reference );
    }

    private function finish_idempotent_wp_error( $request, $row, $error, $reference, $action = 'appointment_create' ) {
        $data   = $error->get_error_data();
        $status = is_array( $data ) && ! empty( $data['status'] ) ? (int) $data['status'] : 500;
        $state  = is_array( $data ) && ! empty( $data['indeterminate'] ) ? 'indeterminate' : 'failed';
        return $this->finish_idempotent_error( $request, $row, $error->get_error_code(), $error->get_error_message(), $status, $reference, $state, $action );
    }

    private function wp_error_response( $request, $error, $action, $reference = '' ) {
        $data   = $error->get_error_data();
        $status = is_array( $data ) && ! empty( $data['status'] ) ? (int) $data['status'] : 500;
        return $this->error_response( $request, $error->get_error_code(), $error->get_error_message(), $status, $action, $reference );
    }

    private function error_response( $request, $code, $message, $status, $action, $reference = '' ) {
        return $this->respond_payload( $request, $this->error_payload( $request, $code, $message ), $status, $action, $reference );
    }

    private function respond( $request, $data, $status, $action, $reference = '' ) {
        return $this->respond_payload( $request, $this->payload( $request, $data ), $status, $action, $reference );
    }

    private function payload( $request, $data ) {
        return [ 'data' => $data, 'meta' => [ 'request_id' => $this->request_id( $request ) ] ];
    }

    private function error_payload( $request, $code, $message ) {
        return [
            'error' => [ 'code' => $code, 'message' => $message ],
            'meta'  => [ 'request_id' => $this->request_id( $request ) ],
        ];
    }

    private function respond_payload( $request, $payload, $status, $action, $reference = '' ) {
        $partner = $this->attribute( $request, 'vmdpapi_partner' );
        $id      = $this->request_id( $request );
        $response= new \WP_REST_Response( $payload, $status );
        $response->header( 'X-Request-Id', $id );
        $response->header( 'Cache-Control', 'no-store' );
        $this->repository->audit( is_array( $partner ) ? (int) $partner['id'] : 0, $id, $action, $status, $this->auth->remote_ip(), $reference );
        return $response;
    }

    private function request_id( $request ) {
        $id = $this->attribute( $request, 'vmdpapi_request_id' );
        if ( ! $id ) {
            $id = wp_generate_uuid4();
            $attributes                       = $request->get_attributes();
            $attributes['vmdpapi_request_id'] = $id;
            $request->set_attributes( $attributes );
        }
        return $id;
    }

    private function attribute( $request, $key ) {
        $attributes = $request->get_attributes();
        return isset( $attributes[ $key ] ) ? $attributes[ $key ] : null;
    }
}
