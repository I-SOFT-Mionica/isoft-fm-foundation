<?php
/**
 * Time and memory budget for heavy work.
 *
 * Long or memory-hungry tasks (the integrity scan, PDF thumbnails, ZIP
 * bundles) check a budget as they go and stop cleanly before PHP's own
 * limits kill the request. A task that runs out stops on its own, says
 * why, and leaves the upload, page or cron run it was part of intact.
 *
 * Every stop is announced with `isoft_fmf_task_stopped( $task, $reason,
 * $detail )`, kept in a short list shown under Tools > Site Health > Info,
 * and queued once as an admin notice.
 */

defined( 'ABSPATH' ) || exit;

final class ISOFT_FMF_Task_Budget {

	/** Seconds a task may run when PHP sets no limit (CLI, many cron setups). */
	public const DEFAULT_SECONDS = 20;

	/** Share of memory_limit a task may grow the process to. */
	public const MEMORY_SHARE = 0.8;

	/** Share of max_execution_time, counted from the request start, a task may use. */
	private const REQUEST_SHARE = 0.6;

	private const STOPS_OPTION = 'isoft_fmf_task_stops';
	private const STOPS_KEPT   = 20;

	private string $task;
	private float $started;
	private float $deadline;
	private int $memory_cap;
	private string $reason = '';

	/**
	 * @param string     $task    Short task key: 'integrity', 'pdf_thumbnail', 'bundle', ...
	 * @param float|null $seconds Time budget; null derives it from the server's limits.
	 */
	public function __construct( string $task, ?float $seconds = null ) {
		$this->task    = $task;
		$this->started = microtime( true );

		/**
		 * Filters the budget of one heavy task.
		 *
		 * @param array{seconds: float, memory_share: float} $limits Budget.
		 * @param string                                     $task   Task key.
		 */
		$limits = (array) apply_filters(
			'isoft_fmf_task_budget',
			array(
				'seconds'      => $seconds ?? (float) self::DEFAULT_SECONDS,
				'memory_share' => self::MEMORY_SHARE,
			),
			$task
		);

		$this->deadline = $this->started + max( 0.0, (float) ( $limits['seconds'] ?? self::DEFAULT_SECONDS ) );

		// Never run past the point where PHP would cut the whole request off.
		$max_exec = (int) ini_get( 'max_execution_time' );
		if ( $max_exec > 0 ) {
			$request_start  = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : $this->started;
			$this->deadline = min( $this->deadline, $request_start + $max_exec * self::REQUEST_SHARE );
		}

		$share            = min( 1.0, max( 0.1, (float) ( $limits['memory_share'] ?? self::MEMORY_SHARE ) ) );
		$memory_limit     = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		$this->memory_cap = $memory_limit > 0 ? (int) ( $memory_limit * $share ) : 0;
	}

	/**
	 * True once the task should stop: out of time or close to the memory limit.
	 */
	public function exhausted(): bool {
		if ( '' !== $this->reason ) {
			return true;
		}
		if ( microtime( true ) >= $this->deadline ) {
			$this->reason = 'time';
		} elseif ( $this->memory_cap > 0 && memory_get_usage( true ) >= $this->memory_cap ) {
			$this->reason = 'memory';
		}
		return '' !== $this->reason;
	}

	/** Why the budget ran out: 'time', 'memory', or '' while it has not. */
	public function reason(): string {
		return $this->reason;
	}

	/** Seconds left before the deadline (0 when past it). */
	public function remaining(): float {
		return max( 0.0, $this->deadline - microtime( true ) );
	}

	/** Bytes the process may still grow by, or -1 when PHP sets no memory limit. */
	public function memory_headroom(): int {
		return $this->memory_cap > 0 ? max( 0, $this->memory_cap - memory_get_usage( true ) ) : -1;
	}

	public function task(): string {
		return $this->task;
	}

	/**
	 * Run a task and contain any error it throws, so one failure cannot take
	 * the surrounding request down. The error is reported as a stop.
	 *
	 * @template T
	 * @param string       $task     Task key.
	 * @param callable():T $work     The work.
	 * @param mixed        $fallback Returned when the work throws.
	 * @return T|mixed
	 */
	public static function guard( string $task, callable $work, $fallback = null ) {
		try {
			return $work();
		} catch ( \Throwable $e ) {
			self::report( $task, 'error', $e->getMessage() );
			return $fallback;
		}
	}

	/**
	 * Record that a task stopped before finishing.
	 *
	 * @param string $task   Task key.
	 * @param string $reason 'time' | 'memory' | 'error' | 'too_large' | a task-specific key.
	 * @param string $detail Plain text: what was being worked on, or the error.
	 */
	public static function report( string $task, string $reason, string $detail = '' ): void {
		$detail = wp_strip_all_tags( $detail );
		if ( strlen( $detail ) > 300 ) {
			$detail = substr( $detail, 0, 300 ) . '...';
		}

		do_action( 'isoft_fmf_task_stopped', $task, $reason, $detail );

		$stops = get_option( self::STOPS_OPTION, array() );
		$stops = is_array( $stops ) ? $stops : array();
		array_unshift(
			$stops,
			array(
				'task'   => $task,
				'reason' => $reason,
				'detail' => $detail,
				'time'   => time(),
			)
		);
		update_option( self::STOPS_OPTION, array_slice( $stops, 0, self::STOPS_KEPT ), false );

		// One notice per task and reason until an admin has seen it. Repeats
		// fold into it: the latest detail, and how many times it happened.
		$key     = $task . ':' . $reason;
		$queued  = isoft_fmf_queued_notice( $key );
		$times   = (int) ( $queued['data']['times'] ?? 0 ) + 1;
		$message = self::message( $task, $reason, $detail );
		if ( $times > 1 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: how many times it happened */
				_n( '(%d time since your last visit.)', '(%d times since your last visit.)', $times, 'isoft-fm-foundation' ),
				$times
			);
		}
		isoft_fmf_notify_admin( $message, 'error' === $reason ? 'error' : 'warning', $key, array( 'times' => $times ) );
	}

	/**
	 * Recent stops, newest first.
	 *
	 * @return array<int, array{task: string, reason: string, detail: string, time: int}>
	 */
	public static function recent_stops(): array {
		$stops = get_option( self::STOPS_OPTION, array() );
		return is_array( $stops ) ? $stops : array();
	}

	private static function message( string $task, string $reason, string $detail ): string {
		$message = sprintf(
			/* translators: %s: what happened, e.g. "PDF thumbnail stopped: it ran out of time" */
			__( 'I-Soft File Manager: Foundation: %s.', 'isoft-fm-foundation' ),
			self::describe( $task, $reason )
		);
		return '' !== $detail ? $message . ' ' . $detail : $message;
	}

	/**
	 * Readable "task + what happened", e.g. "PDF thumbnail was refused: too large".
	 */
	private static function describe( string $task, string $reason ): string {
		$labels  = array(
			'integrity'     => __( 'File integrity check', 'isoft-fm-foundation' ),
			'pdf_thumbnail' => __( 'PDF thumbnail', 'isoft-fm-foundation' ),
			'bundle'        => __( 'ZIP bundle', 'isoft-fm-foundation' ),
			'file_search'   => __( 'Search for a missing file', 'isoft-fm-foundation' ),
		);
		$reasons = array(
			'time'      => __( 'stopped: it ran out of time', 'isoft-fm-foundation' ),
			'memory'    => __( 'stopped: it was close to the memory limit', 'isoft-fm-foundation' ),
			'error'     => __( 'failed', 'isoft-fm-foundation' ),
			'too_large' => __( 'was refused: too large', 'isoft-fm-foundation' ),
			'disk'      => __( 'stopped: not enough free disk space', 'isoft-fm-foundation' ),
		);
		return sprintf(
			/* translators: 1: task name, e.g. "PDF thumbnail", 2: what happened, e.g. "stopped: it ran out of time" */
			__( '%1$s %2$s', 'isoft-fm-foundation' ),
			$labels[ $task ] ?? $task,
			$reasons[ $reason ] ?? $reason
		);
	}

	// -------------------------------------------------------------------------
	// Site Health > Info
	// -------------------------------------------------------------------------

	public static function register_hooks(): void {
		add_filter( 'debug_information', array( self::class, 'debug_information' ) );
	}

	/**
	 * @param array<string, mixed> $info Site Health info sections.
	 * @return array<string, mixed>
	 */
	public static function debug_information( array $info ): array {
		$fields = array();
		foreach ( self::recent_stops() as $i => $stop ) {
			$fields[ 'stop_' . $i ] = array(
				'label' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $stop['time'] ),
				'value' => trim( self::describe( (string) $stop['task'], (string) $stop['reason'] ) . '. ' . $stop['detail'] ),
				// "Copy site info" gets the raw keys, which support can search for.
				'debug' => trim( $stop['task'] . ' / ' . $stop['reason'] . ' ' . $stop['detail'] ),
			);
		}
		if ( ! $fields ) {
			$fields['none'] = array(
				'label' => __( 'Stopped tasks', 'isoft-fm-foundation' ),
				'value' => __( 'None recorded', 'isoft-fm-foundation' ),
			);
		}

		$info['isoft-fm-foundation-tasks'] = array(
			'label'       => __( 'I-Soft File Manager: Foundation: stopped tasks', 'isoft-fm-foundation' ),
			'description' => __( 'Heavy tasks (integrity check, PDF thumbnails, ZIP bundles) work within a time and memory budget. These are the latest that stopped early, failed or were refused.', 'isoft-fm-foundation' ),
			'fields'      => $fields,
		);
		return $info;
	}
}
