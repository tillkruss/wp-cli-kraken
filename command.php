<?php
/**
 * WP-CLI command to ptimize/compress WordPress image attachments
 * using the Kraken Image Optimizer API.
 *
 * @author Till Krüss
 * @license MIT License
 * @link https://github.com/tillkruss/wp-cli-kraken
 */
class WP_CLI_Kraken extends WP_CLI_Command {

	/**
	 * @var string Cache uploads directory path.
	 */
	private $upload_dir;

	/**
	 * @var bool Dry-run state.
	 */
	private $dryrun = false;

	/**
	 * @var int Maximum number of images to krake.
	 */
	private $limit = -1;

	/**
	 * @var array Runtime statistics.
	 */
	private $statistics = array();

	/**
	 * @var array Attachment Kraken metadata cache.
	 */
	private $kraken_metadata = array();

	/**
	 * @var array Default config values.
	 */
	private $config = array(
		'lossy' => false,
		'compare' => 'md4',
		'types' => 'gif, jpeg, png, svg'
	);

	/**
	 * @var array Image comparison methods.
	 */
	private $comparators = array(
		'none',
		'md4',
		'timestamp'
	);

	/**
	 * @var array Accepted image MIME types.
	 */
	private $mime_types = array(
		'gif'  => 'image/gif',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'svg'  => 'image/svg+xml'
	);

	/**
	 * Constructor. Checks if the cURL extention is available.
	 * Sets values for `$this->upload_dir` and `$this->statistics`.
	 *
	 * @return void
	 */
	public function __construct() {

		// abort if cURL extention is not available
		if ( ! extension_loaded( 'curl' ) || ! function_exists( 'curl_init' ) ) {
			WP_CLI::error( 'Please install/enable the cURL PHP extension.' );
		}

		// cache uploads directory path
		$this->upload_dir = wp_upload_dir()[ 'basedir' ];

		// setup `$this->statistics` values
		foreach ( array(
			'attachments', 'files', 'compared', 'changed', 'unknown', 'uploaded',
			'kraked', 'failed', 'samesize', 'size', 'saved' ) as $key
		) { $this->statistics[ $key ] = 0; }

	}

	/**
	 * Krake image(s).
	 *
	 * ## DESCRIPTION
	 *
	 * Optimize/compress WordPress image attachments using the Kraken Image Optimizer API.
	 *
	 * ## OPTIONS
	 *
	 * [<attachment-id>...]
	 * : One or more IDs of the attachments to krake.
	 *
	 * [--lossy]
	 * : Use lossy image compression.
	 *
	 * [--limit=<number>]
	 * : Maximum number of images to krake. Default: -1
	 *
	 * [--types=<types>]
	 * : Image format(s) to krake. Default: 'gif,jpeg,png,svg'
	 *
	 * [--compare=<method>]
	 * : Image metadata comparison method. Values: none, md4, timestamp. Default: md4
	 *
	 * [--all]
	 * : Bypass metadata comparison.
	 *
	 * [--dry-run]
	 * : Do a dry run and show report without executing API calls.
	 *
	 * [--api-key=<key>]
	 * : Kraken API key to use.
	 *
	 * [--api-secret=<secret>]
	 * : Kraken API secret to use.
	 *
	 * [--api-test]
	 * : Validate Kraken API credentials and show account summary.
	 *
	 * ## EXAMPLES
	 *
	 *     # Krake all images that have not been kraked
	 *     wp media krake
	 *
	 *     # Krake all image sizes of attachment with id 1337
	 *     wp media krake 1337
	 *
	 *     # Krake images using lossy compression
	 *     wp media krake --lossy
	 *
	 *     # Krake a maximum of 42 images
	 *     wp media krake --limit=42
	 *
	 *     # Krake only PNG and JPEG images
	 *     wp media krake --types='png, jpeg'
	 *
	 *     # Use file modification time for metadata comparison
	 *     wp media krake --compare=timestamp
	 *
	 *     # Krake all images, bypass metadata comparison
	 *     wp media krake --all
	 *
	 *     # Do a dry run and show report without executing API calls
	 *     wp media krake --dry-run
	 *
	 *     # Validate Kraken API credentials and show account summary
	 *     wp media krake --api-test
	 *
	 */
	public function __invoke( array $args, array $assoc_args ) {

		$this->_init_config( $assoc_args );

		$images = new WP_Query( array(
			'post_type' => 'attachment',
			'post__in' => $args,
			'post_mime_type' => implode( ', ', $this->_parse_mime_types( $this->config[ 'types' ], true ) ),
			'post_status' => 'any',
			'posts_per_page' => -1,
			'fields' => 'ids'
		) );

		// abort if no matching attachments are found
		if ( ! $images->post_count ) {
			WP_CLI::warning( 'No matching attachments found.' );
			return;
		}

		WP_CLI::line( sprintf(
			'Found %d %s to check. %s',
			$images->post_count,
			_n( 'attachment', 'attachments', $images->post_count ),
			$this->limit === -1
				? 'Kraking all found images.'
				: sprintf( 'Kraking first %s %s found.', $this->limit, _n( 'image', 'images', $this->limit ) )
		) );

		$skipping = 'Skipping already kraked images (comparing %s).';
		switch ( $this->config[ 'compare' ] ) {
			case 'md4': WP_CLI::line( sprintf( $skipping, 'file MD4 hashes' ) ); break;
			case 'timestamp': WP_CLI::line( sprintf( $skipping, 'file modification times' ) ); break;
		}

		WP_CLI::line( sprintf(
			'Using %s compression for %s files.',
			$this->config[ 'lossy' ] ? 'lossy' : 'lossless',
			strtoupper( $this->config[ 'types' ] )
		) );

		// check all image sizes for each attachment
		foreach ( $images->posts as $id ) {
			$this->_check_image_sizes( $id );
		}

		$this->_show_report();

	}

	/**
	 * Sets class/config values from command-line flags and YAML config file values.
	 *
	 * @param array $args Passed command-line flags.
	 * @return void
	 */
	private function _init_config( array $args ) {

		// fetch `kraken` config values from YAML config file
		$extra_config = WP_CLI::get_runner()->extra_config;
		$config = isset( $extra_config[ 'kraken' ] ) ? $extra_config[ 'kraken' ] : array();

		// set dry-run state?
		if ( isset( $args[ 'dry-run' ] ) ) {
			$this->dryrun = true;
		}

		// setup Kraken API credentials
		// uses command-line flags if provided, otherwise YAML config file values
		if ( isset( $args[ 'api-key' ], $args[ 'api-secret' ] ) ) {
			$api_key = trim( $args[ 'api-key' ] );
			$api_secret = trim( $args[ 'api-secret' ] );
		} elseif ( isset( $config[ 'api-key' ], $config[ 'api-secret' ] ) ) {
			$api_key = trim( $config[ 'api-key' ] );
			$api_secret = trim( $config[ 'api-secret' ] );
		}

		// validate Kraken API credentials format
		if ( isset( $api_key, $api_secret ) ) {

			if ( preg_match( '~^[a-f0-9]{32}$~i', $api_key ) && preg_match( '~^[a-f0-9]{40}$~i', $api_secret ) ) {

				$this->config[ 'api-key' ] = $api_key;
				$this->config[ 'api-secret' ] = $api_secret;

				// validate API credentials
				if ( ! $this->dryrun ) {

					$ch = curl_init();
					curl_setopt( $ch, CURLOPT_URL, 'https://api.kraken.io/user_status' );
					curl_setopt( $ch, CURLOPT_POST, true );
					curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
					curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( array(
						'auth' => array(
							'api_key' => $api_key,
							'api_secret' => $api_secret
						)
					) ) );

					$response = json_decode( curl_exec( $ch ), true );

					if ( curl_errno( $ch ) ) {

						// show warning if a cURL occurred
						WP_CLI::warning( sprintf( 'Kraken API credentials validation failed. (cURL error [%s]: %s)', curl_errno( $ch ), curl_error( $ch ) ) );

					} else {

						if ( $response[ 'success' ] ) {

							// always show account quota details
							WP_CLI::line( sprintf(
								'Monthly Quota: %s, Current Usage: %s, Remaining: %s',
								size_format( $response[ 'quota_total' ] ),
								size_format( $response[ 'quota_used' ], 2 ),
								size_format( $response[ 'quota_remaining' ], 2 )
							) );

							// abort if we're only doing an API test
							if ( isset( $args[ 'api-test' ] ) ) {
								WP_CLI::success( 'Kraken API test successful.' );
								exit;
							}

						} else {

							WP_CLI::warning( sprintf( 'Kraken API credentials validation failed. (Error: %s)', $response[ 'error' ] ) );

						}

					}

					curl_close( $ch );

				}

			} else {
				WP_CLI::error( 'Please specify valid Kraken API credentials.' );
			}

		} else {
			WP_CLI::error( 'Please specify your Kraken API credentials.' );
		}

		// parse `--limit=<number>` flag
		if ( isset( $args[ 'limit' ] ) ) {
			$limit = trim( $args[ 'limit' ] );
			if ( is_string( $args[ 'limit' ] ) && ( $limit === '-1' || $limit > 0 ) ) {
				$this->limit = intval( $limit );
			} else {
				WP_CLI::error( 'Invalid `limit` flag value.' );
			}
		}

		// setup compression type
		// use `--lossy` flag if provided, otherwise YAML config file value
		if ( isset( $args[ 'lossy' ] ) ) {
			$lossy = $args[ 'lossy' ];
		} elseif ( isset( $config[ 'lossy' ] ) ) {
			$lossy = $config[ 'lossy' ];
		}

		// validate and set `lossy` config value, or fallback to lossless compression
		if ( isset( $lossy ) && is_bool( $lossy ) ) {
			$this->config[ 'lossy' ] = $lossy;
		} elseif ( isset( $lossy ) ) {
			WP_CLI::warning( 'Unknown `lossy` value. Using lossless compression.' );
		}

		// setup comparison method
		if ( isset( $args[ 'all' ] ) ) {

			// krake all images
			$this->config[ 'compare' ] = 'none';

		} else {

			// use `--compare` flag if provided, otherwise YAML config file value
			if ( isset( $args[ 'compare' ] ) ) {
				$compare = $args[ 'compare' ];
			} elseif ( isset( $config[ 'compare' ] ) ) {
				$compare = $config[ 'compare' ];
			}

			// validate and set `compare` config value, or abort if value is invalid
			if ( isset( $compare ) && in_array( trim( $compare ), $this->comparators ) ) {
				$this->config[ 'compare' ] = trim( $compare );
			} elseif ( isset( $compare ) ) {
				WP_CLI::error( sprintf( 'Unknown `compare` value. Valid values: %s', implode( ', ', $this->comparators ) ) );
			}

		}

		// parse `--types` flag if provided, otherwise YAML config file value
		if ( isset( $args[ 'types' ] ) ) {
			$types = $this->_parse_mime_types( $args[ 'types' ] );
		} elseif ( isset( $config[ 'types' ] ) ) {
			$types = $this->_parse_mime_types( $config[ 'types' ] );
		}

		// validate and set `types` config value, or abort if value is invalid
		if ( isset( $types ) && is_array( $types ) ) {
			$this->config[ 'types' ] = implode( ', ', $types );
		} elseif ( isset( $types ) ) {
			WP_CLI::error( sprintf( 'Unknown `types` value. Valid values: %s', implode( ', ', array_keys( $this->mime_types ) ) ) );
		}

	}

	/**
	 * Finds all image sizes (thumbnail, medium, etc.) of given attachment.
	 * Calls `_kraken_image()` directly for each image that has no Kraken metadata.
	 * Calls `_maybe_kraken_image()` images that have Kraken metadata for comparison.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	private function _check_image_sizes( $attachment_id ) {

		$files = array();
		$filepath = get_attached_file( $attachment_id );
		$dirpath = dirname( $filepath );

		$image_metadata = wp_get_attachment_metadata( $attachment_id );
		$image_krakendata = $this->_get_image_kraken_metadata( $attachment_id );

		// add original image
		$files[] = $filepath;

		// add all additional image sizes (thumbnail, medium, etc.)
		if ( isset( $image_metadata[ 'sizes' ] ) ) {
			foreach ( $image_metadata[ 'sizes' ] as $size ) {
				$files[] = $dirpath . '/' . $size[ 'file' ];
			}
		}

		foreach ( $files as $path ) {

			// krake all images that have no kraken metadata
			// pass already kraked images through `_maybe_kraken_image()`
			if ( ! isset( $image_krakendata[ basename( $path ) ] ) ) {
				$this->_kraken_image( $attachment_id, $path );
				$this->statistics[ 'unknown' ]++;
			} else {
				$this->_maybe_kraken_image( $attachment_id, $path );
			}

			$this->statistics[ 'files' ]++;

		}

		$this->statistics[ 'attachments' ]++;

	}

	/**
	 * Compares the current image file with the the stored details.
	 * Calls `_kraken_image()` if the hash/timestamp doesn't match,
	 * or if the comparator is set to `none`.
	 *
	 * @param int $attachment_id ID of the associated attachment.
	 * @param string $filepath Absolute path to original image.
	 * @return void
	 */
	private function _maybe_kraken_image( $attachment_id, $filepath ) {

		$krakendata = $this->_get_image_kraken_metadata( $attachment_id )[ basename( $filepath ) ];

		switch ( $this->config[ 'compare' ] ) {

			case 'none': // just krake all, no comparison
				$krake = true;
				break;

			default:
			case 'md4': // compare MD4 hashes
				$hash = hash_file( 'md4', $filepath );
				$krake = ( strcmp( $hash, $krakendata[ 'md4' ] ) !== 0 );
				break;

			case 'timestamp': // compare file modification time
				$timestamp = gmdate( 'U', filemtime( $filepath ) );
				$krake = ( strcmp( $timestamp, $krakendata[ 'mtime' ] ) !== 0 );
				break;

		}

		if ( $this->config[ 'compare' ] !== 'none' ) {
			$this->statistics[ 'compared' ]++;
		}

		// krake image?
		if ( isset( $krake ) && $krake ) {
			$this->_kraken_image( $attachment_id, $filepath );
			$this->statistics[ 'changed' ]++;
		}

	}

	/**
	 * Uploads given file to Kraken and calls `_replace_image()`
	 * if the API call was successful and the image filesize was reduced.
	 *
	 * @param int $attachment_id ID of the associated attachment.
	 * @param string $filepath Absolute path to original image.
	 * @return void
	 */
	private function _kraken_image( $attachment_id, $filepath ) {

		// skip API call if it's a dry-run
		if ( $this->dryrun ) {
			$this->statistics[ 'kraked' ]++;
			return;
		}

		WP_CLI::line( sprintf(
			'Kraking %s (%s)',
			str_replace( $this->upload_dir, '', $filepath ),
			size_format( filesize( $filepath ), 2 )
		) );

		// upload image to Kraken and wait for response
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, 'https://api.kraken.io/v1/upload' );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, array(
			'file' => class_exists( 'CURLFile' ) ? new CURLFile( $filepath ) : '@' . $filepath,
			'data' => json_encode( array(
				'auth' => array(
					'api_key' => $this->config[ 'api-key' ],
					'api_secret' => $this->config[ 'api-secret' ]
				),
				'wait' => true,
				'lossy' => $this->config[ 'lossy' ]
			) )
		) );

		$response = json_decode( curl_exec( $ch ), true );

		// show warning if a cURL occurred
		if ( curl_errno( $ch ) ) {
			WP_CLI::warning( sprintf( 'Kraked image upload failed. (cURL error [%s]: %s)', curl_errno( $ch ), curl_error( $ch ) ) );
			$this->statistics[ 'failed' ]++;
			curl_close( $ch );
			return;
		}

		curl_close( $ch );
		$this->statistics[ 'uploaded' ]++;

		// was the API call successful?
		if ( $response[ 'success' ] ) {

			// was the image filesize reduced?
			if ( $response[ 'saved_bytes' ] > 0 ) {

				// was the orginal image backed up and replaced by kraked image?
				if ( $this->_replace_image( $attachment_id, $filepath, $response[ 'kraked_url' ], $response[ 'kraked_size' ] ) ) {

					WP_CLI::line( sprintf(
						'Kraked %s (Kraked size: %s, Savings: %s / %s%%)',
						str_replace( $this->upload_dir, '', $filepath ),
						size_format( $response[ 'kraked_size' ], 2 ),
						size_format( $response[ 'saved_bytes' ] ),
						round( abs( ( ( $response[ 'kraked_size' ] - $response[ 'original_size' ] ) / $response[ 'original_size' ] ) * 100 ), 2 )
					) );

					$this->statistics[ 'kraked' ]++;
					$this->statistics[ 'size' ] += $response[ 'original_size' ];
					$this->statistics[ 'saved' ] += $response[ 'saved_bytes' ];

				} else {
					$this->statistics[ 'failed' ]++;
				}

			} else {

				WP_CLI::line( 'Image can not be optimized any further.' );
				$this->statistics[ 'samesize' ]++;

			}

		} else {

			WP_CLI::warning( sprintf( 'Krake API call failed. (Error: %s)', $response[ 'error' ] ) );
			$this->statistics[ 'failed' ]++;

		}

	}

	/**
	 * Downloads the kraked image and replaces the original image with it.
	 * Creates a backup of the original image (appends `.org` to the original file name).
	 * Returns `false` if an error occured, otherwise `true`.
	 *
	 * @param int $attachment_id ID of the associated attachment.
	 * @param string $filepath Absolute path to original image.
	 * @param string $kraked_url URL of kraked image.
	 * @param int $kraked_size Size of kraked image in bytes.
	 * @return bool
	 */
	private function _replace_image( $attachment_id, $filepath, $kraked_url, $kraked_size ) {

		// temporary file name
		$krakedpath = $filepath . '.kraked';

		// download kraked image
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $kraked_url );
		curl_setopt( $ch, CURLOPT_FILE, fopen( $krakedpath, 'w' ) );
		curl_exec( $ch );

		if ( curl_errno( $ch ) ) {
			WP_CLI::warning( sprintf( 'Kraked image download failed. (cURL error [%s]: %s)', curl_errno( $ch ), curl_error( $ch ) ) );
		}

		curl_close( $ch );

		// was the download successful?
		if ( filesize( $krakedpath ) === intval( $kraked_size ) ) {

			// was the original image renamed to `<filename.ext>.org`?
			if ( rename( $filepath, $filepath . '.org' ) ) {

				// was the kraked image renamed to filename of original image?
				if ( rename( $krakedpath, $filepath ) ) {

					// store new kraken metadata for this file
					$kraken_metadata = $this->_get_image_kraken_metadata( $attachment_id );
					$kraken_metadata[ basename( $filepath ) ] = array(
						'md4' => hash_file( 'md4', $filepath ),
						'mtime' => gmdate( 'U', filemtime( $filepath ) )
					);
					$this->kraken_metadata[ $attachment_id ] = $kraken_metadata;
					update_post_meta( $attachment_id, '_kraken_metadata', $kraken_metadata );

					return true;

				} else {

					// try to restore original image
					$restored = rename( $filepath . '.org', $filepath );

					WP_CLI::warning( sprintf(
						'Image replacement failed. %s',
						$restored ? 'Original image restored.' : 'Failed to restore original image.'
					) );

				}

			} else {

				WP_CLI::warning( 'Image backup failed. Image not kraked.' );

			}

		} else {

			WP_CLI::warning( 'Kraked image download failed. File size mismatch.' );

		}

		return false;

	}

	/**
	 * Returns the Kraken metadata for given attachment ID.
	 * Returns internally cached data after 1st call.
	 *
	 * @param int $attachment_id The attachment ID.
	 * @return array Attachment Kraken metadata.
	 */
	private function _get_image_kraken_metadata( $attachment_id ) {

		// has this metadata already been cached?
		if ( isset( $this->kraken_metadata[ $attachment_id ] ) ) {
			return $this->kraken_metadata[ $attachment_id ];
		}

		// retrieve metadata
		$kraken_metadata = get_post_meta( $attachment_id, '_kraken_metadata', true );

		if ( empty( $kraken_metadata ) ) {
			$kraken_metadata = array();
		}


####
// ToDo: THIS IS DEBUG...
####
$kraken_metadata = array();



		// cache metadata
		$this->kraken_metadata[ $attachment_id ] = $kraken_metadata;

		return $kraken_metadata;

	}

	/**
	 * Parses given comma-separated types into array and returns it.
	 * If `$map` is `true`, the returned array will contain MIME types, instead of given types.
	 * Returns `false` if none or invalid types are passed.
	 *
	 * @param string $string Comma-separated list of types.
	 * @param bool $map Return MIME types, instead of given types.
	 * @return array|false
	 */
	private function _parse_mime_types( $string, $map = false ) {

		$types = array();
		$values = preg_split( '~[\s,]+~', trim( $string ) );

		foreach ( $values as $value ) {

			if ( empty( $value ) ) {
				continue;
			}

			if ( $value === 'jpg' ) {
				$value = 'jpeg'; // Mooji-style!
			}

			// validate value and return `false` if value is invalid
			if ( isset( $this->mime_types[ $value ] ) ) {
				$types[] = $map ? $this->mime_types[ $value ] : $value;
			} else {
				return false;
			}

		}

		return empty( $types ) ? false : $types;

	}

	/**
	 * Shows final report.
	 *
	 * @return void
	 */
	private function _show_report() {

		WP_CLI::line( sprintf( '%s attachments (%s images) checked.', $this->statistics[ 'attachments' ], $this->statistics[ 'files' ] ) );
		WP_CLI::line( sprintf( '%s images with no kraken metadata.', $this->statistics[ 'unknown' ] ) );
		WP_CLI::line( sprintf( '%s images checked for changes.', $this->statistics[ 'compared' ] ) );
		WP_CLI::line( sprintf( '%s modified images found.', $this->statistics[ 'changed' ] ) );

		if ( $this->dryrun ) {
			WP_CLI::line( sprintf( '%s images will be kraked.', $this->statistics[ 'changed' ] + $this->statistics[ 'unknown' ] ) );
			return;
		}

		WP_CLI::line( sprintf( '%s images uploaded.', $this->statistics[ 'uploaded' ] ) );
		WP_CLI::line( sprintf( '%s images successfully kraked.', $this->statistics[ 'kraked' ] ) );
		WP_CLI::line( sprintf( '%s images already fully optimized.', $this->statistics[ 'samesize' ] ) );
		WP_CLI::line( sprintf( '%s images failed to kraken.', $this->statistics[ 'failed' ] ) );

		if ( $this->statistics[ 'size' ] > 0 && $this->statistics[ 'saved' ] > 0 ) {

			WP_CLI::line( sprintf(
				'%s compressed to %s. Savings: %s',
				size_format( $this->statistics[ 'size' ], 2 ),
				size_format( $this->statistics[ 'saved' ], 2 ),
				round( abs( ( $this->statistics[ 'saved' ] / $this->statistics[ 'size' ] ) * 100 ), 2 )
			) );

		}

	}

}

// register `media krake` command
WP_CLI::add_command( 'media krake', 'WP_CLI_Kraken' );
