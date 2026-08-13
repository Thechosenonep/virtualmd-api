<?php

namespace VirtualMD\PartnerAPI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Amelia_Gateway {
    private $repository;

    public function __construct( Repository $repository ) {
        $this->repository = $repository;
    }

    public function availability( $service_id, $provider_id, $start_date, $end_date ) {
        if ( ! function_exists( '\\VirtualMD\\HeroBooking\\vm_amelia_get_slots_data' ) ) {
            return new \WP_Error(
                'vmdpapi_availability_unavailable',
                'El motor de disponibilidad del widget no está activo.',
                [ 'status' => 503 ]
            );
        }

        $raw = \VirtualMD\HeroBooking\vm_amelia_get_slots_data(
            absint( $service_id ),
            absint( $provider_id ),
            $start_date,
            $end_date
        );
        $timezone = wp_timezone();
        $slots    = [];
        foreach ( (array) $raw['slots'] as $date => $times ) {
            foreach ( (array) $times as $time ) {
                $providers = [];
                if ( $provider_id ) {
                    $providers = [ absint( $provider_id ) ];
                } elseif ( isset( $raw['providerMap'][ $date ][ $time ] ) ) {
                    $providers = array_values( array_unique( array_map( 'absint', (array) $raw['providerMap'][ $date ][ $time ] ) ) );
                    sort( $providers );
                }
                $date_time = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $date . ' ' . $time, $timezone );
                if ( ! $date_time || empty( $providers ) ) {
                    continue;
                }
                $slots[] = [
                    'starts_at'   => $date_time->format( \DateTimeInterface::ATOM ),
                    'provider_ids'=> $providers,
                ];
            }
        }

        return [
            'timezone'   => $timezone->getName(),
            'service_id' => absint( $service_id ),
            'provider_id'=> absint( $provider_id ) ?: null,
            'from'       => $start_date,
            'to'         => $end_date,
            'slots'      => $slots,
        ];
    }

    public function provider_for_slot( $service_id, $requested_provider_id, \DateTimeImmutable $start ) {
        $date         = $start->format( 'Y-m-d' );
        $time         = $start->format( 'H:i' );
        $availability = $this->availability( $service_id, $requested_provider_id, $date, $date );
        if ( is_wp_error( $availability ) ) {
            return $availability;
        }

        foreach ( $availability['slots'] as $slot ) {
            $candidate = new \DateTimeImmutable( $slot['starts_at'] );
            if ( $candidate->format( 'Y-m-d H:i' ) === $date . ' ' . $time ) {
                if ( $requested_provider_id && in_array( absint( $requested_provider_id ), $slot['provider_ids'], true ) ) {
                    return absint( $requested_provider_id );
                }
                if ( ! empty( $slot['provider_ids'][0] ) ) {
                    return absint( $slot['provider_ids'][0] );
                }
            }
        }

        return new \WP_Error(
            'vmdpapi_slot_unavailable',
            'El horario seleccionado ya no está disponible.',
            [ 'status' => 409 ]
        );
    }

    public function create_booking( $input, $service, $provider_id, $partner ) {
        if ( ! defined( 'AMELIA_API_KEY' ) || '' === (string) AMELIA_API_KEY ) {
            return new \WP_Error( 'vmdpapi_amelia_not_configured', 'Amelia no está configurado.', [ 'status' => 503 ] );
        }
        if (
            ! function_exists( '\\VirtualMD\\HeroBooking\\vm_amelia_store_form_context_for_booking' )
            || ! function_exists( '\\VirtualMD\\HeroBooking\\vm_paypal_prepare_customer_for_booking' )
        ) {
            return new \WP_Error( 'vmdpapi_identity_engine_unavailable', 'El motor de identidad aislada del widget no está disponible.', [ 'status' => 503 ] );
        }

        $customer    = [
            'id'              => null,
            'firstName'       => $input['patient']['first_name'],
            'lastName'        => $input['patient']['last_name'],
            'email'           => $input['patient']['email'],
            'phone'           => $input['patient']['phone'],
            'countryPhoneIso' => $input['patient']['country_phone_iso'],
            'externalId'      => null,
        ];

        $custom_fields = [];
        if ( ! empty( $input['patient']['birth_date'] ) ) {
            $field_id = defined( 'VMD_PARTNER_API_BIRTH_DATE_FIELD_ID' )
                ? absint( VMD_PARTNER_API_BIRTH_DATE_FIELD_ID )
                : 17;
            if ( $field_id ) {
                $custom_fields[ (string) $field_id ] = [
                    'label' => 'Fecha de nacimiento',
                    'type'  => 'text',
                    'value' => $input['patient']['birth_date'],
                ];
            }
        }

        $notes = [
            'Reserva creada por integración externa',
            'Integración: ' . $partner['name'],
            'Referencia externa: ' . $input['external_reference'],
        ];
        if ( '' !== $input['patient_reference'] ) {
            $notes[] = 'Referencia de paciente: ' . $input['patient_reference'];
        }
        if ( '' !== $input['reason'] ) {
            $notes[] = 'Motivo: ' . $input['reason'];
        }
        $notes[] = 'Autorización del paciente declarada por la integración: ' . $input['consent']['accepted_at'];

        $location_id = defined( 'VMD_PARTNER_API_LOCATION_ID' ) ? absint( VMD_PARTNER_API_LOCATION_ID ) : 1;
        $payload     = [
            'type'                         => 'appointment',
            'bookingStart'                 => $input['starts_at']->format( 'Y-m-d H:i' ),
            'notifyParticipants'           => 1,
            'locationId'                   => $location_id,
            'providerId'                   => absint( $provider_id ),
            'serviceId'                    => absint( $service['id'] ),
            'internalNotes'                => implode( "\n", $notes ),
            'bookings'                     => [ [
                'persons'     => 1,
                'duration'    => absint( $service['duration'] ),
                'customerId'  => null,
                'customer'    => $customer,
                'extras'      => [],
                'customFields'=> $custom_fields,
                'price'       => 0,
            ] ],
            'payment'                      => [
                'gateway'  => 'onSite',
                'currency' => 'MXN',
                'amount'   => 0,
                'data'     => [ 'source' => 'virtualmd_partner_api' ],
            ],
            'runInstantPostBookingActions' => true,
        ];
        $payload = apply_filters( 'vmd_partner_api_amelia_booking_payload', $payload, $input, $partner );

        // Match the public widget: every consultation receives an isolated
        // technical Amelia customer while real contact data remains in the
        // widget context for notifications. This prevents one shared family
        // email address from merging different patients.
        $context_token = '';
        if ( function_exists( '\\VirtualMD\\HeroBooking\\vm_amelia_store_form_context_for_booking' ) ) {
            $context_token = \VirtualMD\HeroBooking\vm_amelia_store_form_context_for_booking( $payload );
        }
        if ( function_exists( '\\VirtualMD\\HeroBooking\\vm_amelia_append_form_customer_to_internal_notes' ) ) {
            $payload = \VirtualMD\HeroBooking\vm_amelia_append_form_customer_to_internal_notes( $payload );
        }
        if ( function_exists( '\\VirtualMD\\HeroBooking\\vm_paypal_prepare_customer_for_booking' ) ) {
            $prepared = \VirtualMD\HeroBooking\vm_paypal_prepare_customer_for_booking( $payload, $context_token );
            if ( empty( $prepared['success'] ) || empty( $prepared['booking_data'] ) ) {
                return new \WP_Error( 'vmdpapi_patient_prepare_failed', 'No fue posible preparar la identidad aislada del paciente.', [ 'status' => 503 ] );
            }
            $payload = $prepared['booking_data'];
            if ( $context_token && function_exists( '\\VirtualMD\\HeroBooking\\vm_amelia_update_form_context_technical_customer' ) ) {
                \VirtualMD\HeroBooking\vm_amelia_update_form_context_technical_customer( $context_token, $payload );
            }
        }
        $payload['notifyParticipants']           = 1;
        $payload['runInstantPostBookingActions'] = $context_token ? false : true;

        $url      = add_query_arg( [ 'action' => 'wpamelia_api', 'call' => '/api/v1/bookings' ], admin_url( 'admin-ajax.php' ) );
        $response = wp_remote_request( $url, [
            'method'      => 'POST',
            'timeout'     => 20,
            'redirection' => 0,
            'headers'     => array_filter( [
                'Content-Type' => 'application/json',
                'Amelia'       => (string) AMELIA_API_KEY,
                'X-VM-Amelia-Form-Token' => $context_token,
            ] ),
            'body'        => wp_json_encode( $payload ),
        ] );

        if ( is_wp_error( $response ) ) {
            return new \WP_Error(
                'vmdpapi_amelia_transport_error',
                'No fue posible confirmar si Amelia recibió la reserva. No reintentes con otra referencia.',
                [ 'status' => 503, 'indeterminate' => true ]
            );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $body   = (string) wp_remote_retrieve_body( $response );
        $json   = json_decode( $body, true );
        if ( ! is_array( $json ) ) {
            return new \WP_Error(
                'vmdpapi_amelia_invalid_response',
                'Amelia devolvió una respuesta no válida. No reintentes con otra referencia.',
                [ 'status' => 502, 'indeterminate' => 0 === $status || $status >= 200 ]
            );
        }

        $booking_id     = isset( $json['data']['appointment']['bookings'][0]['id'] )
            ? absint( $json['data']['appointment']['bookings'][0]['id'] )
            : 0;
        $appointment_id = isset( $json['data']['appointment']['id'] )
            ? absint( $json['data']['appointment']['id'] )
            : 0;

        if ( $status >= 400 && $status < 500 ) {
            return new \WP_Error(
                'vmdpapi_amelia_rejected',
                'Amelia rechazó la reserva. Confirma los datos del paciente y la disponibilidad.',
                [ 'status' => 409 ]
            );
        }
        if ( $status >= 200 && $status < 300 && ( ! $booking_id || ! $appointment_id ) && ! empty( $json['data']['message'] ) ) {
            return new \WP_Error(
                'vmdpapi_amelia_rejected',
                'Amelia rechazó la reserva. Confirma los datos del paciente y la disponibilidad.',
                [ 'status' => 409 ]
            );
        }
        if ( $status < 200 || $status >= 300 || ! $booking_id || ! $appointment_id ) {
            return new \WP_Error(
                'vmdpapi_amelia_missing_ids',
                'No fue posible confirmar los identificadores de Amelia. No reintentes con otra referencia.',
                [ 'status' => 502, 'indeterminate' => true ]
            );
        }

        $booking_status = isset( $json['data']['appointment']['bookings'][0]['status'] )
            ? sanitize_key( $json['data']['appointment']['bookings'][0]['status'] )
            : 'approved';

        if ( $context_token ) {
            if ( function_exists( '\\VirtualMD\\HeroBooking\\vm_amelia_persist_form_context' ) ) {
                \VirtualMD\HeroBooking\vm_amelia_persist_form_context( $context_token, $booking_id );
            }
            $customer_id = isset( $json['data']['appointment']['bookings'][0]['customerId'] )
                ? absint( $json['data']['appointment']['bookings'][0]['customerId'] )
                : 0;
            if ( $customer_id && function_exists( '\\VirtualMD\\HeroBooking\\vm_amelia_update_created_customer_with_form_data' ) ) {
                \VirtualMD\HeroBooking\vm_amelia_update_created_customer_with_form_data( $customer_id, $context_token );
            }
            if ( $customer_id && function_exists( '\\VirtualMD\\HeroBooking\\vm_amelia_run_booking_success_actions' ) ) {
                \VirtualMD\HeroBooking\vm_amelia_run_booking_success_actions( $booking_id, [
                    'type'                     => 'appointment',
                    'appointmentStatusChanged' => false,
                    'recurring'                => [],
                    'packageId'                => null,
                    'customerId'               => $customer_id,
                    'paymentId'                => null,
                    'packageCustomerId'        => null,
                ], $context_token );
            }
        }
        return [
            'booking_id'     => $booking_id,
            'appointment_id' => $appointment_id,
            'status'         => $booking_status,
        ];
    }

    public function reassign_booking( $link, \DateTimeImmutable $start, $provider_id ) {
        if ( ! defined( 'AMELIA_API_KEY' ) || '' === (string) AMELIA_API_KEY ) {
            return new \WP_Error( 'vmdpapi_amelia_not_configured', 'Amelia no está configurado.', [ 'status' => 503 ] );
        }

        $booking_id = absint( $link['amelia_booking_id'] );
        if ( ! $booking_id ) {
            return new \WP_Error( 'vmdpapi_booking_id_missing', 'La consulta no tiene un identificador de Amelia para reprogramar.', [ 'status' => 409 ] );
        }

        $context_token = '';
        if ( function_exists( '\\VirtualMD\\HeroBooking\\vm_amelia_create_reassign_context_from_booking_id' ) ) {
            $context_token = \VirtualMD\HeroBooking\vm_amelia_create_reassign_context_from_booking_id( $booking_id );
        }
        $headers = [
            'Content-Type' => 'application/json',
            'Amelia'       => (string) AMELIA_API_KEY,
        ];
        if ( $context_token ) {
            $headers['X-VM-Amelia-Form-Token'] = $context_token;
        }

        $url      = add_query_arg( [
            'action' => 'wpamelia_api',
            'call'   => '/api/v1/bookings/reassign/' . $booking_id,
        ], admin_url( 'admin-ajax.php' ) );
        $response = wp_remote_request( $url, [
            'method'      => 'POST',
            'timeout'     => 20,
            'redirection' => 0,
            'headers'     => $headers,
            'body'        => wp_json_encode( [
                'bookingStart'      => $start->format( 'Y-m-d H:i' ),
                'utcOffset'         => null,
                'timeZone'          => wp_timezone()->getName(),
                'providerId'        => absint( $provider_id ),
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return new \WP_Error(
                'vmdpapi_amelia_transport_error',
                'No fue posible confirmar la reprogramación. Reintenta únicamente con la misma Idempotency-Key.',
                [ 'status' => 503, 'indeterminate' => true ]
            );
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        $body   = (string) wp_remote_retrieve_body( $response );
        $json   = json_decode( $body, true );
        if ( ! is_array( $json ) ) {
            return new \WP_Error(
                'vmdpapi_amelia_invalid_response',
                'Amelia devolvió una respuesta no válida. Reintenta únicamente con la misma Idempotency-Key.',
                [ 'status' => 502, 'indeterminate' => 0 === $status || $status >= 200 ]
            );
        }
        if ( $status < 200 || $status >= 300 || empty( $json['message'] ) ) {
            return new \WP_Error(
                'vmdpapi_amelia_reassign_rejected',
                'Amelia rechazó la reprogramación. Consulta nuevamente la disponibilidad.',
                [ 'status' => 409 ]
            );
        }

        $appointment_id     = $this->appointment_id_for_booking( $booking_id );
        if ( ! $appointment_id ) {
            $appointment_id = $this->extract_id_by_keys( $json, [ 'appointmentId', 'appointment_id' ] );
        }
        if ( ! $appointment_id ) {
            return new \WP_Error(
                'vmdpapi_reassign_missing_ids',
                'Amelia confirmó el cambio pero no devolvió identificadores completos. Reintenta únicamente con la misma Idempotency-Key.',
                [ 'status' => 502, 'indeterminate' => true ]
            );
        }
        $confirmed_provider = $this->provider_id_for_appointment( $appointment_id );
        if ( ! $confirmed_provider ) {
            $confirmed_provider = $this->extract_id_by_keys( $json, [ 'providerId', 'provider_id' ] );
        }

        $this->send_reschedule_notifications(
            $booking_id,
            $appointment_id,
            $confirmed_provider ?: absint( $provider_id ),
            $start,
            $context_token,
            $json
        );

        return [
            'booking_id'     => $booking_id,
            'appointment_id' => $appointment_id,
            'provider_id'    => $confirmed_provider ?: absint( $provider_id ),
        ];
    }

    private function appointment_id_for_booking( $booking_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT appointmentId FROM {$wpdb->prefix}amelia_customer_bookings WHERE id = %d LIMIT 1",
            absint( $booking_id )
        ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    private function provider_id_for_appointment( $appointment_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT providerId FROM {$wpdb->prefix}amelia_appointments WHERE id = %d LIMIT 1",
            absint( $appointment_id )
        ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    private function extract_id_by_keys( $value, $keys, $depth = 0 ) {
        if ( $depth > 8 || ! is_array( $value ) ) {
            return 0;
        }
        foreach ( $keys as $key ) {
            if ( isset( $value[ $key ] ) && absint( $value[ $key ] ) ) {
                return absint( $value[ $key ] );
            }
        }
        foreach ( $value as $child ) {
            if ( is_array( $child ) ) {
                $found = $this->extract_id_by_keys( $child, $keys, $depth + 1 );
                if ( $found ) {
                    return $found;
                }
            }
        }
        return 0;
    }

    private function send_reschedule_notifications( $booking_id, $appointment_id, $provider_id, $start, $context_token, $payload ) {
        if (
            function_exists( '\\VirtualMD\\HeroBooking\\vm_amelia_get_booking_data_for_whatsapp_event' )
            && function_exists( '\\VirtualMD\\HeroBooking\\vm_amelia_send_manual_whatsapp_confirmation' )
        ) {
            $booking_data = \VirtualMD\HeroBooking\vm_amelia_get_booking_data_for_whatsapp_event( $booking_id, $appointment_id, $payload );
            if ( is_array( $booking_data ) ) {
                $booking_data['providerId']   = absint( $provider_id );
                $booking_data['bookingStart'] = $start->format( 'Y-m-d H:i' );
                $booking_data['status']       = 'Reprogramada';
                \VirtualMD\HeroBooking\vm_amelia_send_manual_whatsapp_confirmation(
                    $booking_data,
                    [ 'booking_id' => $booking_id, 'appointment_id' => $appointment_id ],
                    $context_token,
                    [ 'booking_id' => $booking_id, 'appointment_id' => $appointment_id, 'reassign' => true, '_skip_hero_sender' => true ]
                );
            }
        }

        if ( ! function_exists( '\\VirtualMD\\HeroBooking\\vm_amelia_get_customer_context_by_booking_id' ) ) {
            return;
        }
        $context = \VirtualMD\HeroBooking\vm_amelia_get_customer_context_by_booking_id( $booking_id );
        $email   = isset( $context['customer']['email'] ) ? sanitize_email( $context['customer']['email'] ) : '';
        if ( ! is_email( $email ) ) {
            return;
        }

        global $wpdb;
        $provider_name = $wpdb->get_var( $wpdb->prepare(
            "SELECT CONCAT(firstName, ' ', lastName) FROM {$wpdb->prefix}amelia_users WHERE id = %d LIMIT 1",
            absint( $provider_id )
        ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $service_name = $wpdb->get_var( $wpdb->prepare(
            "SELECT s.name FROM {$wpdb->prefix}amelia_services s
             INNER JOIN {$wpdb->prefix}amelia_appointments a ON a.serviceId = s.id
             WHERE a.id = %d LIMIT 1",
            absint( $appointment_id )
        ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $meeting_url = function_exists( '\\VirtualMD\\HeroBooking\\vm_amelia_get_meeting_url_for_appointment' )
            ? \VirtualMD\HeroBooking\vm_amelia_get_meeting_url_for_appointment( $appointment_id )
            : '';

        $name = trim( (string) ( $context['customer']['fullName'] ?? '' ) );
        if ( '' === $name ) {
            $name = trim( (string) ( $context['customer']['firstName'] ?? '' ) . ' ' . (string) ( $context['customer']['lastName'] ?? '' ) );
        }
        $message  = '<p>Hola ' . esc_html( $name ?: 'paciente' ) . ',</p>';
        $message .= '<p>Tu consulta VirtualMD fue reprogramada correctamente.</p>';
        $message .= '<p><strong>Fecha:</strong> ' . esc_html( $start->format( 'd/m/Y' ) ) . '<br>';
        $message .= '<strong>Hora:</strong> ' . esc_html( $start->format( 'H:i' ) ) . '<br>';
        $message .= '<strong>Especialista:</strong> ' . esc_html( trim( (string) $provider_name ) ) . '<br>';
        $message .= '<strong>Consulta:</strong> ' . esc_html( (string) $service_name ) . '</p>';
        if ( $meeting_url ) {
            $message .= '<p><a href="' . esc_url( $meeting_url ) . '">Entrar a la videoconsulta</a></p>';
        }
        wp_mail( $email, 'Tu consulta VirtualMD fue reprogramada', $message, [ 'Content-Type: text/html; charset=UTF-8' ] );
    }

    public function acquire_slot_lock( $service_id, $provider_id, \DateTimeImmutable $start ) {
        global $wpdb;
        $name = 'vmdpapi_' . substr( sha1( $service_id . '|' . $provider_id . '|' . $start->format( 'c' ) ), 0, 40 );
        $got  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $name ) );
        return 1 === $got ? $name : false;
    }

    public function acquire_appointment_lock( $booking_id ) {
        global $wpdb;
        $name = 'vmdpapi_appt_' . substr( sha1( (string) absint( $booking_id ) ), 0, 32 );
        $got  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $name ) );
        return 1 === $got ? $name : false;
    }

    public function acquire_partner_create_lock( $partner_id ) {
        global $wpdb;
        $name = 'vmdpapi_partner_' . substr( sha1( (string) absint( $partner_id ) ), 0, 30 );
        $got  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $name ) );
        return 1 === $got ? $name : false;
    }

    public function release_slot_lock( $name ) {
        global $wpdb;
        if ( $name ) {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
        }
    }
}
