<?php

namespace VirtualMD\PartnerAPI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class CLI {
    public static function register( Repository $repository ) {
        if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
            return;
        }
        \WP_CLI::add_command( 'virtualmd-partner-api', new CLI_Command( $repository ) );
    }
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    final class CLI_Command {
        private $repository;

        public function __construct( Repository $repository ) {
            $this->repository = $repository;
        }

        /**
         * Create a credential. The secret is printed once.
         *
         * ## OPTIONS
         * --name=<name>
         * --scopes=<comma-separated-scopes>
         * [--rate-limit=<number>]
         * [--allowed-ips=<comma-separated-cidrs>]
         * [--daily-limit=<number>]
         * [--monthly-limit=<number>]
         * [--reschedule-limit=<number>]
         */
        public function key_create( $args, $assoc_args ) {
            $result = $this->repository->create_key(
                $assoc_args['name'],
                $assoc_args['scopes'],
                isset( $assoc_args['rate-limit'] ) ? absint( $assoc_args['rate-limit'] ) : 120,
                isset( $assoc_args['allowed-ips'] ) ? $assoc_args['allowed-ips'] : '',
                isset( $assoc_args['daily-limit'] ) ? absint( $assoc_args['daily-limit'] ) : 100,
                isset( $assoc_args['monthly-limit'] ) ? absint( $assoc_args['monthly-limit'] ) : 1000,
                isset( $assoc_args['reschedule-limit'] ) ? absint( $assoc_args['reschedule-limit'] ) : 3
            );
            if ( is_wp_error( $result ) ) {
                \WP_CLI::error( $result->get_error_message() );
            }
            \WP_CLI::success( 'Credencial creada. Guárdala ahora:' );
            \WP_CLI::line( $result['credential'] );
        }

        /** List credentials without exposing secrets. */
        public function key_list() {
            \WP_CLI\Utils\format_items( 'table', $this->repository->list_keys(), [ 'id', 'public_id', 'name', 'scopes', 'rate_limit', 'appointment_daily_limit', 'appointment_monthly_limit', 'reschedule_limit', 'status', 'created_at', 'last_used_at' ] );
        }

        /**
         * Revoke a credential.
         *
         * ## OPTIONS
         * <id>
         */
        public function key_revoke( $args ) {
            if ( ! $this->repository->revoke_key( absint( $args[0] ) ) ) {
                \WP_CLI::error( 'No se pudo revocar la credencial.' );
            }
            \WP_CLI::success( 'Credencial revocada.' );
        }
    }
}
