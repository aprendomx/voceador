<?php
/**
 * Entidad canal.
 *
 * @package Voceador
 */

namespace Voceador\Channels;

/**
 * Un destino de publicación con sus credenciales ya descifradas.
 */
final class Channel {

	/**
	 * Id del canal.
	 *
	 * @var int
	 */
	public readonly int $id;

	/**
	 * Tipo de canal ("facebook_page").
	 *
	 * @var string
	 */
	public readonly string $type;

	/**
	 * Alias visible en el admin.
	 *
	 * @var string
	 */
	public readonly string $alias;

	/**
	 * Id del recurso en la red remota.
	 *
	 * @var string
	 */
	public readonly string $remote_id;

	/**
	 * Nombre del recurso en la red remota.
	 *
	 * @var string
	 */
	public readonly string $remote_name;

	/**
	 * URL del avatar, o null si no se conoce.
	 *
	 * @var string|null
	 */
	public readonly ?string $avatar_url;

	/**
	 * Método de conexión ("manual", "oauth", ...).
	 *
	 * @var string
	 */
	public readonly string $connection_method;

	/**
	 * Id del canal padre, o null si no aplica.
	 *
	 * @var int|null
	 */
	public readonly ?int $parent_channel_id;

	/**
	 * Credenciales ya descifradas.
	 *
	 * @var array
	 */
	public readonly array $credentials;

	/**
	 * Permisos concedidos.
	 *
	 * @var array
	 */
	public readonly array $scopes;

	/**
	 * Fecha de expiración del token (UTC, MySQL) o null.
	 *
	 * @var string|null
	 */
	public readonly ?string $token_expires_at;

	/**
	 * Estado del canal ("active", "paused", ...).
	 *
	 * @var string
	 */
	public readonly string $status;

	/**
	 * Último resultado de validar credenciales.
	 *
	 * @var array
	 */
	public readonly array $health;

	/**
	 * Fecha del último chequeo de salud, o null.
	 *
	 * @var string|null
	 */
	public readonly ?string $health_checked_at;

	/**
	 * Configuración propia del tipo de canal.
	 *
	 * @var array
	 */
	public readonly array $settings;

	/**
	 * Fecha de creación (UTC, MySQL).
	 *
	 * @var string
	 */
	public readonly string $created_at;

	/**
	 * Fecha de última actualización (UTC, MySQL).
	 *
	 * @var string
	 */
	public readonly string $updated_at;

	/**
	 * Constructor.
	 *
	 * @param array $data Campos con los nombres de la tabla; los que falten toman su default.
	 */
	public function __construct( array $data ) {
		$this->id                = (int) ( $data['id'] ?? 0 );
		$this->type              = (string) ( $data['type'] ?? '' );
		$this->alias             = (string) ( $data['alias'] ?? '' );
		$this->remote_id         = (string) ( $data['remote_id'] ?? '' );
		$this->remote_name       = (string) ( $data['remote_name'] ?? '' );
		$this->avatar_url        = isset( $data['avatar_url'] ) ? (string) $data['avatar_url'] : null;
		$this->connection_method = (string) ( $data['connection_method'] ?? 'manual' );
		$this->parent_channel_id = isset( $data['parent_channel_id'] ) ? (int) $data['parent_channel_id'] : null;
		$this->credentials       = is_array( $data['credentials'] ?? null ) ? $data['credentials'] : array();
		$this->scopes            = is_array( $data['scopes'] ?? null ) ? array_values( $data['scopes'] ) : array();
		$this->token_expires_at  = isset( $data['token_expires_at'] ) ? (string) $data['token_expires_at'] : null;
		$this->status            = (string) ( $data['status'] ?? 'active' );
		$this->health            = is_array( $data['health'] ?? null ) ? $data['health'] : array();
		$this->health_checked_at = isset( $data['health_checked_at'] ) ? (string) $data['health_checked_at'] : null;
		$this->settings          = is_array( $data['settings'] ?? null ) ? $data['settings'] : array();
		$this->created_at        = (string) ( $data['created_at'] ?? '' );
		$this->updated_at        = (string) ( $data['updated_at'] ?? '' );
	}

	/**
	 * Devuelve una credencial por clave.
	 *
	 * @param string $key Clave ("access_token").
	 * @return string|null
	 */
	public function credential( string $key ): ?string {
		return isset( $this->credentials[ $key ] ) ? (string) $this->credentials[ $key ] : null;
	}

	/**
	 * Indica si el canal recibe publicaciones.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return 'active' === $this->status;
	}

	/**
	 * Indica si el canal tiene un permiso concedido.
	 *
	 * @param string $scope Permiso.
	 * @return bool
	 */
	public function has_scope( string $scope ): bool {
		return in_array( $scope, $this->scopes, true );
	}
}
