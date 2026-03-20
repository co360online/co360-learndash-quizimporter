<?php
/**
 * Resultado de la importación/validación.
 */
class LTI_Import_Result {
	/**
	 * @var bool
	 */
	private $success = false;

	/**
	 * @var string[]
	 */
	private $errors = array();

	/**
	 * @var string[]
	 */
	private $messages = array();

	/**
	 * @var array<string,mixed>
	 */
	private $data = array();

	/**
	 * @param bool $success
	 */
	public function set_success( $success ) {
		$this->success = (bool) $success;
	}

	/**
	 * @return bool
	 */
	public function is_success() {
		return $this->success;
	}

	/**
	 * @param string $error
	 */
	public function add_error( $error ) {
		$this->errors[] = (string) $error;
	}

	/**
	 * @return string[]
	 */
	public function get_errors() {
		return $this->errors;
	}

	/**
	 * @return bool
	 */
	public function has_errors() {
		return ! empty( $this->errors );
	}

	/**
	 * @param string $message
	 */
	public function add_message( $message ) {
		$this->messages[] = (string) $message;
	}

	/**
	 * @return string[]
	 */
	public function get_messages() {
		return $this->messages;
	}

	/**
	 * @param string $key
	 * @param mixed  $value
	 */
	public function set_data( $key, $value ) {
		$this->data[ $key ] = $value;
	}

	/**
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	public function get_data( $key, $default = null ) {
		return isset( $this->data[ $key ] ) ? $this->data[ $key ] : $default;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get_all_data() {
		return $this->data;
	}
}
