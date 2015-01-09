# WP CLI Kraken

WP-CLI command to optimize/compress WordPress image attachments using the [Kraken Image Optimizer](https://kraken.io) API.

## Synopsis

    wp media krake [<attachment-id>...] [--lossy] [--all] [--dry-run]
    [--limit=<number>] [--types=<types>] [--compare=<method>]
    [--api-key=<key>] [--api-secret=<secret>] [--api-test]

For more details type:

    wp help media krake

## Installing

1. Install [WP-CLI](http://wp-cli.org).
2. [Install WP-CLI-Kraken](https://github.com/wp-cli/wp-cli/wiki/Community-Packages) via Composer, or WP-CLI's `--require` attribute.
3. Define Kraken API credentials. See [Setup](#setup).

## Setup

To get started, define the Kraken API credentials in the [`wp-cli.yml`](http://wp-cli.org/config/):

    kraken:
    	api-key: <your-api-key>
		api-secret: <your-api-secret>

Then validate the API credentials by running:

    wp media krake --api-test

Or alternatively, pass the Kraken API credentials via the `--api-key` and `--api-secret` flags:

    wp media krake --api-test --api-key=<key> --api-secret=<secret>

## Configuration

The following configuration values can be defined in [`YAML config files`](http://wp-cli.org/config/).

    
    kraken:
    	api-key: <your-api-key>				Kraken API key.
    	api-secret: <your-api-secret>		Kraken API secret.
    	lossy: <bool>						Use lossy image compression. Default: Use lossless compression.
    	compare: <method>					Image metadata comparison method. Available methods: `none, md4, timestamp`. Default: `md4`.
    	types: <type(s)>					Image format(s) to krake. Available types: `gif, jpeg, png, svg`. Default: `gif,jpeg,png,svg`.

## Command Examples

    # Krake all images that have not been kraked.
    wp media krake

    # Krake all image sizes of attachment with id 1337.
    wp media krake 1337

    # Krake images using lossy compression.
    wp media krake --lossy

    # Krake a maximum of 42 images.
    wp media krake --limit=42

    # Krake only PNG and JPEG images.
    wp media krake --types='png, jpeg'

    # Use file modification time for metadata comparison.
    wp media krake --compare=timestamp

    # Krake all images, bypass metadata comparison.
    wp media krake --all

    # Do a dry run and show report without executing API calls.
    wp media krake --dry-run

    # Validate Kraken API credentials and show account summary.
    wp media krake --api-test
