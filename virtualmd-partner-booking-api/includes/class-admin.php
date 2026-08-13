<?php

namespace VirtualMD\PartnerAPI;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Admin {
    private $repository;

    public function __construct( Repository $repository ) {
        $this->repository = $repository;
    }

    public function register() {
        add_action( 'admin_menu', [ $this, 'menu' ] );
    }

    public function menu() {
        add_management_page(
            'VirtualMD Partner API',
            'VirtualMD Partner API',
            'manage_options',
            'virtualmd-partner-api',
            [ $this, 'render' ]
        );
    }

    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos para ver esta página.', 'virtualmd-partner-api' ) );
        }

        $created = null;
        $notice  = '';
        if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['vmdpapi_action'] ) ) {
            check_admin_referer( 'vmdpapi_manage_keys' );
            $action = sanitize_key( wp_unslash( $_POST['vmdpapi_action'] ) );
            if ( 'create' === $action ) {
                $created = $this->repository->create_key(
                    isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '',
                    isset( $_POST['scopes'] ) ? (array) wp_unslash( $_POST['scopes'] ) : [],
                    isset( $_POST['rate_limit'] ) ? absint( $_POST['rate_limit'] ) : 120,
                    isset( $_POST['allowed_ips'] ) ? wp_unslash( $_POST['allowed_ips'] ) : '',
                    isset( $_POST['daily_limit'] ) ? absint( $_POST['daily_limit'] ) : 100,
                    isset( $_POST['monthly_limit'] ) ? absint( $_POST['monthly_limit'] ) : 1000,
                    isset( $_POST['reschedule_limit'] ) ? absint( $_POST['reschedule_limit'] ) : 3
                );
            } elseif ( 'update_settings' === $action ) {
                $updated = $this->repository->update_key_settings(
                    isset( $_POST['key_id'] ) ? absint( $_POST['key_id'] ) : 0,
                    isset( $_POST['scopes'] ) ? (array) wp_unslash( $_POST['scopes'] ) : [],
                    isset( $_POST['allowed_ips'] ) ? wp_unslash( $_POST['allowed_ips'] ) : '',
                    isset( $_POST['rate_limit'] ) ? absint( $_POST['rate_limit'] ) : 120,
                    isset( $_POST['daily_limit'] ) ? absint( $_POST['daily_limit'] ) : 0,
                    isset( $_POST['monthly_limit'] ) ? absint( $_POST['monthly_limit'] ) : 0,
                    isset( $_POST['reschedule_limit'] ) ? absint( $_POST['reschedule_limit'] ) : 0
                );
                if ( is_wp_error( $updated ) ) {
                    $created = $updated;
                } else {
                    $notice = 'Permisos, red y límites actualizados.';
                }
            } elseif ( 'revoke' === $action ) {
                $this->repository->revoke_key( isset( $_POST['key_id'] ) ? absint( $_POST['key_id'] ) : 0 );
                $notice = 'Credencial revocada.';
            }
        }

        $tab = isset( $_GET['tab'] ) && 'appointments' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ? 'appointments' : 'keys';
        ?>
        <div class="wrap">
            <h1>VirtualMD Partner Booking API</h1>
            <nav class="nav-tab-wrapper">
                <a class="nav-tab <?php echo 'keys' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'tools.php?page=virtualmd-partner-api&tab=keys' ) ); ?>">Credenciales y límites</a>
                <a class="nav-tab <?php echo 'appointments' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'tools.php?page=virtualmd-partner-api&tab=appointments' ) ); ?>">Consultas generadas</a>
            </nav>

            <?php if ( is_wp_error( $created ) ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html( $created->get_error_message() ); ?></p></div>
            <?php elseif ( is_array( $created ) ) : ?>
                <div class="notice notice-warning" style="padding:12px">
                    <p><strong>Copia esta credencial ahora. No se volverá a mostrar.</strong></p>
                    <p><code style="user-select:all;word-break:break-all"><?php echo esc_html( $created['credential'] ); ?></code></p>
                </div>
            <?php elseif ( $notice ) : ?>
                <div class="notice notice-success"><p><?php echo esc_html( $notice ); ?></p></div>
            <?php endif; ?>

            <?php if ( 'appointments' === $tab ) : ?>
                <?php $this->render_appointments(); ?>
            <?php else : ?>
                <?php $this->render_keys(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_keys() {
        $scope_labels = [
            'catalog:read'            => 'Leer catálogo',
            'doctors:read'            => 'Leer doctores',
            'availability:read'       => 'Leer disponibilidad',
            'appointments:write'      => 'Crear citas',
            'appointments:read'       => 'Consultar citas propias',
            'appointments:reschedule' => 'Reprogramar citas propias',
        ];
        ?>
        <p>Usa una clave distinta por proveedor. Un valor de <strong>0</strong> en los límites de citas o reprogramaciones significa sin límite.</p>
        <h2>Crear credencial</h2>
        <form method="post" style="max-width:820px">
            <?php wp_nonce_field( 'vmdpapi_manage_keys' ); ?>
            <input type="hidden" name="vmdpapi_action" value="create">
            <table class="form-table" role="presentation">
                <tr><th><label for="vmdpapi-name">Proveedor</label></th><td><input id="vmdpapi-name" name="name" class="regular-text" required maxlength="191" placeholder="Ej. Empresa ABC"></td></tr>
                <tr><th>Permisos</th><td>
                    <?php foreach ( $scope_labels as $scope => $label ) : ?>
                        <label style="display:block;margin-bottom:6px"><input type="checkbox" name="scopes[]" value="<?php echo esc_attr( $scope ); ?>"> <?php echo esc_html( $label ); ?></label>
                    <?php endforeach; ?>
                </td></tr>
                <tr><th><label for="vmdpapi-rate">Solicitudes/minuto</label></th><td><input id="vmdpapi-rate" name="rate_limit" type="number" min="10" max="5000" value="120"></td></tr>
                <tr><th><label for="vmdpapi-daily">Citas/día</label></th><td><input id="vmdpapi-daily" name="daily_limit" type="number" min="0" max="100000" value="100"></td></tr>
                <tr><th><label for="vmdpapi-monthly">Citas/mes</label></th><td><input id="vmdpapi-monthly" name="monthly_limit" type="number" min="0" max="1000000" value="1000"></td></tr>
                <tr><th><label for="vmdpapi-reschedules">Reprogramaciones por cita</label></th><td><input id="vmdpapi-reschedules" name="reschedule_limit" type="number" min="0" max="1000" value="3"></td></tr>
                <tr><th><label for="vmdpapi-ips">IP/CIDR permitidos</label></th><td><textarea id="vmdpapi-ips" name="allowed_ips" rows="3" class="large-text" placeholder="Opcional. Ej. 203.0.113.10, 2001:db8::/48"></textarea><p class="description">Vacío permite cualquier IP.</p></td></tr>
            </table>
            <?php submit_button( 'Crear credencial' ); ?>
        </form>

        <h2>Credenciales existentes</h2>
        <div style="overflow:auto">
        <table class="widefat striped" style="min-width:1250px">
            <thead><tr><th>Proveedor</th><th>ID público</th><th>Permisos</th><th>Estado</th><th>Último uso</th><th>Límites</th><th>Acciones</th></tr></thead>
            <tbody>
            <?php foreach ( $this->repository->list_keys() as $key ) : ?>
                <tr>
                    <td><strong><?php echo esc_html( $key['name'] ); ?></strong><br><small>Creada <?php echo esc_html( $key['created_at'] ); ?> UTC</small></td>
                    <td><code><?php echo esc_html( $key['public_id'] ); ?></code></td>
                    <td style="max-width:260px;word-break:break-word"><?php echo esc_html( $key['scopes'] ); ?></td>
                    <td><?php echo esc_html( $key['status'] ); ?></td>
                    <td><?php echo esc_html( $key['last_used_at'] ?: '—' ); ?></td>
                    <td>
                        <form method="post" style="display:grid;grid-template-columns:repeat(4,minmax(80px,1fr));gap:6px;align-items:end">
                            <?php wp_nonce_field( 'vmdpapi_manage_keys' ); ?>
                            <input type="hidden" name="vmdpapi_action" value="update_settings">
                            <input type="hidden" name="key_id" value="<?php echo esc_attr( $key['id'] ); ?>">
                            <fieldset style="grid-column:1/-1"><legend><small>Permisos</small></legend>
                                <?php $current_scopes = array_filter( explode( ',', $key['scopes'] ) ); ?>
                                <?php foreach ( $scope_labels as $scope => $label ) : ?>
                                    <label style="display:inline-block;margin:0 10px 5px 0"><input type="checkbox" name="scopes[]" value="<?php echo esc_attr( $scope ); ?>" <?php checked( in_array( $scope, $current_scopes, true ) ); ?>> <small><?php echo esc_html( $label ); ?></small></label>
                                <?php endforeach; ?>
                            </fieldset>
                            <label><small>Req/min</small><input class="small-text" name="rate_limit" type="number" min="10" max="5000" value="<?php echo esc_attr( $key['rate_limit'] ); ?>"></label>
                            <label><small>Citas/día</small><input class="small-text" name="daily_limit" type="number" min="0" value="<?php echo esc_attr( $key['appointment_daily_limit'] ); ?>"></label>
                            <label><small>Citas/mes</small><input class="small-text" name="monthly_limit" type="number" min="0" value="<?php echo esc_attr( $key['appointment_monthly_limit'] ); ?>"></label>
                            <label><small>Reprog./cita</small><input class="small-text" name="reschedule_limit" type="number" min="0" value="<?php echo esc_attr( $key['reschedule_limit'] ); ?>"></label>
                            <label style="grid-column:1/-1"><small>IP/CIDR permitidos (vacío = cualquiera)</small><textarea name="allowed_ips" rows="2" style="width:100%"><?php echo esc_textarea( $key['allowed_ips'] ); ?></textarea></label>
                            <button class="button" style="grid-column:1/-1">Guardar permisos y límites</button>
                        </form>
                    </td>
                    <td>
                        <?php if ( 'active' === $key['status'] ) : ?>
                            <form method="post">
                                <?php wp_nonce_field( 'vmdpapi_manage_keys' ); ?>
                                <input type="hidden" name="vmdpapi_action" value="revoke">
                                <input type="hidden" name="key_id" value="<?php echo esc_attr( $key['id'] ); ?>">
                                <button class="button button-link-delete" onclick="return confirm('¿Revocar esta credencial?');">Revocar</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php
    }

    private function render_appointments() {
        $keys       = $this->repository->list_keys();
        $partner_id = isset( $_GET['partner_id'] ) ? absint( $_GET['partner_id'] ) : 0;
        $page       = isset( $_GET['record_page'] ) ? max( 1, absint( $_GET['record_page'] ) ) : 1;
        $per_page   = 50;
        $total      = $this->repository->count_appointments_for_admin( $partner_id );
        $rows       = $this->repository->list_appointments_for_admin( $partner_id, $per_page, ( $page - 1 ) * $per_page );
        ?>
        <p>Registro de las consultas creadas mediante la API. No muestra nombre, correo ni teléfono del paciente.</p>
        <form method="get" style="margin:16px 0">
            <input type="hidden" name="page" value="virtualmd-partner-api">
            <input type="hidden" name="tab" value="appointments">
            <label for="vmdpapi-partner-filter">Proveedor:</label>
            <select id="vmdpapi-partner-filter" name="partner_id">
                <option value="0">Todos</option>
                <?php foreach ( $keys as $key ) : ?>
                    <option value="<?php echo esc_attr( $key['id'] ); ?>" <?php selected( $partner_id, (int) $key['id'] ); ?>><?php echo esc_html( $key['name'] ); ?></option>
                <?php endforeach; ?>
            </select>
            <button class="button">Filtrar</button>
        </form>
        <p><strong><?php echo esc_html( $total ); ?></strong> consultas registradas.</p>
        <div style="overflow:auto">
        <table class="widefat striped" style="min-width:1250px">
            <thead><tr><th>Creada</th><th>Proveedor externo</th><th>Referencia</th><th>Servicio</th><th>Doctor</th><th>Horario actual</th><th>Estado</th><th>Reprogramaciones</th><th>Historial</th><th>IDs internos</th></tr></thead>
            <tbody>
            <?php if ( empty( $rows ) ) : ?><tr><td colspan="10">Aún no hay consultas generadas por la API.</td></tr><?php endif; ?>
            <?php foreach ( $rows as $row ) : ?>
                <tr>
                    <td><?php echo esc_html( $row['created_at'] ); ?> UTC</td>
                    <td><?php echo esc_html( $row['partner_name'] ); ?></td>
                    <td><code><?php echo esc_html( $row['external_reference'] ); ?></code><?php if ( $row['patient_reference'] ) : ?><br><small>Paciente: <?php echo esc_html( $row['patient_reference'] ); ?></small><?php endif; ?></td>
                    <td><?php echo esc_html( $row['service_name'] ?: '#' . $row['service_id'] ); ?></td>
                    <td><?php echo esc_html( trim( $row['provider_name'] ) ?: '#' . $row['provider_id'] ); ?></td>
                    <td><?php echo esc_html( $row['starts_at'] ); ?> (WordPress)</td>
                    <td><?php echo esc_html( $row['current_status'] ); ?></td>
                    <td><?php echo esc_html( $row['reschedule_count'] ); ?><?php if ( $row['last_rescheduled_at'] ) : ?><br><small>Última: <?php echo esc_html( $row['last_rescheduled_at'] ); ?> UTC</small><?php endif; ?></td>
                    <td>
                        <?php $events = $this->repository->list_appointment_events_for_admin( $row['id'] ); ?>
                        <details><summary><?php echo esc_html( count( $events ) ); ?> eventos</summary>
                            <?php foreach ( $events as $event ) : ?>
                                <p style="min-width:260px"><strong><?php echo esc_html( $event['event_type'] ); ?></strong> · <?php echo esc_html( $event['created_at'] ); ?> UTC<br>
                                <?php if ( $event['old_starts_at'] ) : ?><small>Antes: <?php echo esc_html( $event['old_starts_at'] ); ?> / doctor <?php echo esc_html( $event['old_provider_id'] ); ?></small><br><?php endif; ?>
                                <?php if ( $event['new_starts_at'] ) : ?><small>Después: <?php echo esc_html( $event['new_starts_at'] ); ?> / doctor <?php echo esc_html( $event['new_provider_id'] ); ?></small><?php endif; ?></p>
                            <?php endforeach; ?>
                        </details>
                    </td>
                    <td><small>booking <?php echo esc_html( $row['amelia_booking_id'] ); ?><br>appointment <?php echo esc_html( $row['amelia_appointment_id'] ); ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php
        $pages = (int) ceil( $total / $per_page );
        if ( $pages > 1 ) {
            echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( [
                'base'      => add_query_arg( 'record_page', '%#%', admin_url( 'tools.php?page=virtualmd-partner-api&tab=appointments&partner_id=' . $partner_id ) ),
                'format'    => '',
                'current'   => $page,
                'total'     => $pages,
                'prev_text' => '‹',
                'next_text' => '›',
            ] ) ) . '</div></div>';
        }
        ?>
        <?php
    }
}
