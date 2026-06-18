<?php
/**
 * LGApps_Widget
 *
 * Registers a WordPress sidebar widget for each app in the registry.
 * Uses a static config map + generated temp PHP files to avoid eval().
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LGApps_Widget extends WP_Widget {

    protected $app_slug   = '';
    protected $app_config = [];

    /** @var array Static map of slug => config for subclass constructor lookup */
    private static $config_map = [];

    public function __construct( $slug = '', $config = [] ) {
        $this->app_slug   = $slug;
        $this->app_config = $config;

        $widget_id = 'lgapps_' . str_replace( '-', '_', $slug );
        $title     = ! empty( $config['title'] ) ? $config['title'] : ucwords( str_replace( '-', ' ', $slug ) );

        parent::__construct(
            $widget_id,
            'LG App: ' . $title,
            [ 'description' => ! empty( $config['description'] ) ? $config['description'] : 'Opens the ' . $title . ' app.' ]
        );
    }

    public function widget( $args, $instance ) {
        LGApps_Registry::enqueue( $this->app_slug );

        $title    = ! empty( $instance['title'] ) ? $instance['title'] : '';
        $btn_text = ! empty( $instance['button_text'] ) ? $instance['button_text'] : $this->app_config['title'];

        echo $args['before_widget'];
        if ( $title ) {
            echo $args['before_title'] . esc_html( $title ) . $args['after_title'];
        }
        echo '<button class="lgapps-open-btn" onclick="window.lgapps_open(\'' . esc_attr( $this->app_slug ) . '\')">'
           . esc_html( $btn_text )
           . '</button>';
        echo $args['after_widget'];
    }

    public function form( $instance ) {
        $title = isset( $instance['title'] ) ? $instance['title'] : '';
        $btn   = isset( $instance['button_text'] ) ? $instance['button_text'] : ( ! empty( $this->app_config['title'] ) ? $this->app_config['title'] : '' );
        ?>
        <p>
            <label for="<?php echo $this->get_field_id( 'title' ); ?>">Widget Title:</label>
            <input class="widefat"
                   id="<?php echo $this->get_field_id( 'title' ); ?>"
                   name="<?php echo $this->get_field_name( 'title' ); ?>"
                   type="text"
                   value="<?php echo esc_attr( $title ); ?>">
        </p>
        <p>
            <label for="<?php echo $this->get_field_id( 'button_text' ); ?>">Button Text:</label>
            <input class="widefat"
                   id="<?php echo $this->get_field_id( 'button_text' ); ?>"
                   name="<?php echo $this->get_field_name( 'button_text' ); ?>"
                   type="text"
                   value="<?php echo esc_attr( $btn ); ?>">
        </p>
        <?php
    }

    public function update( $new_instance, $old_instance ) {
        return [
            'title'       => sanitize_text_field( $new_instance['title'] ),
            'button_text' => sanitize_text_field( $new_instance['button_text'] ),
        ];
    }

    /* --- Static config map for subclass constructors --- */

    public static function store_config( $slug, $config ) {
        self::$config_map[ $slug ] = $config;
    }

    public static function get_stored_config( $slug ) {
        return isset( self::$config_map[ $slug ] ) ? self::$config_map[ $slug ] : [];
    }

    /**
     * Register a widget for a given app slug.
     *
     * Generates a tiny PHP file in wp-content/uploads/lgapps-tmp/ containing
     * a named subclass. No eval(), no $GLOBALS, no anonymous classes.
     * The slug is sanitize_key()'d so only [a-z0-9_-] can appear in the
     * class name and file path.
     */
    public static function register_for_app( $slug, $config ) {
        $slug        = sanitize_key( $slug );
        $safe_suffix = str_replace( '-', '_', $slug );
        $class_name  = 'LGApps_Widget_' . $safe_suffix;

        self::store_config( $slug, $config );

        if ( ! class_exists( $class_name ) ) {
            $tmp_dir = wp_upload_dir()['basedir'] . '/lgapps-tmp';
            if ( ! is_dir( $tmp_dir ) ) {
                wp_mkdir_p( $tmp_dir );
                // Block direct web access
                @file_put_contents( $tmp_dir . '/.htaccess', 'Deny from all' );
                @file_put_contents( $tmp_dir . '/index.php', '<?php // Silence is golden.' );
            }

            $tmp_file       = $tmp_dir . '/widget-' . $safe_suffix . '.php';
            $version_marker = '/* lgapps-v' . LGAPPS_VERSION . ' */';

            // Only rewrite when plugin version changes or file is missing
            if ( ! file_exists( $tmp_file ) || strpos( (string) file_get_contents( $tmp_file ), $version_marker ) === false ) {
                $escaped_slug = addslashes( $slug );
                $code = "<?php {$version_marker}\n"
                      . "class {$class_name} extends LGApps_Widget {\n"
                      . "    public function __construct() {\n"
                      . "        \$config = LGApps_Widget::get_stored_config( '{$escaped_slug}' );\n"
                      . "        parent::__construct( '{$escaped_slug}', \$config );\n"
                      . "    }\n"
                      . "}\n";
                file_put_contents( $tmp_file, $code );
            }

            require_once $tmp_file;
        }

        register_widget( $class_name );
    }
}
