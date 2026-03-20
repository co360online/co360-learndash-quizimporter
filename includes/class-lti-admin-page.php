<?php
/**
 * Pantalla de administración para importar quizzes.
 */
class LTI_Admin_Page {
	/** @var LTI_Parser */
	private $parser;

	/** @var LTI_Validator */
	private $validator;

	/** @var LTI_LearnDash_Service */
	private $learndash;

	/** @var LTI_Import_Result|null */
	private $result = null;

	/**
	 * @param LTI_Parser            $parser Parser.
	 * @param LTI_Validator         $validator Validador.
	 * @param LTI_LearnDash_Service $learndash Servicio LD.
	 */
	public function __construct( LTI_Parser $parser, LTI_Validator $validator, LTI_LearnDash_Service $learndash ) {
		$this->parser    = $parser;
		$this->validator = $validator;
		$this->learndash = $learndash;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Registra submenú bajo LearnDash si existe.
	 *
	 * @return void
	 */
	public function register_menu() {
		$parent_slug = post_type_exists( 'sfwd-courses' ) ? 'learndash-lms' : 'tools.php';

		add_submenu_page(
			$parent_slug,
			'Importar Test LearnDash',
			'Importar Test (Texto)',
			'manage_options',
			'lti-quiz-importer',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Carga CSS/JS de la pantalla.
	 *
	 * @param string $hook_suffix Hook actual.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( $hook_suffix, 'lti-quiz-importer' ) ) {
			return;
		}

		wp_enqueue_style( 'lti-admin-css', LTI_PLUGIN_URL . 'assets/admin.css', array(), LTI_PLUGIN_VERSION );
		wp_enqueue_script( 'lti-admin-js', LTI_PLUGIN_URL . 'assets/admin.js', array(), LTI_PLUGIN_VERSION, true );
	}

	/**
	 * Muestra pantalla y procesa importación.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos suficientes para acceder a esta página.', 'ld-text-importer' ) );
		}

		$form_data = array(
			'quiz_title'       => '',
			'quiz_description' => '',
			'content'          => '',
		);

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['lti_action'] ) ) {
			$this->result = $this->handle_form_submit();
			$form_data    = $this->get_form_data_from_request();
		}

		?>
		<div class="wrap lti-wrap">
			<h1><?php echo esc_html__( 'Importar Quiz de LearnDash desde texto', 'ld-text-importer' ); ?></h1>
			<p><?php echo esc_html__( 'Pega bloques de preguntas separados por línea en blanco. Cada bloque debe seguir el formato definido.', 'ld-text-importer' ); ?></p>

			<?php $this->render_notices(); ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'lti_import_quiz_action', 'lti_import_quiz_nonce' ); ?>
				<input type="hidden" name="lti_action" value="import_quiz" />

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lti_quiz_title">Título del test</label></th>
						<td><input name="lti_quiz_title" id="lti_quiz_title" type="text" class="regular-text" required value="<?php echo esc_attr( $form_data['quiz_title'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="lti_quiz_description">Descripción del test (opcional)</label></th>
						<td><textarea name="lti_quiz_description" id="lti_quiz_description" class="large-text" rows="3"><?php echo esc_textarea( $form_data['quiz_description'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="lti_content">Contenido</label></th>
						<td><textarea name="lti_content" id="lti_content" class="large-text code" rows="18" required><?php echo esc_textarea( $form_data['content'] ); ?></textarea></td>
					</tr>
				</table>

				<?php submit_button( 'Validar e importar' ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * @return LTI_Import_Result
	 */
	private function handle_form_submit() {
		$result = new LTI_Import_Result();

		if ( ! isset( $_POST['lti_import_quiz_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lti_import_quiz_nonce'] ) ), 'lti_import_quiz_action' ) ) {
			$result->add_error( 'Error de seguridad (nonce inválido). Recarga la página e inténtalo de nuevo.' );
			return $result;
		}

		$data = $this->get_form_data_from_request();

		if ( '' === $data['quiz_title'] ) {
			$result->add_error( 'Debes indicar un título para el test.' );
			return $result;
		}

		if ( ! $this->learndash->is_available() ) {
			$result->add_error( 'LearnDash no parece estar activo o disponible en esta instalación.' );
			return $result;
		}

		$items             = $this->parser->parse( $data['content'] );
		$validation_result = $this->validator->validate( $items );

		if ( $validation_result->has_errors() ) {
			return $validation_result;
		}

		$import_result = $this->learndash->create_quiz_with_questions(
			$data['quiz_title'],
			$data['quiz_description'],
			$items
		);

		return $import_result;
	}

	/**
	 * @return array<string,string>
	 */
	private function get_form_data_from_request() {
		$quiz_title = isset( $_POST['lti_quiz_title'] ) ? sanitize_text_field( wp_unslash( $_POST['lti_quiz_title'] ) ) : '';
		$quiz_desc  = isset( $_POST['lti_quiz_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['lti_quiz_description'] ) ) : '';
		$content    = isset( $_POST['lti_content'] ) ? wp_unslash( $_POST['lti_content'] ) : '';

		return array(
			'quiz_title'       => $quiz_title,
			'quiz_description' => $quiz_desc,
			'content'          => $content,
		);
	}

	/**
	 * @return void
	 */
	private function render_notices() {
		if ( ! $this->result ) {
			return;
		}

		if ( $this->result->has_errors() ) {
			echo '<div class="notice notice-error"><p><strong>Se encontraron errores y no se creó ningún quiz:</strong></p><ul>';
			foreach ( $this->result->get_errors() as $error ) {
				echo '<li>' . esc_html( $error ) . '</li>';
			}
			echo '</ul></div>';
			return;
		}

		echo '<div class="notice notice-success"><p>';
		echo esc_html( implode( ' ', $this->result->get_messages() ) );

		$edit_link = $this->result->get_data( 'quiz_edit_link', '' );
		if ( $edit_link ) {
			echo ' <a href="' . esc_url( $edit_link ) . '">Ver quiz</a>';
		}

		echo '</p>';
		echo '<p><strong>Resumen:</strong> ' . esc_html( sprintf( 'Preguntas creadas: %d', (int) $this->result->get_data( 'questions_created', 0 ) ) ) . '</p>';
		echo '</div>';
	}
}
