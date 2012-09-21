<?php

abstract class WP_CLI_Command_With_Upgrade extends WP_CLI_Command {

	protected $item_type;
	protected $upgrader;
	protected $upgrade_refresh;
	protected $upgrade_transient;

	protected $default_subcommand = 'status';

	abstract protected function parse_name( $args, $subcommand );

	abstract protected function get_item_list();
	abstract protected function get_status( $file );
	abstract protected function get_details( $file );

	abstract protected function status_all();
	abstract protected function _status_single( $details, $name, $version, $status );

	abstract protected function install_from_repo( $slug, $assoc_args );

	/**
	 * Get the status of one or all items
	 *
	 * @param array $args
	 */
	function status( $args = array() ) {
		// Force WordPress to check for updates
		call_user_func( $this->upgrade_refresh );

		if ( empty( $args ) ) {
			$this->status_all();
		} else {
			list( $file, $name ) = $this->parse_name( $args, __FUNCTION__ );

			$this->status_single( $file, $name );
		}
	}

	protected function status_single( $file, $name ) {
		$details = $this->get_details( $file );

		$status = $this->format_status( $this->get_status( $file ), 'long' );

		$version = $details[ 'Version' ];

		if ( $this->has_update( $file ) )
			$version .= ' (%gUpdate available%n)';

		$this->_status_single( $details, $name, $version, $status );
	}

	/**
	 * Install a new plugin/theme
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	function install( $args, $assoc_args ) {
		if ( empty( $args ) ) {
			WP_CLI::line( "usage: wp $this->item_type install <slug>" );
			exit;
		}

		// Force WordPress to check for updates
		call_user_func( $this->upgrade_refresh );

		$slug = stripslashes( $args[0] );

		if ( '.zip' == substr( $slug, -4 ) ) {
			$file_upgrader = WP_CLI::get_upgrader( $this->upgrader );

			if ( $file_upgrader->install( $slug ) ) {
				$slug = $file_upgrader->result['destination_name'];

				if ( isset( $assoc_args['activate'] ) ) {
					WP_CLI::line( "Activating '$slug'..." );
					$this->activate( array( $slug ) );
				}
			} else {
				exit(1);
			}
		} else {
			$this->install_from_repo( $slug, $assoc_args );
		}
	}

	/**
	 * Update a plugin/theme
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	function update( $args, $assoc_args ) {
		call_user_func( $this->upgrade_refresh );

		if ( !empty( $args ) && !isset( $assoc_args['all'] ) ) {
			list( $file, $name ) = $this->parse_name( $args, __FUNCTION__ );

			WP_CLI::get_upgrader( $this->upgrader )->upgrade( $file );
		} else {
			$this->update_multiple( $args, $assoc_args );
		}
	}

	private function update_multiple( $args, $assoc_args ) {
		// Grab all items that need updates
		// If we have no sub-arguments, add them to the output list.
		$item_list = "Available {$this->item_type} updates:";
		$items_to_update = array();
		foreach ( $this->get_item_list() as $file ) {
			if ( $this->has_update( $file ) ) {
				$items_to_update[] = $file;

				if ( empty( $assoc_args ) ) {
					if ( false === strpos( $file, '/' ) )
						$name = str_replace('.php', '', basename($file));
					else
						$name = dirname($file);

					$item_list .= "\n\t%y$name%n";
				}
			}
		}

		if ( empty( $items_to_update ) ) {
			WP_CLI::line( "No {$this->item_type} updates available." );
			return;
		}

		// If --all, UPDATE ALL THE THINGS
		if ( isset( $assoc_args['all'] ) ) {
			$upgrader = WP_CLI::get_upgrader( $this->upgrader );
			$result = $upgrader->bulk_upgrade( $items_to_update );

			// Let the user know the results.
			$num_to_update = count( $items_to_update );
			$num_updated = count( array_filter( $result ) );

			$line = "Updated $num_updated/$num_to_update {$this->item_type}s.";
			if ( $num_to_update == $num_updated ) {
				WP_CLI::success( $line );
			} else if ( $num_updated > 0 ) {
				WP_CLI::warning( $line );
			} else {
				WP_CLI::error( $line );
			}

		// Else list items that require updates
		} else {
			WP_CLI::line( $item_list );
		}
	}

	/**
	 * Check whether an item has an update available or not.
	 *
	 * @param string $slug The plugin/theme slug
	 *
	 * @return bool
	 */
	protected function has_update( $slug ) {
		$update_list = get_site_transient( $this->upgrade_transient );

		return isset( $update_list->response[ $slug ] );
	}

	protected $map = array(
		'short' => array(
			'inactive' => 'I',
			'active' => 'A',
			'active-network' => 'N',
			'must-use' => 'M',
		),
		'long' => array(
			'inactive' => 'Inactive',
			'active' => 'Active',
			'active-network' => 'Must Use',
			'must-use' => 'Network Active',
		)
	);

	protected function format_status( $status, $format ) {
		return $this->get_color( $status ) . $this->map[ $format ][ $status ];
	}

	protected function show_legend() {
		$legend = array();

		// TODO: show only the values that were used
		foreach ( $this->map['short'] as $status => $short ) {
			$key = $this->format_status( $status, 'short' );
			$legend[ $key ] = $this->map['long'][ $status ];
		}

		$legend[ '%yU' ] = 'Update Available';

		$legend_line = array();
		foreach ( $legend as $key => $title )
			$legend_line[] = "$key = $title%n";

		WP_CLI::line( 'Legend: ' . implode( ', ', $legend_line ) );
	}

	protected function get_color( $status ) {
		static $colors = array(
			'inactive' => '',
			'active' => '%g',
			'active-network' => '%g',
			'must-use' => '%c',
		);

		return $colors[ $status ];
	}
}
