<?php
/**
 * Admin: distribution panel ("Reparto").
 *
 * Read only for the distribution: it shows what happened (counters), the
 * state of each round and the retry queue, and never changes who gets what.
 *
 * @package MulticorreoGF
 */

defined( 'WPINC' ) || exit;

/**
 * Dashboard class.
 */
class MCGF_Dashboard {

	const PAGE = 'mcgf-stats';

	const KIND_LABELS = array(
		'assign'  => 'Asignados',
		'sticky'  => 'Misma persona',
		'sent'    => 'Enviados',
		'fail'    => 'Fallos',
		'retried' => 'Recuperados',
		'lost'    => 'Perdidos',
	);

	/**
	 * Hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ), 15 );
		add_action( 'admin_notices', array( $this, 'failed_notice' ) );
		add_action( 'admin_post_mcgf_export', array( $this, 'export' ) );
		add_action( 'admin_post_mcgf_queue', array( $this, 'queue_action' ) );
		add_action( 'admin_post_mcgf_reset_stats', array( $this, 'reset_stats' ) );
	}

	/**
	 * Menu.
	 *
	 * @return void
	 */
	public function menu() {

		$counts = MCGF_Queue::counts();
		$bubble = $counts['failed'] ? ' <span class="awaiting-mod"><span>' . (int) $counts['failed'] . '</span></span>' : '';

		add_submenu_page(
			MCGF_Admin::PAGE_LISTS,
			__( 'Reparto', 'multicorreo-gf' ),
			__( 'Reparto', 'multicorreo-gf' ) . $bubble,
			MCGF_Roles::CAP,
			self::PAGE,
			array( $this, 'page' )
		);
	}

	/**
	 * Panel URL.
	 *
	 * @param array $args Args.
	 * @return string
	 */
	public static function url( $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE ) );
	}

	/**
	 * Notice when some email could not be sent after every retry.
	 *
	 * @return void
	 */
	public function failed_notice() {

		if ( ! current_user_can( MCGF_Roles::CAP ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && false !== strpos( (string) $screen->id, self::PAGE ) ) {
			return;
		}

		$counts = MCGF_Queue::counts();
		if ( ! $counts['failed'] ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>Multicorreo</strong> — %1$s <a href="%2$s">%3$s</a></p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of emails */
					_n( '%d notificación no se pudo enviar tras todos los reintentos.', '%d notificaciones no se pudieron enviar tras todos los reintentos.', $counts['failed'], 'multicorreo-gf' ),
					$counts['failed']
				)
			),
			esc_url( self::url() . '#mcgf-queue' ),
			esc_html__( 'Revisar y reintentar', 'multicorreo-gf' )
		);
	}

	// ------------------------------------------------------------ filters.

	/**
	 * Current filters from the request.
	 *
	 * @return array [ period, from, to, list, form ]
	 */
	private function filters() {

		// phpcs:disable WordPress.Security.NonceVerification
		$period = isset( $_GET['period'] ) ? sanitize_key( $_GET['period'] ) : '30';
		$list   = isset( $_GET['list'] ) ? absint( $_GET['list'] ) : 0;
		$form   = isset( $_GET['form'] ) ? absint( $_GET['form'] ) : 0;
		$from   = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '';
		$to     = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';
		// phpcs:enable

		$today = MCGF_Stats::today();

		if ( 'custom' === $period && self::is_date( $from ) && self::is_date( $to ) ) {
			if ( $from > $to ) {
				list( $from, $to ) = array( $to, $from );
			}
		} else {
			$days   = array(
				'today' => 0,
				'7'     => 6,
				'30'    => 29,
				'90'    => 89,
				'365'   => 364,
			);
			$period = isset( $days[ $period ] ) ? $period : '30';
			$to     = $today;
			$from   = gmdate( 'Y-m-d', strtotime( $today . ' -' . $days[ $period ] . ' days' ) );
		}

		return compact( 'period', 'from', 'to', 'list', 'form' );
	}

	/**
	 * Y-m-d?
	 *
	 * @param string $date Date.
	 * @return bool
	 */
	private static function is_date( $date ) {
		return (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && false !== strtotime( $date );
	}

	/**
	 * Group stats totals by list → email (applying the form filter).
	 *
	 * @param array $totals [kind][item] => hits.
	 * @param int   $form   Form filter (0 = all).
	 * @return array [ list_id ][ email ][ kind ] => hits
	 */
	private function group( $totals, $form ) {

		$out = array();

		foreach ( $totals as $kind => $items ) {
			foreach ( $items as $item => $hits ) {
				list( $list_id, $form_id, $email ) = MCGF_Stats::split( $item );
				if ( $form && $form !== $form_id ) {
					continue;
				}
				if ( ! isset( $out[ $list_id ][ $email ] ) ) {
					$out[ $list_id ][ $email ] = array_fill_keys( MCGF_Stats::KINDS, 0 );
				}
				$out[ $list_id ][ $email ][ $kind ] += $hits;
			}
		}

		return $out;
	}

	/**
	 * Form titles.
	 *
	 * @return array [ id => title ]
	 */
	private function form_titles() {
		$out = array();
		foreach ( (array) GFFormsModel::get_forms( null, 'title' ) as $row ) {
			$out[ (int) $row->id ] = (string) $row->title;
		}
		return $out;
	}

	// ------------------------------------------------------------- render.

	/**
	 * Render the panel.
	 *
	 * @return void
	 */
	public function page() {

		$f      = $this->filters();
		$lists  = MCGF_Lists::all();
		$forms  = $this->form_titles();
		$usage  = MCGF_Lists::usage();
		$totals = MCGF_Stats::totals( $f['from'], $f['to'] );
		$group  = $this->group( $totals, $f['form'] );

		echo '<div class="wrap mcgf-wrap mcgf-dash">';
		echo '<h1>' . esc_html__( 'Reparto de correos', 'multicorreo-gf' ) . '</h1>';

		$this->messages();
		$this->render_filters( $f, $lists, $forms );

		// KPIs over the filtered lists.
		$sum = array_fill_keys( MCGF_Stats::KINDS, 0 );
		foreach ( $group as $list_id => $emails ) {
			if ( $f['list'] && $f['list'] !== $list_id ) {
				continue;
			}
			foreach ( $emails as $kinds ) {
				foreach ( $kinds as $kind => $hits ) {
					$sum[ $kind ] += $hits;
				}
			}
		}

		$queue = MCGF_Queue::counts();

		echo '<div class="mcgf-kpis">';
		$this->kpi( __( 'Envíos repartidos', 'multicorreo-gf' ), $sum['assign'], __( 'Cupos entregados (uno por envío nuevo)', 'multicorreo-gf' ) );
		$this->kpi( __( 'Misma persona', 'multicorreo-gf' ), $sum['sticky'], __( 'Volvió a escribir y le tocó el mismo correo, sin gastar cupo', 'multicorreo-gf' ) );
		$this->kpi( __( 'Correos enviados', 'multicorreo-gf' ), $sum['sent'], __( 'Entregados al servidor de correo (incluye reenvíos)', 'multicorreo-gf' ) );
		$this->kpi( __( 'Recuperados', 'multicorreo-gf' ), $sum['retried'], __( 'Fallaron y salieron después con un reintento', 'multicorreo-gf' ) );
		$this->kpi( __( 'Pendientes de reintento', 'multicorreo-gf' ), $queue['pending'], __( 'Se reintentan solos', 'multicorreo-gf' ), $queue['pending'] ? 'warn' : '' );
		$this->kpi( __( 'Sin enviar', 'multicorreo-gf' ), $queue['failed'], __( 'Fallaron en todos los reintentos: revísalos abajo', 'multicorreo-gf' ), $queue['failed'] ? 'bad' : '' );
		echo '</div>';

		$this->render_chart( $f );

		// One block per list.
		$shown = 0;
		foreach ( $lists as $list ) {
			if ( $f['list'] && $f['list'] !== $list['id'] ) {
				continue;
			}
			$uses = isset( $usage[ $list['id'] ] ) ? $usage[ $list['id'] ] : array();
			if ( ! $uses && empty( $group[ $list['id'] ] ) && ! $f['list'] ) {
				continue;
			}
			$this->render_list( $list, isset( $group[ $list['id'] ] ) ? $group[ $list['id'] ] : array(), $uses, $f );
			$shown++;
		}

		if ( ! $shown ) {
			echo '<div class="mcgf-card"><p>' . esc_html__( 'Aún no hay listas en uso. Crea una lista y elígela en una notificación (Enviar a › Reparto aleatorio).', 'multicorreo-gf' ) . ' <a href="' . esc_url( MCGF_Admin::url() ) . '">' . esc_html__( 'Ir a las listas', 'multicorreo-gf' ) . '</a></p></div>';
		}

		$this->render_queue( $lists, $forms );

		// Tools.
		$reset = wp_nonce_url( admin_url( 'admin-post.php?action=mcgf_reset_stats' ), 'mcgf_reset_stats' );
		echo '<p class="mcgf-tools"><a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'mcgf_export' ), $f ), admin_url( 'admin-post.php' ) ), 'mcgf_export' ) ) . '">' . esc_html__( 'Exportar CSV (Excel)', 'multicorreo-gf' ) . '</a> ';
		echo '<a class="button-link-delete" href="' . esc_url( $reset ) . '" data-mcgf-confirm="' . esc_attr__( '¿Borrar todas las estadísticas? El reparto y las rondas no cambian.', 'multicorreo-gf' ) . '">' . esc_html__( 'Borrar estadísticas', 'multicorreo-gf' ) . '</a></p>';

		echo '<p class="description">' . esc_html__( 'Solo se guardan totales diarios por correo; los datos de cada persona están en sus entradas de Gravity Forms. Las estadísticas de más de 13 meses se borran solas.', 'multicorreo-gf' ) . '</p>';

		echo '</div>';
	}

	/**
	 * Messages after an action.
	 *
	 * @return void
	 */
	private function messages() {

		$msg   = isset( $_GET['mcgf_msg'] ) ? sanitize_key( $_GET['mcgf_msg'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$texts = array(
			'retried'   => __( 'Reintento hecho. Mira el resultado en la tabla.', 'multicorreo-gf' ),
			'discarded' => __( 'Envío descartado: no se volverá a intentar.', 'multicorreo-gf' ),
			'stats'     => __( 'Estadísticas borradas.', 'multicorreo-gf' ),
		);

		if ( isset( $texts[ $msg ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $texts[ $msg ] ) . '</p></div>';
		}
	}

	/**
	 * Filters bar.
	 *
	 * @param array $f     Filters.
	 * @param array $lists Lists.
	 * @param array $forms Forms.
	 * @return void
	 */
	private function render_filters( $f, $lists, $forms ) {

		$periods = array(
			'today'  => __( 'Hoy', 'multicorreo-gf' ),
			'7'      => __( 'Últimos 7 días', 'multicorreo-gf' ),
			'30'     => __( 'Últimos 30 días', 'multicorreo-gf' ),
			'90'     => __( 'Últimos 90 días', 'multicorreo-gf' ),
			'365'    => __( 'Últimos 12 meses', 'multicorreo-gf' ),
			'custom' => __( 'Fechas a elegir', 'multicorreo-gf' ),
		);

		echo '<form method="get" class="mcgf-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';

		echo '<label>' . esc_html__( 'Periodo', 'multicorreo-gf' ) . ' <select name="period" id="mcgf-period">';
		foreach ( $periods as $value => $label ) {
			printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $value ), selected( $f['period'], $value, false ), esc_html( $label ) );
		}
		echo '</select></label>';

		echo '<span class="mcgf-custom"' . ( 'custom' === $f['period'] ? '' : ' hidden' ) . '>';
		echo '<input type="date" name="from" value="' . esc_attr( $f['from'] ) . '"> – <input type="date" name="to" value="' . esc_attr( $f['to'] ) . '">';
		echo '</span>';

		echo '<label>' . esc_html__( 'Lista', 'multicorreo-gf' ) . ' <select name="list"><option value="0">' . esc_html__( 'Todas', 'multicorreo-gf' ) . '</option>';
		foreach ( $lists as $list ) {
			printf( '<option value="%1$d" %2$s>%3$s</option>', (int) $list['id'], selected( $f['list'], $list['id'], false ), esc_html( $list['name'] ) );
		}
		echo '</select></label>';

		echo '<label>' . esc_html__( 'Formulario', 'multicorreo-gf' ) . ' <select name="form"><option value="0">' . esc_html__( 'Todos', 'multicorreo-gf' ) . '</option>';
		foreach ( $forms as $id => $title ) {
			printf( '<option value="%1$d" %2$s>%3$s</option>', (int) $id, selected( $f['form'], $id, false ), esc_html( $title ) );
		}
		echo '</select></label>';

		submit_button( __( 'Ver', 'multicorreo-gf' ), 'secondary', '', false );

		echo '<span class="mcgf-range">' . esc_html( mysql2date( get_option( 'date_format' ), $f['from'] ) . ' – ' . mysql2date( get_option( 'date_format' ), $f['to'] ) ) . '</span>';
		echo '</form>';
	}

	/**
	 * KPI tile.
	 *
	 * @param string $label Label.
	 * @param int    $value Value.
	 * @param string $hint  Hint.
	 * @param string $tone  '', warn, bad.
	 * @return void
	 */
	private function kpi( $label, $value, $hint, $tone = '' ) {
		printf(
			'<div class="mcgf-kpi %1$s"><span class="mcgf-kpi-label">%2$s</span><span class="mcgf-kpi-value">%3$s</span><span class="mcgf-kpi-hint">%4$s</span></div>',
			esc_attr( $tone ? 'is-' . $tone : '' ),
			esc_html( $label ),
			esc_html( number_format_i18n( $value ) ),
			esc_html( $hint )
		);
	}

	/**
	 * Daily bars (new slots per day).
	 *
	 * @param array $f Filters.
	 * @return void
	 */
	private function render_chart( $f ) {

		$days = array();
		$day  = new DateTime( $f['from'] );
		$last = new DateTime( $f['to'] );

		while ( $day <= $last ) {
			$days[ $day->format( 'Y-m-d' ) ] = 0;
			$day->modify( '+1 day' );
		}

		if ( count( $days ) < 2 ) {
			return;
		}

		foreach ( MCGF_Stats::rows( $f['from'], $f['to'] ) as $row ) {
			if ( 'assign' !== $row['kind'] || ! isset( $days[ $row['day'] ] ) ) {
				continue;
			}
			list( $list_id, $form_id ) = MCGF_Stats::split( $row['item'] );
			if ( ( $f['list'] && $f['list'] !== $list_id ) || ( $f['form'] && $f['form'] !== $form_id ) ) {
				continue;
			}
			$days[ $row['day'] ] += (int) $row['hits'];
		}

		// Long ranges: group by month so the bars stay readable.
		if ( count( $days ) > 92 ) {
			$months = array();
			foreach ( $days as $d => $hits ) {
				$m            = substr( $d, 0, 7 );
				$months[ $m ] = ( isset( $months[ $m ] ) ? $months[ $m ] : 0 ) + $hits;
			}
			$bars   = $months;
			$format = 'M Y';
			$suffix = '-01';
		} else {
			$bars   = $days;
			$format = get_option( 'date_format' );
			$suffix = '';
		}

		$max = max( 1, max( $bars ) );

		echo '<div class="mcgf-card"><h2>' . esc_html__( 'Envíos repartidos por día', 'multicorreo-gf' ) . '</h2>';
		echo '<div class="mcgf-chart" role="img" aria-label="' . esc_attr__( 'Envíos repartidos por día', 'multicorreo-gf' ) . '">';
		foreach ( $bars as $key => $hits ) {
			$label = mysql2date( $format, $key . $suffix ) . ': ' . number_format_i18n( $hits );
			printf(
				'<span class="mcgf-bar" style="height:%1$s%%" title="%2$s"></span>',
				esc_attr( $hits ? max( 2, round( $hits / $max * 100, 1 ) ) : 0 ),
				esc_attr( $label )
			);
		}
		echo '</div><div class="mcgf-chart-axis"><span>' . esc_html( mysql2date( $format, array_keys( $bars )[0] . $suffix ) ) . '</span><span>' . esc_html( number_format_i18n( $max ) ) . ' ' . esc_html__( 'máx.', 'multicorreo-gf' ) . '</span><span>' . esc_html( mysql2date( $format, array_keys( $bars )[ count( $bars ) - 1 ] . $suffix ) ) . '</span></div></div>';
	}

	/**
	 * One list: share per email and rounds per form.
	 *
	 * @param array $list   List.
	 * @param array $emails [ email ][ kind ] => hits.
	 * @param array $uses   Notifications using it.
	 * @param array $f      Filters.
	 * @return void
	 */
	private function render_list( $list, $emails, $uses, $f ) {

		$slots = MCGF_Lists::slots( $list['emails'] );
		$total = max( 1, count( $list['emails'] ) );

		// Every email of the list, plus emails that got something in the
		// period but are no longer in it.
		$rows = array();
		foreach ( $slots as $email => $n ) {
			$rows[ $email ] = isset( $emails[ $email ] ) ? $emails[ $email ] : array_fill_keys( MCGF_Stats::KINDS, 0 );
		}
		foreach ( $emails as $email => $kinds ) {
			if ( ! isset( $rows[ $email ] ) ) {
				$rows[ $email ] = $kinds;
			}
		}

		$assigned = 0;
		foreach ( $rows as $kinds ) {
			$assigned += $kinds['assign'];
		}

		echo '<div class="mcgf-card mcgf-list-card">';
		echo '<h2>' . esc_html( $list['name'] ) . ' <a class="mcgf-edit" href="' . esc_url(
			MCGF_Admin::url(
				array(
					'action' => 'edit',
					'list'   => $list['id'],
				)
			)
		) . '">' . esc_html__( 'Editar lista', 'multicorreo-gf' ) . '</a></h2>';

		// Rounds.
		$forms_used = array();
		foreach ( $uses as $use ) {
			if ( $f['form'] && $f['form'] !== $use['form_id'] ) {
				continue;
			}
			$forms_used[ $use['form_id'] ] = $use['form_title'];
		}

		if ( $forms_used ) {
			echo '<div class="mcgf-rounds">';
			foreach ( $forms_used as $form_id => $title ) {
				$state = MCGF_Bag::state( $list, $form_id );
				$pct   = $state['size'] ? round( $state['given'] / $state['size'] * 100 ) : 0;
				$text  = $state['round']
					? sprintf(
						/* translators: 1: round, 2: given, 3: size */
						__( 'Ronda %1$d: %2$d de %3$d cupos repartidos', 'multicorreo-gf' ),
						$state['round'],
						$state['given'],
						$state['size']
					)
					: __( 'La ronda empieza con el próximo envío', 'multicorreo-gf' );
				echo '<div class="mcgf-round"><span class="mcgf-round-form">' . esc_html( $title ) . '</span>';
				echo '<span class="mcgf-progress"><span style="width:' . esc_attr( $pct ) . '%"></span></span>';
				echo '<span class="mcgf-round-text">' . esc_html( $text ) . '</span></div>';
			}
			echo '</div>';
		}

		echo '<table class="widefat striped mcgf-table mcgf-share"><thead><tr>';
		echo '<th>' . esc_html__( 'Correo', 'multicorreo-gf' ) . '</th>';
		echo '<th class="num">' . esc_html__( 'Cupos', 'multicorreo-gf' ) . '</th>';
		echo '<th class="num" title="' . esc_attr__( 'Parte que le corresponde por sus cupos', 'multicorreo-gf' ) . '">' . esc_html__( 'Le toca', 'multicorreo-gf' ) . '</th>';
		echo '<th class="num">' . esc_html__( 'Repartidos', 'multicorreo-gf' ) . '</th>';
		echo '<th class="num" title="' . esc_attr__( 'Parte real de los envíos repartidos en el periodo', 'multicorreo-gf' ) . '">' . esc_html__( 'Recibe', 'multicorreo-gf' ) . '</th>';
		echo '<th>' . esc_html__( 'Equilibrio', 'multicorreo-gf' ) . '</th>';
		echo '<th class="num">' . esc_html__( 'Misma persona', 'multicorreo-gf' ) . '</th>';
		echo '<th class="num">' . esc_html__( 'Enviados', 'multicorreo-gf' ) . '</th>';
		echo '<th class="num">' . esc_html__( 'Fallos', 'multicorreo-gf' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $email => $kinds ) {

			$n        = isset( $slots[ $email ] ) ? $slots[ $email ] : 0;
			$expected = $n / $total * 100;
			$real     = $assigned ? $kinds['assign'] / $assigned * 100 : 0;
			$diff     = $real - $expected;

			echo '<tr><td>' . esc_html( $email );
			if ( ! $n ) {
				echo ' <span class="mcgf-tag">' . esc_html__( 'ya no está en la lista', 'multicorreo-gf' ) . '</span>';
			}
			echo '</td>';
			echo '<td class="num">' . ( $n ? esc_html( number_format_i18n( $n ) ) : '—' ) . '</td>';
			echo '<td class="num">' . esc_html( number_format_i18n( $expected, 1 ) ) . '%</td>';
			echo '<td class="num"><strong>' . esc_html( number_format_i18n( $kinds['assign'] ) ) . '</strong></td>';
			echo '<td class="num">' . ( $assigned ? esc_html( number_format_i18n( $real, 1 ) ) . '%' : '—' ) . '</td>';

			// Balance bar: real share against the expected one.
			echo '<td class="mcgf-balance">';
			if ( $assigned ) {
				$tone = abs( $diff ) < 3 ? 'ok' : ( abs( $diff ) < 8 ? 'warn' : 'bad' );
				printf(
					'<span class="mcgf-meter"><span class="mcgf-meter-real is-%1$s" style="width:%2$s%%"></span><span class="mcgf-meter-mark" style="left:%3$s%%"></span></span> <span class="mcgf-diff is-%1$s">%4$s</span>',
					esc_attr( $tone ),
					esc_attr( min( 100, round( $real, 1 ) ) ),
					esc_attr( min( 100, round( $expected, 1 ) ) ),
					esc_html( ( $diff >= 0 ? '+' : '' ) . number_format_i18n( $diff, 1 ) )
				);
			}
			echo '</td>';

			echo '<td class="num">' . esc_html( number_format_i18n( $kinds['sticky'] ) ) . '</td>';
			echo '<td class="num">' . esc_html( number_format_i18n( $kinds['sent'] ) ) . '</td>';
			echo '<td class="num">' . ( $kinds['fail'] ? '<span class="mcgf-diff is-bad">' . esc_html( number_format_i18n( $kinds['fail'] ) ) . '</span>' : '0' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( '«Equilibrio» compara la parte que recibe cada correo con la que le corresponde por sus cupos (la marca). En periodos cortos es normal que no sea exacto: el reparto se iguala al completar cada ronda.', 'multicorreo-gf' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Retry queue.
	 *
	 * @param array $lists Lists.
	 * @param array $forms Forms.
	 * @return void
	 */
	private function render_queue( $lists, $forms ) {

		$rows = MCGF_Queue::rows();

		echo '<div class="mcgf-card" id="mcgf-queue"><h2>' . esc_html__( 'Envíos pendientes o sin enviar', 'multicorreo-gf' ) . '</h2>';

		if ( ! $rows ) {
			echo '<p class="mcgf-ok">' . esc_html__( 'Todo enviado: no hay nada pendiente.', 'multicorreo-gf' ) . '</p></div>';
			return;
		}

		echo '<p class="description">' . esc_html__( 'Si un envío falla, se reintenta solo al mismo correo a los 5 min, 15 min, 1 h, 3 h, 6 h y 12 h. Si sigue fallando queda como «sin enviar». Casi siempre es el servidor de correo (SMTP) del sitio: revísalo y pulsa «Reintentar ahora».', 'multicorreo-gf' ) . '</p>';

		echo '<table class="widefat striped mcgf-table"><thead><tr>';
		echo '<th>' . esc_html__( 'Estado', 'multicorreo-gf' ) . '</th>';
		echo '<th>' . esc_html__( 'Entrada', 'multicorreo-gf' ) . '</th>';
		echo '<th>' . esc_html__( 'Correo asignado', 'multicorreo-gf' ) . '</th>';
		echo '<th class="num">' . esc_html__( 'Intentos', 'multicorreo-gf' ) . '</th>';
		echo '<th>' . esc_html__( 'Próximo intento', 'multicorreo-gf' ) . '</th>';
		echo '<th>' . esc_html__( 'Último error', 'multicorreo-gf' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {

			$entry_url = admin_url( 'admin.php?page=gf_entries&view=entry&id=' . (int) $row['form_id'] . '&lid=' . (int) $row['entry_id'] );
			$form      = isset( $forms[ (int) $row['form_id'] ] ) ? $forms[ (int) $row['form_id'] ] : '#' . (int) $row['form_id'];
			$retry     = wp_nonce_url( admin_url( 'admin-post.php?action=mcgf_queue&do=retry&row=' . (int) $row['id'] ), 'mcgf_queue_' . (int) $row['id'] );
			$discard   = wp_nonce_url( admin_url( 'admin-post.php?action=mcgf_queue&do=discard&row=' . (int) $row['id'] ), 'mcgf_queue_' . (int) $row['id'] );
			$failed    = 'failed' === $row['status'];

			echo '<tr>';
			echo '<td><span class="mcgf-status is-' . ( $failed ? 'bad' : 'warn' ) . '">' . esc_html( $failed ? __( 'Sin enviar', 'multicorreo-gf' ) : __( 'Pendiente', 'multicorreo-gf' ) ) . '</span></td>';
			echo '<td><a href="' . esc_url( $entry_url ) . '">' . esc_html( $form . ' › #' . (int) $row['entry_id'] ) . '</a><br><span class="mcgf-muted">' . esc_html( get_date_from_gmt( $row['created'], get_option( 'date_format' ) . ' H:i' ) ) . '</span></td>';
			echo '<td>' . esc_html( $row['email'] ) . '</td>';
			echo '<td class="num">' . esc_html( number_format_i18n( (int) $row['attempts'] ) ) . '</td>';
			echo '<td>' . ( $failed ? '—' : esc_html( get_date_from_gmt( $row['next_try'], get_option( 'date_format' ) . ' H:i' ) ) ) . '</td>';
			echo '<td class="mcgf-error">' . esc_html( (string) $row['last_error'] ) . '</td>';
			echo '<td class="mcgf-actions"><a class="button button-small" href="' . esc_url( $retry ) . '">' . esc_html__( 'Reintentar ahora', 'multicorreo-gf' ) . '</a> ';
			echo '<a class="button-link-delete" href="' . esc_url( $discard ) . '" data-mcgf-confirm="' . esc_attr__( '¿Descartar este envío? No se volverá a intentar.', 'multicorreo-gf' ) . '">' . esc_html__( 'Descartar', 'multicorreo-gf' ) . '</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}

	// ------------------------------------------------------------ actions.

	/**
	 * Retry now / discard a queued email.
	 *
	 * @return void
	 */
	public function queue_action() {

		$id = isset( $_GET['row'] ) ? absint( $_GET['row'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$do = isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		check_admin_referer( 'mcgf_queue_' . $id );

		if ( ! current_user_can( MCGF_Roles::CAP ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'multicorreo-gf' ) );
		}

		if ( 'discard' === $do ) {
			MCGF_Queue::discard( $id );
			$msg = 'discarded';
		} else {
			MCGF_Queue::requeue( $id );
			MCGF_Queue::process( $id );
			$msg = 'retried';
		}

		wp_safe_redirect( self::url( array( 'mcgf_msg' => $msg ) ) . '#mcgf-queue' );
		exit;
	}

	/**
	 * Delete every statistic.
	 *
	 * @return void
	 */
	public function reset_stats() {

		check_admin_referer( 'mcgf_reset_stats' );

		if ( ! current_user_can( MCGF_Roles::CAP ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'multicorreo-gf' ) );
		}

		MCGF_Stats::reset();

		wp_safe_redirect( self::url( array( 'mcgf_msg' => 'stats' ) ) );
		exit;
	}

	/**
	 * CSV export (semicolon + BOM, opens fine in Excel in Spanish).
	 *
	 * @return void
	 */
	public function export() {

		check_admin_referer( 'mcgf_export' );

		if ( ! current_user_can( MCGF_Roles::CAP ) ) {
			wp_die( esc_html__( 'No tienes permiso para hacer esto.', 'multicorreo-gf' ) );
		}

		$f     = $this->filters();
		$lists = MCGF_Lists::all();
		$forms = $this->form_titles();
		$data  = array();

		foreach ( MCGF_Stats::rows( $f['from'], $f['to'] ) as $row ) {
			list( $list_id, $form_id, $email ) = MCGF_Stats::split( $row['item'] );
			if ( ( $f['list'] && $f['list'] !== $list_id ) || ( $f['form'] && $f['form'] !== $form_id ) ) {
				continue;
			}
			$key = $row['day'] . '|' . $row['item'];
			if ( ! isset( $data[ $key ] ) ) {
				$data[ $key ] = array(
					'day'    => $row['day'],
					'list'   => isset( $lists[ $list_id ] ) ? $lists[ $list_id ]['name'] : '#' . $list_id,
					'form'   => isset( $forms[ $form_id ] ) ? $forms[ $form_id ] : '#' . $form_id,
					'email'  => $email,
					'counts' => array_fill_keys( MCGF_Stats::KINDS, 0 ),
				);
			}
			$data[ $key ]['counts'][ $row['kind'] ] += (int) $row['hits'];
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=multicorreo-reparto-' . $f['from'] . '_' . $f['to'] . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$header = array( 'Fecha', 'Lista', 'Formulario', 'Correo' );
		foreach ( self::KIND_LABELS as $label ) {
			$header[] = $label;
		}
		fputcsv( $out, $header, ';' );

		foreach ( $data as $line ) {
			$csv = array( $line['day'], self::csv_safe( $line['list'] ), self::csv_safe( $line['form'] ), self::csv_safe( $line['email'] ) );
			foreach ( array_keys( self::KIND_LABELS ) as $kind ) {
				$csv[] = $line['counts'][ $kind ];
			}
			fputcsv( $out, $csv, ';' );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * Stop spreadsheet formula injection.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	private static function csv_safe( $value ) {
		$value = (string) $value;
		return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}
}
