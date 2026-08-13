<?php

namespace VirtualMD\PartnerAPI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Auth {
    private $repository;

    public function __construct( Repository $repository ) {
        $this->repository = $repository;
    }

    public function permission( $scope ) {
        return function ( $request ) use ( $scope ) {
            return $this->authorize( $request, $scope );
        };
    }

    public function authorize( $request, $required_scope ) {
        if ( ! $this->transport_is_secure() ) {
            return $this->error( 'vmdpapi_https_required', 'HTTPS es obligatorio.', 403 );
        }

        if ( ! $this->origin_is_allowed( $request ) ) {
            return $this->error( 'vmdpapi_browser_not_allowed', 'Esta API está configurada para integraciones servidor a servidor.', 403 );
        }

        $credential = $this->credential( $request );
        if ( is_wp_error( $credential ) ) {
            return $credential;
        }

        if ( ! $this->consume_ip_auth_limit() ) {
            return $this->error( 'vmdpapi_auth_rate_limited', 'Demasiados intentos de autenticación.', 429 );
        }

        $parts = explode( '.', $credential, 2 );
        if ( 2 !== count( $parts ) || ! preg_match( '/^vmd_live_([a-f0-9]{16})$/D', $parts[0], $matches ) ) {
            return $this->error( 'vmdpapi_unauthorized', 'Credenciales inválidas.', 401 );
        }

        $partner = $this->repository->find_key_by_public_id( $matches[1] );
        if ( ! $partner || 'active' !== $partner['status'] ) {
            return $this->error( 'vmdpapi_unauthorized', 'Credenciales inválidas.', 401 );
        }

        $candidate = hash_hmac( 'sha256', $parts[1], wp_salt( 'auth' ) );
        if ( ! hash_equals( $partner['secret_hash'], $candidate ) ) {
            return $this->error( 'vmdpapi_unauthorized', 'Credenciales inválidas.', 401 );
        }

        if ( ! $this->ip_is_allowed( $partner['allowed_ips'] ) ) {
            return $this->error( 'vmdpapi_ip_denied', 'Origen de red no autorizado.', 403 );
        }

        $scopes = array_filter( array_map( 'trim', explode( ',', $partner['scopes'] ) ) );
        if ( ! in_array( $required_scope, $scopes, true ) && ! in_array( '*', $scopes, true ) ) {
            return $this->error( 'vmdpapi_forbidden', 'La credencial no tiene el permiso requerido.', 403 );
        }

        if ( ! $this->consume_rate_limit( $partner ) ) {
            $error = $this->error( 'vmdpapi_rate_limited', 'Límite de solicitudes excedido.', 429 );
            $error->add_data( [ 'status' => 429, 'retry_after' => 60 ] );
            return $error;
        }

        $attributes                     = $request->get_attributes();
        $attributes['vmdpapi_partner']  = $partner;
        $request->set_attributes( $attributes );
        $this->repository->touch_key( (int) $partner['id'] );
        return true;
    }

    private function credential( $request ) {
        $explicit = trim( (string) $request->get_header( 'X-VirtualMD-API-Key' ) );
        $header   = trim( (string) $request->get_header( 'Authorization' ) );
        $bearer   = '';

        if ( '' !== $header && ! preg_match( '/^Bearer\s+([^\s]+)$/i', $header, $matches ) ) {
            return $this->error( 'vmdpapi_unauthorized', 'Encabezado Authorization inválido.', 401 );
        }
        if ( ! empty( $matches[1] ) ) {
            $bearer = $matches[1];
        }
        if ( '' !== $explicit && '' !== $bearer && ! hash_equals( $explicit, $bearer ) ) {
            return $this->error( 'vmdpapi_unauthorized', 'Se recibieron credenciales distintas.', 401 );
        }

        $credential = '' !== $explicit ? $explicit : $bearer;
        if ( strlen( $credential ) < 50 || strlen( $credential ) > 160 ) {
            return $this->error( 'vmdpapi_unauthorized', 'Credenciales requeridas.', 401 );
        }
        return $credential;
    }

    private function transport_is_secure() {
        if ( is_ssl() ) {
            return true;
        }
        return defined( 'VMD_PARTNER_API_ALLOW_INSECURE_LOCAL' ) && VMD_PARTNER_API_ALLOW_INSECURE_LOCAL;
    }

    private function origin_is_allowed( $request ) {
        $origin = trim( (string) $request->get_header( 'Origin' ) );
        if ( '' === $origin ) {
            return true;
        }
        $allowed = defined( 'VMD_PARTNER_API_ALLOWED_ORIGINS' ) ? VMD_PARTNER_API_ALLOWED_ORIGINS : [];
        if ( is_string( $allowed ) ) {
            $allowed = preg_split( '/[\s,]+/', $allowed, -1, PREG_SPLIT_NO_EMPTY );
        }
        return is_array( $allowed ) && in_array( $origin, $allowed, true );
    }

    private function consume_rate_limit( $partner ) {
        $window = 60;
        $limit  = max( 10, min( 5000, (int) $partner['rate_limit'] ) );
        $bucket = 'vmdpapi_rl_' . md5( $partner['id'] . '|' . floor( time() / $window ) );
        $count  = (int) get_transient( $bucket );
        if ( $count >= $limit ) {
            return false;
        }
        set_transient( $bucket, $count + 1, $window + 5 );
        return true;
    }

    private function consume_ip_auth_limit() {
        $window = 60;
        $limit  = defined( 'VMD_PARTNER_API_AUTH_ATTEMPTS_PER_MINUTE' )
            ? max( 30, min( 5000, absint( VMD_PARTNER_API_AUTH_ATTEMPTS_PER_MINUTE ) ) )
            : 300;
        $bucket = 'vmdpapi_auth_' . md5( $this->remote_ip() . '|' . floor( time() / $window ) );
        $count  = (int) get_transient( $bucket );
        if ( $count >= $limit ) {
            return false;
        }
        set_transient( $bucket, $count + 1, $window + 5 );
        return true;
    }

    private function ip_is_allowed( $configured ) {
        $configured = trim( (string) $configured );
        if ( '' === $configured ) {
            return true;
        }
        $remote = $this->remote_ip();
        foreach ( preg_split( '/[\s,]+/', $configured, -1, PREG_SPLIT_NO_EMPTY ) as $cidr ) {
            if ( $this->ip_in_cidr( $remote, $cidr ) ) {
                return true;
            }
        }
        return false;
    }

    private function ip_in_cidr( $ip, $cidr ) {
        $parts   = explode( '/', trim( $cidr ), 2 );
        $network = $parts[0];
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) || ! filter_var( $network, FILTER_VALIDATE_IP ) ) {
            return false;
        }
        $ip_bin  = @inet_pton( $ip );
        $net_bin = @inet_pton( $network );
        if ( false === $ip_bin || false === $net_bin || strlen( $ip_bin ) !== strlen( $net_bin ) ) {
            return false;
        }
        $max    = 8 * strlen( $ip_bin );
        $prefix = isset( $parts[1] ) && ctype_digit( $parts[1] ) ? (int) $parts[1] : $max;
        if ( $prefix < 0 || $prefix > $max ) {
            return false;
        }
        $bytes = intdiv( $prefix, 8 );
        if ( $bytes && substr( $ip_bin, 0, $bytes ) !== substr( $net_bin, 0, $bytes ) ) {
            return false;
        }
        $bits = $prefix % 8;
        if ( 0 === $bits ) {
            return true;
        }
        $mask = ( 0xff << ( 8 - $bits ) ) & 0xff;
        return ( ord( $ip_bin[ $bytes ] ) & $mask ) === ( ord( $net_bin[ $bytes ] ) & $mask );
    }

    public function remote_ip() {
        if ( function_exists( '\\VirtualMD\\HeroBooking\\vmhb_get_client_ip' ) ) {
            $trusted = \VirtualMD\HeroBooking\vmhb_get_client_ip();
            if ( filter_var( $trusted, FILTER_VALIDATE_IP ) ) {
                return $trusted;
            }
        }
        $remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) $_SERVER['REMOTE_ADDR'] ) : '';
        return filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : 'unknown';
    }

    private function error( $code, $message, $status ) {
        return new \WP_Error( $code, $message, [ 'status' => $status ] );
    }
}
