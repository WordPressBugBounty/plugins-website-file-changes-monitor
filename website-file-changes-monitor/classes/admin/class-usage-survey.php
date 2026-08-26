<?php
/**
 * Renders the one question plugin usage survey on the plugins own admin
 * screens and stores the local answered flag.
 *
 * @package MFM
 * @since 2.4.0
 */

declare(strict_types=1);

namespace MFM\Admin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin usage survey.
 *
 * @since 2.4.0
 */
class Usage_Survey {

	/**
	 * Option holding the campaign answered flag.
	 *
	 * @var string
	 *
	 * @since 2.4.0
	 */
	const ANSWERED_OPTION = MFM_PREFIX . 'usage_survey_2026_answered';

	/**
	 * Option indicating that the campaign is waiting for an answer.
	 *
	 * @var string
	 *
	 * @since 2.4.0
	 */
	const PENDING_OPTION = MFM_PREFIX . 'usage_survey_2026_pending';

	/**
	 * Nonce action used by the local AJAX request.
	 *
	 * @var string
	 *
	 * @since 2.4.0
	 */
	const NONCE_ACTION = MFM_PREFIX . 'usage_survey';

	/**
	 * Remote proxy that relays the answer.
	 *
	 * @var string
	 *
	 * @since 2.4.0
	 */
	const REMOTE_URL = 'https://proxytron.wpwhitesecurity.com';

	/**
	 * Follow up survey URL.
	 *
	 * @var string
	 *
	 * @since 2.4.0
	 */
	const FOLLOW_UP_URL = 'https://forms.melapress.com/mfm-plugin';

	/**
	 * Register the survey hooks.
	 *
	 * @return void
	 *
	 * @since 2.4.0
	 */
	public static function init() {
		\add_action( 'admin_init', array( __CLASS__, 'maybe_mark_pending_after_update' ), 5 );
		\add_action( 'update_site_option_' . MFM_PREFIX . 'initial_setup_needed', array( __CLASS__, 'maybe_mark_pending_after_setup' ), 10, 3 );
		\add_action( 'admin_footer', array( __CLASS__, 'maybe_render' ) );
		\add_action( 'wp_ajax_mfm_usage_survey_answered', array( __CLASS__, 'handle_answer' ) );
		\add_filter( MFM_PREFIX . 'admin_notices_count', array( __CLASS__, 'add_notification_count' ) );
	}

	/**
	 * Check if the survey was already answered on this site.
	 *
	 * @return bool $answered - True when the campaign flag is present.
	 *
	 * @since 2.4.0
	 */
	private static function is_answered(): bool {
		return (bool) \get_site_option( self::ANSWERED_OPTION, false );
	}

	/**
	 * Mark the campaign as waiting for an answer.
	 *
	 * An answered campaign is never reopened by a later plugin update.
	 *
	 * @return void
	 *
	 * @since 2.4.0
	 */
	private static function mark_pending() {
		if ( ! self::is_answered() ) {
			\update_site_option( self::PENDING_OPTION, true );
		}
	}

	/**
	 * Mark the campaign pending when an existing installation is updated.
	 *
	 * @return void
	 *
	 * @since 2.4.0
	 */
	public static function maybe_mark_pending_after_update() {
		$stored_version = \get_site_option( MFM_PREFIX . 'active_version', false );
		$has_last_scan  = (bool) \get_site_option( MFM_PREFIX . 'last_scan_time', false );

		if ( ( ! empty( $stored_version ) && version_compare( $stored_version, MFM_WP_VERSION, '<' ) ) || ( empty( $stored_version ) && $has_last_scan ) ) {
			self::mark_pending();
		}
	}

	/**
	 * Mark the campaign pending when the initial setup requirement is cleared.
	 *
	 * @param string $option_name - Updated option name.
	 * @param mixed  $new_value - New option value.
	 * @param mixed  $old_value - Previous option value.
	 *
	 * @return void
	 *
	 * @since 2.4.0
	 */
	public static function maybe_mark_pending_after_setup( string $option_name, $new_value, $old_value ) {
		if ( MFM_PREFIX . 'initial_setup_needed' === $option_name && (bool) $old_value && ! (bool) $new_value ) {
			self::mark_pending();
		}
	}

	/**
	 * Check if the campaign should be shown in the admin menu.
	 *
	 * @return bool $pending - True when the survey is pending and setup is complete.
	 *
	 * @since 2.4.0
	 */
	private static function has_pending_notification(): bool {
		return ! self::is_answered()
			&& (bool) \get_site_option( self::PENDING_OPTION, false )
			&& ! \get_site_option( MFM_PREFIX . 'initial_setup_needed', false );
	}

	/**
	 * Include the survey in the shared admin notice count.
	 *
	 * @param int $count - Current admin notice count.
	 *
	 * @return int $count - Filtered admin notice count.
	 *
	 * @since 2.4.0
	 */
	public static function add_notification_count( int $count ): int {
		if ( self::has_pending_notification() ) {
			return max( 1, $count );
		}

		return $count;
	}

	/**
	 * Get the survey answers, keyed by internal slug.
	 *
	 * @return array $answers - Slug to label map.
	 *
	 * @since 2.4.0
	 */
	private static function get_answers(): array {
		return array(
			'using-it-with-wsal'          => \__( 'With WP Activity Log, including testing or evaluating the integration', 'website-file-changes-monitor' ),
			'using-it-as-standalone-tool' => \__( 'As a standalone file change monitoring tool, including testing or evaluating it on its own', 'website-file-changes-monitor' ),
			'not-actively-using-it'       => \__( "I installed it, but I'm not actively using it", 'website-file-changes-monitor' ),
			'other'                       => \__( 'Other', 'website-file-changes-monitor' ),
		);
	}

	/**
	 * Enqueue the survey assets and pass the request data to the script.
	 *
	 * @param bool $auto_open - Whether to open the survey when the page loads.
	 *
	 * @return void
	 *
	 * @since 2.4.0
	 */
	private static function enqueue_assets( bool $auto_open ) {
		\wp_enqueue_style(
			'mfm-usage-survey-css',
			MFM_WP_URL . 'assets/css/mfm-usage-survey.css',
			array(),
			MFM_WP_VERSION
		);

		\wp_enqueue_script(
			'mfm-usage-survey-js',
			MFM_WP_URL . 'assets/js/mfm-usage-survey.js',
			array(),
			MFM_WP_VERSION,
			true
		);

		\wp_localize_script(
			'mfm-usage-survey-js',
			'mfmUsageSurvey',
			array(
				'ajaxUrl'               => \admin_url( 'admin-ajax.php' ),
				'autoOpen'              => $auto_open,
				'clearMenuNotification' => ! \get_site_option( MFM_PREFIX . 'update_notice_needed', false ),
				'nonce'                 => \wp_create_nonce( self::NONCE_ACTION ),
				'remoteUrl'             => self::REMOTE_URL,
				'version'               => MFM_WP_VERSION,
				'site'                  => \get_site_url(),
			)
		);
	}

	/**
	 * Render the survey modal and the follow up modal.
	 *
	 * @return void
	 *
	 * @since 2.4.0
	 */
	private static function render_modals() {
		$logo_url = MFM_WP_URL . 'assets/img/mfm-logo.png';
		?>
		<div id="mfm-usage-survey" class="mfm-usage-survey" popover="manual" tabindex="-1" role="dialog" aria-labelledby="mfm-usage-survey-title">
			<button type="button" class="mfm-usage-survey-close" aria-label="<?php \esc_attr_e( 'Close', 'website-file-changes-monitor' ); ?>">&times;</button>
			<img class="mfm-usage-survey-logo" src="<?php echo \esc_url( $logo_url ); ?>" alt="<?php \esc_attr_e( 'Melapress File Monitor', 'website-file-changes-monitor' ); ?>">
			<h2 id="mfm-usage-survey-title"><?php \esc_html_e( 'How are you using Melapress File Monitor?', 'website-file-changes-monitor' ); ?></h2>
			<p><?php \esc_html_e( "If you have a moment, please let us know how you're currently using Melapress File Monitor:", 'website-file-changes-monitor' ); ?></p>
			<form class="mfm-usage-survey-form">
				<div class="mfm-usage-survey-answers">
					<?php foreach ( self::get_answers() as $answer_slug => $answer_label ) : ?>
						<label>
							<input type="radio" name="mfm-usage-survey-answer" value="<?php echo \esc_attr( $answer_slug ); ?>">
							<span><?php echo \esc_html( $answer_label ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
				<p class="mfm-usage-survey-validation" hidden role="alert" aria-atomic="true">
					<?php \esc_html_e( 'Please select one of the answers above and click Submit.', 'website-file-changes-monitor' ); ?>
				</p>
				<div class="mfm-usage-survey-actions">
					<button type="submit" class="mfm-usage-survey-submit"><?php \esc_html_e( 'Submit', 'website-file-changes-monitor' ); ?></button>
				</div>
			</form>
		</div>

		<div id="mfm-usage-survey-follow-up" class="mfm-usage-survey" popover="manual" tabindex="-1" role="dialog" aria-labelledby="mfm-usage-survey-follow-up-title">
			<button type="button" class="mfm-usage-survey-close" aria-label="<?php \esc_attr_e( 'Close', 'website-file-changes-monitor' ); ?>">&times;</button>
			<img class="mfm-usage-survey-logo" src="<?php echo \esc_url( $logo_url ); ?>" alt="<?php \esc_attr_e( 'Melapress File Monitor', 'website-file-changes-monitor' ); ?>">
			<h2 id="mfm-usage-survey-follow-up-title"><?php \esc_html_e( 'Can we ask you a few more questions?', 'website-file-changes-monitor' ); ?></h2>
			<p><?php \esc_html_e( "We're trying to better understand how people use Melapress File Monitor and what they need from it.", 'website-file-changes-monitor' ); ?></p>
			<p><?php \esc_html_e( "If you have another minute or two, we'd really appreciate it if you could answer 7 quick multiple choice questions.", 'website-file-changes-monitor' ); ?></p>
			<div class="mfm-usage-survey-actions">
				<a class="mfm-usage-survey-submit" href="<?php echo \esc_url( self::FOLLOW_UP_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php \esc_html_e( 'Share my feedback', 'website-file-changes-monitor' ); ?></a>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the survey when the current screen and user qualify.
	 *
	 * @return void
	 *
	 * @since 2.4.0
	 */
	public static function maybe_render() {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		// If we're not in a mfm admin screen, or if it's already answered, return early and stop.
		if ( ! Admin_Manager::is_mfm_admin_screen() || self::is_answered() ) {
			return;
		}

		$setup_pending  = (bool) \get_site_option( MFM_PREFIX . 'initial_setup_needed', false );
		$survey_pending = self::has_pending_notification();

		// During initial setup, load the hidden survey so the wizard can open it on completion.
		if ( ! $setup_pending && ! $survey_pending ) {
			return;
		}

		self::enqueue_assets( $survey_pending );
		self::render_modals();
	}

	/**
	 * Store the campaign answered flag.
	 *
	 * @return void
	 *
	 * @since 2.4.0
	 */
	public static function handle_answer() {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_send_json_error( array( 'message' => \__( 'Insufficient permissions', 'website-file-changes-monitor' ) ) );
		}

		$nonce = \sanitize_key( \wp_unslash( $_POST['nonce'] ?? '' ) );

		if ( empty( $nonce ) || ! \wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			\wp_send_json_error( array( 'message' => \__( 'Failed nonce check', 'website-file-changes-monitor' ) ) );
		}

		\update_site_option( self::ANSWERED_OPTION, true );
		\delete_site_option( self::PENDING_OPTION );

		\wp_send_json_success();
	}
}
