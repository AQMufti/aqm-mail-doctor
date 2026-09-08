<?php
/**
 * Plugin Name: AQM Mail Doctor
 * Plugin URI:  https://github.com/AQMufti/aqm-mail-doctor
 * Description: Normalises outgoing mail to the aqmuftirealty.com standard, and records what the mail server ACTUALLY said when a message is refused. Adds a Mail screen under the AQM menu with a send test.
 * Version:     1.1.0
 * Author:      A. Q. Mufti
 * License:     GPL-2.0-or-later
 *
 * WHY THIS EXISTS
 * ===============
 *
 * Written 8 Sep 2026, after seven form notifications were refused with:
 *
 *     SMTP Error: Data not accepted.
 *
 * Every one of them was addressed to info@aqmufti.com.
 *
 * WHAT THE DATABASE SHOWS
 *
 * A scan of the 1 Sep 2026 backup found 41 Elementor forms with
 * "email_to": "info@aqmufti.com", and 11 with "email_from" set to the same
 * address. Nothing in any AQM plugin references that address; it is baked into
 * the Elementor form widgets themselves. WordPress's own admin_email is
 * aqmufti@hotmail.com, so this is not a core setting.
 *
 * Both aqmufti.com and aqmuftirealty.com have MX records pointing at Titan
 * (mx1/mx2.titan.email). AQM Mail Transport authenticates as
 * support@aqmuftirealty.com and forces that as the sender. So every form
 * notification is Titan being asked to carry mail from an aqmuftirealty.com
 * mailbox to an address on the OTHER domain.
 *
 * AQ's standing rule since 29 Aug 2026 is that aqmuftirealty.com is the single
 * standard everywhere, on every asset and profile. Those 41 forms never got the
 * memo.
 *
 * WHAT THIS FILE DOES ABOUT IT
 *
 * Two things, deliberately separate:
 *
 *   1. NORMALISE. Any recipient at aqmufti.com is rewritten to the same mailbox
 *      at aqmuftirealty.com before the message is handed to the transport. That
 *      is a redirect to an address AQ already reads, so no mail can be lost by
 *      it, and it stops the bleeding today without editing 41 Elementor forms
 *      by hand. Every rewrite is logged so the scale of it stays visible.
 *
 *      This is a bridge, not the cure. Fix the forms, then turn it off.
 *
 *   2. DIAGNOSE. "Data not accepted" is PHPMailer's own wording, not the mail
 *      server's - PHPMailer::smtpSend() emits it whenever SMTP::data() returns
 *      false, and throws away the server's actual reply. That is why seven
 *      failures produced no usable information. This captures the real SMTP
 *      conversation and the server's last reply, so the NEXT failure names its
 *      own cause instead of hiding it.
 *
 * IT DOES NOT TOUCH AQM MAIL TRANSPORT. That file holds the Titan password and
 * stays a must-use plugin - see section 6 of claude/aqm-plugin-standard.md.
 * This hooks at lower and higher priorities around it and leaves it alone.
 */

defined( 'ABSPATH' ) || exit;

define( 'AQM_MD_FILE', __FILE__ );
define( 'AQM_MD_VERSION', '1.1.0' );
define( 'AQM_MD_GITHUB_REPO', 'AQMufti/aqm-mail-doctor' );

require_once __DIR__ . '/aqm-updater.php';
new AQM_Updater(
	__FILE__,
	AQM_MD_VERSION,
	AQM_MD_GITHUB_REPO,
	'AQM Mail Doctor',
	'Normalises outgoing mail to the aqmuftirealty.com standard and records what the mail server actually said.'
);

/** Rewrite recipients on this domain... */
const AQM_MD_FROM_DOMAIN = 'aqmufti.com';
/** ...to the same mailbox on this one. */
const AQM_MD_TO_DOMAIN = 'aqmuftirealty.com';

/** Live capture of the SMTP conversation for the message being sent. */
$GLOBALS['aqm_md_wire'] = array();

/* =====================================================================
 * 1. Normalise recipients
 * ================================================================== */

/**
 * Rewrite name@aqmufti.com to name@aqmuftirealty.com.
 *
 * Handles the "Name <addr>" form as well as a bare address, and leaves
 * anything on another domain completely alone. Matching is anchored so that
 * aqmuftirealty.com itself can never match - it does not END with
 * "@aqmufti.com".
 */
function aqm_md_fix_address( $address ) {

	$address = trim( (string) $address );
	if ( '' === $address ) {
		return $address;
	}

	$pattern = '/@' . preg_quote( AQM_MD_FROM_DOMAIN, '/' ) . '(\s*>?)\s*$/i';

	return preg_replace( $pattern, '@' . AQM_MD_TO_DOMAIN . '$1', $address );
}

/**
 * wp_mail() hands us the whole argument array. Priority 1 so this runs before
 * anything else, and long before AQM Mail Transport touches PHPMailer at 999.
 */
add_filter(
	'wp_mail',
	function ( $args ) {

		$changed = array();

		foreach ( array( 'to' ) as $field ) {
			if ( empty( $args[ $field ] ) ) {
				continue;
			}
			$list  = is_array( $args[ $field ] ) ? $args[ $field ] : explode( ',', $args[ $field ] );
			$fixed = array();
			foreach ( $list as $one ) {
				$new = aqm_md_fix_address( $one );
				if ( $new !== trim( (string) $one ) ) {
					$changed[] = trim( (string) $one ) . ' -> ' . $new;
				}
				$fixed[] = $new;
			}
			$args[ $field ] = is_array( $args[ $field ] ) ? $fixed : implode( ', ', $fixed );
		}

		// Cc / Bcc / Reply-To live in the headers.
		if ( ! empty( $args['headers'] ) ) {
			$headers = is_array( $args['headers'] ) ? $args['headers'] : explode( "\n", str_replace( "\r\n", "\n", $args['headers'] ) );
			foreach ( $headers as $i => $h ) {
				if ( ! preg_match( '/^\s*(cc|bcc|reply-to)\s*:(.*)$/i', $h, $m ) ) {
					continue;
				}
				$parts = array_map( 'aqm_md_fix_address', explode( ',', $m[2] ) );
				$new   = $m[1] . ': ' . implode( ', ', $parts );
				if ( trim( $new ) !== trim( $h ) ) {
					$changed[]     = trim( $h ) . ' -> ' . trim( $new );
					$headers[ $i ] = $new;
				}
			}
			$args['headers'] = $headers;
		}

		if ( $changed ) {
			aqm_md_note( 'rewrote', $changed, isset( $args['subject'] ) ? $args['subject'] : '' );
		}

		return $args;
	},
	1
);

/* =====================================================================
 * 2. Capture what the server actually says
 * ================================================================== */

/**
 * Priority 10000 - after AQM Mail Transport (999) has configured the transport,
 * so we observe the connection it actually built rather than one we changed.
 *
 * Debugoutput is given a closure, so nothing is ever echoed to the page.
 */
add_action(
	'phpmailer_init',
	function ( $m ) {
		$GLOBALS['aqm_md_wire'] = array();
		$m->SMTPDebug           = 3;   // client, server and connection level
		$m->Debugoutput         = function ( $str, $level ) {
			$line = trim( (string) $str );
			if ( '' === $line ) {
				return;
			}
			// Never let a credential reach the log.
			if ( preg_match( '/AUTH\s+(LOGIN|PLAIN)|^[A-Za-z0-9+\/=]{20,}$/i', $line ) ) {
				$line = '[credential exchange withheld]';
			}
			$GLOBALS['aqm_md_wire'][] = $line;
			if ( count( $GLOBALS['aqm_md_wire'] ) > 120 ) {
				array_shift( $GLOBALS['aqm_md_wire'] );
			}
		};
	},
	10000
);

/**
 * Record the failure WITH the server's own words.
 *
 * PHPMailer::smtpSend() reports "SMTP Error: data not accepted." whenever
 * SMTP::data() returns false, discarding the reply that explains why. The reply
 * is still on the SMTP instance, so read it there.
 */
add_action(
	'wp_mail_failed',
	function ( $error ) {

		global $phpmailer;

		$data = $error->get_error_data();
		$to   = ( is_array( $data ) && ! empty( $data['to'] ) ) ? implode( ', ', (array) $data['to'] ) : '';

		$reply = '';
		if ( $phpmailer instanceof \PHPMailer\PHPMailer\PHPMailer ) {
			if ( method_exists( $phpmailer, 'getSMTPInstance' ) ) {
				$smtp = $phpmailer->getSMTPInstance();
				if ( $smtp && method_exists( $smtp, 'getLastReply' ) ) {
					$reply = trim( (string) $smtp->getLastReply() );
				}
			}
			if ( '' === $reply && ! empty( $phpmailer->ErrorInfo ) ) {
				$reply = trim( (string) $phpmailer->ErrorInfo );
			}
		}

		aqm_md_note(
			'failed',
			array(
				'to'           => $to,
				'wp_error'     => $error->get_error_message(),
				'server_reply' => '' !== $reply ? $reply : '(the server sent no reply text)',
				'wire'         => array_slice( (array) $GLOBALS['aqm_md_wire'], -25 ),
			),
			( is_array( $data ) && ! empty( $data['subject'] ) ) ? $data['subject'] : ''
		);
	},
	1
);

/** Append to the log, newest first, capped. */
function aqm_md_note( $kind, $detail, $subject = '' ) {
	$log = get_option( 'aqm_md_log', array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}
	array_unshift(
		$log,
		array(
			'when'    => current_time( 'mysql' ),
			'kind'    => $kind,
			'subject' => (string) $subject,
			'detail'  => $detail,
		)
	);
	update_option( 'aqm_md_log', array_slice( $log, 0, 40 ), false );
}

/* =====================================================================
 * 3. The cure: fix the Elementor forms themselves
 *
 * Added 1.1.0, once a test send to info@aqmuftirealty.com was confirmed
 * delivered end to end - which proved the transport was healthy and the
 * cross-domain recipient was the whole fault.
 *
 * Scope is deliberately narrow. Only Elementor FORM SETTINGS keys are touched -
 * email_to, email_from, email_reply_to, email_cc, email_bcc and their numbered
 * variants for a second email action. Page copy that displays info@aqmufti.com
 * as visible text is counted and reported but NEVER rewritten: changing what a
 * page says to a visitor is a content decision, not a mail fix.
 *
 * Revisions are excluded. Elementor writes _elementor_data on every revision,
 * so the raw count of matching meta rows is much larger than the number of real
 * pages, and rewriting revisions would achieve nothing.
 *
 * Every post gets its original _elementor_data copied to _aqm_md_backup before
 * the first write, once, so the whole pass is reversible.
 * ================================================================== */

/** Form setting keys whose value is an address we may rewrite. */
function aqm_md_email_keys_pattern() {
	return '/^email(_to|_from|_reply_to|_cc|_bcc)?(_\d+)?$/i';
}

/**
 * Walk a decoded Elementor tree, rewriting addresses in form settings only.
 *
 * @param mixed $node    Decoded JSON, by reference.
 * @param array $changes Collected "key: old -> new" strings, by reference.
 */
function aqm_md_walk( &$node, array &$changes ) {

	if ( ! is_array( $node ) ) {
		return;
	}

	foreach ( $node as $key => &$value ) {

		if ( is_string( $value )
			&& is_string( $key )
			&& preg_match( aqm_md_email_keys_pattern(), $key )
			&& false !== stripos( $value, '@' . AQM_MD_FROM_DOMAIN )
		) {
			$parts = array_map( 'aqm_md_fix_address', explode( ',', $value ) );
			$new   = implode( ', ', $parts );
			if ( $new !== $value ) {
				$changes[] = $key . ': ' . $value . ' -> ' . $new;
				$value     = $new;
			}
			continue;
		}

		if ( is_array( $value ) ) {
			aqm_md_walk( $value, $changes );
		}
	}
	unset( $value );
}

/**
 * Every non-revision post whose Elementor data mentions the old domain.
 *
 * @return array<int,array> id => row
 */
function aqm_md_scan( $apply = false ) {

	global $wpdb;

	$needle = '%' . $wpdb->esc_like( '@' . AQM_MD_FROM_DOMAIN ) . '%';

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_type, p.post_status
			   FROM {$wpdb->postmeta} m
			   JOIN {$wpdb->posts} p ON p.ID = m.post_id
			  WHERE m.meta_key = '_elementor_data'
			    AND m.meta_value LIKE %s
			    AND p.post_type <> 'revision'
			  ORDER BY p.post_type, p.post_title",
			$needle
		),
		ARRAY_A
	);

	$out = array();

	foreach ( (array) $rows as $row ) {

		$id  = (int) $row['ID'];
		$raw = get_post_meta( $id, '_elementor_data', true );

		if ( is_string( $raw ) ) {
			$data = json_decode( $raw, true );
		} else {
			$data = $raw;   // Elementor sometimes hands back an array already
		}

		if ( null === $data || ! is_array( $data ) ) {
			$out[ $id ] = array(
				'id'     => $id,
				'title'  => $row['post_title'],
				'type'   => $row['post_type'],
				'status' => $row['post_status'],
				'error'  => 'could not decode _elementor_data - skipped',
			);
			continue;
		}

		$changes = array();
		aqm_md_walk( $data, $changes );

		// Mentions that are page copy, not form settings. Counted, never touched.
		$copy_mentions = substr_count( strtolower( (string) $raw ), '@' . AQM_MD_FROM_DOMAIN ) - count( $changes );

		$entry = array(
			'id'            => $id,
			'title'         => $row['post_title'],
			'type'          => $row['post_type'],
			'status'        => $row['post_status'],
			'form_changes'  => $changes,
			'copy_mentions' => max( 0, $copy_mentions ),
			'applied'       => false,
		);

		if ( $apply && $changes ) {

			// Back up once, and only once, so a second run cannot overwrite the
			// pristine original with an already-modified copy.
			if ( '' === (string) get_post_meta( $id, '_aqm_md_backup', true ) ) {
				update_post_meta( $id, '_aqm_md_backup', wp_slash( (string) $raw ) );
				update_post_meta( $id, '_aqm_md_backup_at', current_time( 'mysql' ) );
			}

			$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

			if ( ! is_string( $json ) || '' === $json ) {
				$entry['error'] = 're-encoding failed - nothing written';
			} else {
				update_post_meta( $id, '_elementor_data', wp_slash( $json ) );

				// Elementor caches rendered CSS per post id; both are regenerated.
				delete_post_meta( $id, '_elementor_css' );
				delete_post_meta( $id, '_elementor_element_cache' );

				$entry['applied'] = true;
			}
		}

		$out[ $id ] = $entry;
	}

	return $out;
}

/** Put back the pre-change _elementor_data for one post. */
function aqm_md_restore( $id ) {
	$id     = (int) $id;
	$backup = get_post_meta( $id, '_aqm_md_backup', true );
	if ( '' === (string) $backup ) {
		return false;
	}
	update_post_meta( $id, '_elementor_data', wp_slash( (string) $backup ) );
	delete_post_meta( $id, '_elementor_css' );
	delete_post_meta( $id, '_elementor_element_cache' );
	return true;
}

/*
 * REST. One namespace per plugin - see claude/aqm-rest-namespace-standard.md.
 * No aqm/v1 alias: this plugin is new and has no callers to keep working.
 */
add_action(
	'rest_api_init',
	function () {

		register_rest_route(
			'aqm-maildoctor/v1',
			'/form-recipients',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					return current_user_can( 'edit_pages' );
				},
				'callback'            => function () {
					$scan = aqm_md_scan( false );
					return array(
						'from_domain' => AQM_MD_FROM_DOMAIN,
						'to_domain'   => AQM_MD_TO_DOMAIN,
						'posts'       => array_values( $scan ),
						'to_change'   => count( array_filter( $scan, function ( $r ) { return ! empty( $r['form_changes'] ); } ) ),
					);
				},
			)
		);

		register_rest_route(
			'aqm-maildoctor/v1',
			'/form-recipients',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'apply' => array( 'type' => 'boolean', 'default' => false ),
				),
				'callback'            => function ( $request ) {
					$apply = (bool) $request->get_param( 'apply' );
					$scan  = aqm_md_scan( $apply );
					return array(
						'applied'   => $apply,
						'posts'     => array_values( $scan ),
						'changed'   => count( array_filter( $scan, function ( $r ) { return ! empty( $r['applied'] ); } ) ),
						'to_change' => count( array_filter( $scan, function ( $r ) { return ! empty( $r['form_changes'] ); } ) ),
					);
				},
			)
		);

		register_rest_route(
			'aqm-maildoctor/v1',
			'/form-recipients/restore',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'callback'            => function ( $request ) {
					$ids  = (array) $request->get_param( 'ids' );
					$done = array();
					foreach ( $ids as $id ) {
						if ( aqm_md_restore( $id ) ) {
							$done[] = (int) $id;
						}
					}
					return array( 'restored' => $done );
				},
			)
		);
	}
);

/* =====================================================================
 * 4. The screen
 * ================================================================== */

add_action(
	'admin_menu',
	function () {
		// Registered as a top-level menu so AQM Admin Hub collects it like the
		// rest of the family. If the hub is inactive it simply stands alone.
		add_menu_page(
			'AQM Mail',
			'AQM Mail',
			'manage_options',
			'aqm-mail',
			'aqm_md_screen',
			'dashicons-email-alt',
			80
		);
	}
);

function aqm_md_screen() {

	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// --- actions -----------------------------------------------------
	$result = '';

	if ( ! empty( $_POST['aqm_md_test'] ) && check_admin_referer( 'aqm_md_test' ) ) {
		$to = sanitize_email( wp_unslash( $_POST['aqm_md_to'] ) );
		if ( ! is_email( $to ) ) {
			$result = '<div class="notice notice-error"><p>That is not a valid address.</p></div>';
		} else {
			$stamp = gmdate( 'Y-m-d H:i:s' );
			$ok    = wp_mail(
				$to,
				'AQM Mail Doctor test ' . $stamp . ' UTC',
				"Sent by AQM Mail Doctor at {$stamp} UTC.\n\nIf this arrived, the transport is working end to end."
			);
			$result = $ok
				? '<div class="notice notice-success"><p><strong>wp_mail() accepted it.</strong> Check the inbox. If nothing arrives, the message was accepted by Titan and dropped later - look at the log below.</p></div>'
				: '<div class="notice notice-error"><p><strong>wp_mail() refused it.</strong> The reason is the newest entry in the log below, in the mail server\'s own words.</p></div>';
		}
	}

	if ( ! empty( $_POST['aqm_md_clear'] ) && check_admin_referer( 'aqm_md_test' ) ) {
		delete_option( 'aqm_md_log' );
		$result = '<div class="notice notice-success"><p>Log cleared.</p></div>';
	}

	$log = (array) get_option( 'aqm_md_log', array() );

	echo '<div class="wrap"><h1>AQM Mail</h1>';
	echo $result; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed markup above.

	echo '<h2>Send a test</h2>';
	echo '<form method="post">';
	wp_nonce_field( 'aqm_md_test' );
	printf(
		'<p><input type="email" name="aqm_md_to" value="%s" class="regular-text" style="max-width:420px"> ',
		esc_attr( 'info@' . AQM_MD_TO_DOMAIN )
	);
	echo '<button class="button button-primary" name="aqm_md_test" value="1">Send test</button> ';
	echo '<button class="button" name="aqm_md_clear" value="1">Clear log</button></p>';
	echo '</form>';

	printf(
		'<p style="color:#50575e">Recipients at <code>%s</code> are rewritten to <code>%s</code> before sending. '
		. 'That is a bridge while the Elementor forms still point at the old domain &mdash; it is not the cure.</p>',
		esc_html( AQM_MD_FROM_DOMAIN ),
		esc_html( AQM_MD_TO_DOMAIN )
	);

	/* --- Elementor forms still on the old domain ------------------- */

	$scan      = aqm_md_scan( false );
	$to_change = array_filter( $scan, function ( $r ) { return ! empty( $r['form_changes'] ); } );
	$copy_only = array_filter( $scan, function ( $r ) { return empty( $r['form_changes'] ) && ! empty( $r['copy_mentions'] ); } );

	echo '<h2 style="margin-top:2em">Elementor forms</h2>';

	if ( ! $to_change ) {
		printf(
			'<p style="color:#00733c"><strong>No form is addressed to %s.</strong> '
			. 'Once you are satisfied nothing else needs it, the rewrite bridge above can be removed.</p>',
			esc_html( AQM_MD_FROM_DOMAIN )
		);
	} else {
		printf(
			'<p><strong>%d page%s still send form mail to %s.</strong> '
			. 'Fix them with the script rather than by hand &mdash; see below the table.</p>',
			count( $to_change ),
			1 === count( $to_change ) ? '' : 's',
			esc_html( AQM_MD_FROM_DOMAIN )
		);
		echo '<table class="widefat striped" style="max-width:1100px"><thead><tr>'
			. '<th>Page</th><th>Type</th><th>Status</th><th>Settings to change</th></tr></thead><tbody>';
		foreach ( $to_change as $r ) {
			printf(
				'<tr><td><a href="%s"><strong>%s</strong></a> <span style="color:#8c8f94">#%d</span></td>'
				. '<td>%s</td><td>%s</td><td><code>%s</code></td></tr>',
				esc_url( get_edit_post_link( $r['id'] ) ),
				esc_html( $r['title'] ? $r['title'] : '(no title)' ),
				(int) $r['id'],
				esc_html( $r['type'] ),
				esc_html( $r['status'] ),
				esc_html( implode( '  |  ', $r['form_changes'] ) )
			);
		}
		echo '</tbody></table>';
	}

	if ( $copy_only ) {
		printf(
			'<p style="color:#50575e">%d further page%s mention%s <code>%s</code> in visible page copy. '
			. 'That is content, not mail configuration, so nothing here touches it &mdash; change it in Elementor if you want to.</p>',
			count( $copy_only ),
			1 === count( $copy_only ) ? '' : 's',
			1 === count( $copy_only ) ? 's' : '',
			esc_html( AQM_MD_FROM_DOMAIN )
		);
	}

	echo '<h2 style="margin-top:2em">Log</h2>';

	if ( ! $log ) {
		echo '<p>Nothing recorded yet. Send a test above.</p></div>';
		return;
	}

	foreach ( $log as $row ) {

		$is_fail = ( 'failed' === $row['kind'] );
		printf(
			'<div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid %s;padding:12px 16px;margin:0 0 12px;max-width:1100px">',
			$is_fail ? '#b32d2e' : '#dba617'
		);
		printf(
			'<p style="margin:0 0 .5em"><strong>%s</strong> &middot; <span style="color:#50575e">%s</span>%s</p>',
			$is_fail ? 'Refused' : 'Recipient rewritten',
			esc_html( $row['when'] ),
			$row['subject'] ? ' &middot; ' . esc_html( $row['subject'] ) : ''
		);

		if ( $is_fail ) {
			$d = (array) $row['detail'];
			printf( '<p style="margin:.2em 0"><strong>To:</strong> %s</p>', esc_html( $d['to'] ) );
			printf(
				'<p style="margin:.2em 0"><strong>What the server said:</strong><br><code style="display:block;padding:.5em;background:#f6f7f7">%s</code></p>',
				esc_html( $d['server_reply'] )
			);
			printf(
				'<p style="margin:.2em 0;color:#50575e"><em>WordPress reported: %s</em></p>',
				esc_html( $d['wp_error'] )
			);
			if ( ! empty( $d['wire'] ) ) {
				echo '<details><summary style="cursor:pointer">SMTP conversation</summary>'
					. '<pre style="background:#f6f7f7;padding:.75em;overflow:auto;max-height:320px;font-size:12px">'
					. esc_html( implode( "\n", (array) $d['wire'] ) )
					. '</pre></details>';
			}
		} else {
			echo '<ul style="margin:.2em 0 0 1.2em;list-style:disc">';
			foreach ( (array) $row['detail'] as $line ) {
				echo '<li><code>' . esc_html( $line ) . '</code></li>';
			}
			echo '</ul>';
		}

		echo '</div>';
	}

	echo '</div>';
}
