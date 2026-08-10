<?php

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\PushNotifications\Notifications;

use InvalidArgumentException;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for push notifications.
 *
 * Each notification type (e.g. new order, new review) extends this class
 * and implements `to_payload()` with its own title, message, icon, and meta.
 *
 * @since 10.7.0
 */
abstract class Notification {
	/**
	 * Map of notification type identifiers to their corresponding subclass.
	 *
	 * @var array<string, class-string<Notification>>
	 */
	const NOTIFICATION_CLASSES = array(
		'store_order'  => NewOrderNotification::class,
		'store_review' => NewReviewNotification::class,
		'store_stock'  => StockNotification::class,
	);

	/**
	 * Meta key recording when the notification was triggered.
	 *
	 * Written by {@see \Automattic\WooCommerce\Internal\PushNotifications\Services\PendingNotificationStore::add()}
	 * at the moment the store event fires, and read back by
	 * {@see self::get_triggered_timestamp()} when the payload is built — which
	 * can happen much later (ActionScheduler safety net, retries). Persisting
	 * it on the resource means every send path reports the true event time.
	 */
	const TRIGGERED_META_KEY = '_wc_push_notification_triggered';

	/**
	 * The ID of the resource this notification is about (e.g. order ID, comment
	 * ID).
	 *
	 * @var int
	 */
	private int $resource_id;

	/**
	 * Creates a new Notification instance.
	 *
	 * @param int $resource_id The resource ID.
	 *
	 * @throws InvalidArgumentException If the resource ID is invalid.
	 *
	 * @since 10.7.0
	 */
	public function __construct( int $resource_id ) {
		if ( $resource_id <= 0 ) {
			throw new InvalidArgumentException( 'Notification resource_id must be positive.' );
		}

		$this->resource_id = $resource_id;
	}

	/**
	 * Returns the notification type identifier, this should match the subtype
	 * or type (if there isn't a subtype) values attributed to notes in
	 * WordPress.com.
	 *
	 * @return string
	 *
	 * @since 10.7.0
	 */
	abstract public function get_type(): string;

	/**
	 * Returns the WPCOM-ready payload for this notification.
	 *
	 * Returns null if the underlying resource no longer exists.
	 *
	 * @return array|null
	 *
	 * @since 10.7.0
	 */
	abstract public function to_payload(): ?array;

	/**
	 * Checks whether a meta key exists for this notification's resource.
	 *
	 * @param string $key The meta key.
	 * @return bool
	 *
	 * @since 10.7.0
	 */
	abstract public function has_meta( string $key ): bool;

	/**
	 * Writes a meta key with a timestamp to this notification's resource.
	 *
	 * @param string $key The meta key.
	 * @return void
	 *
	 * @since 10.7.0
	 */
	abstract public function write_meta( string $key ): void;

	/**
	 * Reads a meta value from this notification's resource.
	 *
	 * Returns an empty string when the key is absent or the resource no longer
	 * exists.
	 *
	 * @param string $key The meta key.
	 * @return string
	 *
	 * @since 11.1.0
	 */
	abstract public function read_meta( string $key ): string;

	/**
	 * Deletes a meta key from this notification's resource.
	 *
	 * @param string $key The meta key.
	 * @return void
	 *
	 * @since 10.8.0
	 */
	abstract public function delete_meta( string $key ): void;

	/**
	 * Returns the notification data as an array.
	 *
	 * @return array{type: string, resource_id: int}
	 *
	 * @since 10.7.0
	 */
	public function to_array(): array {
		return array(
			'type'        => $this->get_type(),
			'resource_id' => $this->resource_id,
		);
	}

	/**
	 * Reconstructs a Notification subclass from a serialized array.
	 *
	 * @param array{type: string, resource_id: int} $data The notification data.
	 * @return self
	 *
	 * @throws InvalidArgumentException If the type is unknown.
	 *
	 * @since 10.7.0
	 */
	public static function from_array( array $data ): self {
		$type        = $data['type'] ?? '';
		$resource_id = (int) ( $data['resource_id'] ?? 0 );

		$class = self::NOTIFICATION_CLASSES[ $type ] ?? null;

		if ( ! $class ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new InvalidArgumentException( sprintf( 'Unknown notification type: %s', $type ) );
		}

		$instance = new $class( $resource_id );

		if ( method_exists( $instance, 'hydrate' ) ) {
			$instance->hydrate( $data );
		}

		return $instance;
	}

	/**
	 * Returns the ISO 8601 timestamp of the moment this notification was
	 * triggered, for the payload's `timestamp` field.
	 *
	 * Reads the trigger time recorded on the resource when the store event
	 * fired (see {@see self::TRIGGERED_META_KEY}). Previously the payload was
	 * stamped with the current time at send, so any delay between the event
	 * and the send (safety net, retries) was invisible to delivery-age
	 * monitoring. Falls back to the current time when no trigger time was
	 * recorded (e.g. notifications already in flight when this shipped).
	 *
	 * @return string
	 *
	 * @since 11.1.0
	 */
	public function get_triggered_timestamp(): string {
		$triggered_at = (int) $this->read_meta( self::TRIGGERED_META_KEY );

		return gmdate( 'c', $triggered_at > 0 ? $triggered_at : time() );
	}

	/**
	 * Returns a unique identifier for this notification, used for
	 * deduplication.
	 *
	 * @return string
	 *
	 * @since 10.7.0
	 */
	public function get_identifier(): string {
		return sprintf( '%s_%s_%s', get_current_blog_id(), $this->get_type(), $this->resource_id );
	}

	/**
	 * Gets the resource ID.
	 *
	 * @return int
	 *
	 * @since 10.7.0
	 */
	public function get_resource_id(): int {
		return $this->resource_id;
	}

	/**
	 * Canonical positional ActionScheduler arguments for the safety-net job.
	 *
	 * Single source of truth shared by the scheduler (and its dedupe guard) and
	 * the cancel path so the serialized args always match. Action Scheduler
	 * matches the stored args by exact equality, so any divergence between the
	 * schedule-side and cancel-side shapes silently breaks cancellation.
	 *
	 * The args are keyed on the notification's *identity* — the minimal data
	 * needed to uniquely identify and reconstruct the notification — mirroring
	 * {@see self::get_identifier()}. Volatile payload fields (e.g. a stock
	 * snapshot captured at trigger time) must not be included: they are not part
	 * of the identity and may differ between schedule and cancel.
	 *
	 * @return array<int, mixed>
	 *
	 * @since 10.9.0
	 */
	public function get_safety_net_args(): array {
		return array( $this->get_type(), $this->get_resource_id() );
	}

	/**
	 * Decide whether this notification should be delivered to a user given
	 * their stored preference value for {@see static::get_type()}.
	 *
	 * `$pref_value` is whatever the user has stored under this notification
	 * type's preference key, or `null` if they have nothing stored. The
	 * {@see NotificationPreferencesService} stores each preference as an
	 * object so future sub-fields (thresholds, sub-toggles) can be added
	 * without bumping the schema version — today's shape is
	 * `['enabled' => bool]`, future shapes might add e.g.
	 * `['enabled' => true, 'min_value' => 500]` for an order threshold.
	 *
	 * Default: read the universal `enabled` sub-field, defaulting to `true`
	 * when the value is missing or has no `enabled` key (so newly-added
	 * notification types are opt-in by default). Subclasses override to
	 * read richer sub-fields and to consult their own resource (e.g.
	 * compare an order total to the user's `min_value`).
	 *
	 * Subclasses must keep this side-effect-free — the {@see NotificationProcessor}
	 * may call it once per recipient user per notification.
	 *
	 * @param mixed $pref_value The user's stored preference value, or null.
	 * @return bool True if this notification should be sent to that user.
	 *
	 * @since 10.9.0
	 */
	public function should_send_to_user( $pref_value ): bool {
		if ( null === $pref_value ) {
			return true;
		}

		if ( is_array( $pref_value ) ) {
			return (bool) ( $pref_value['enabled'] ?? true );
		}

		// Defensive fallback for unexpected scalar values; the service
		// always normalises stored prefs to the array shape above.
		return (bool) $pref_value;
	}
}
