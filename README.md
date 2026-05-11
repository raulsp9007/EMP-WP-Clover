# Clover HTTP Client WordPress Plugin

A WordPress plugin to test HTTP requests with the Clover API using custom merchid and token.

## Features

- Configure your Clover API credentials (merchid and token) in the WordPress admin
- Test GET requests to any Clover API endpoint
- Test POST requests with custom JSON body
- Built-in response viewer for API responses

## Installation

1. Copy the entire plugin folder to your WordPress installation's `/wp-content/plugins/` directory
2. Activate the plugin through the WordPress admin plugins page
3. Navigate to Settings > Clover API to configure your credentials

## Configuration

1. Go to Settings > Clover API in your WordPress admin
2. Enter your Merchant ID and API Token
3. Optionally customize the API Base URL (defaults to https://api.clover.com/v3/)
4. Save the settings

## Usage

After configuring your credentials:

### Making GET Requests
1. Enter an endpoint in the GET Request field (e.g., `/merchants/{merchant_id}`)
2. Click "Send GET Request"
3. View the response in the designated area

### Making POST Requests
1. Enter an endpoint in the POST Request field (e.g., `/merchants/{merchant_id}/orders`)
2. Enter your JSON payload in the textarea
3. Click "Send POST Request"
4. View the response in the designated area

## Security Notes

- API tokens are stored securely in WordPress options table
- All inputs are sanitized before processing
- Nonce verification is used for form submissions

## Requirements

- WordPress 4.9 or higher
- PHP 5.6 or higher
- cURL support (for HTTP requests)

## Support

For support, please contact the plugin developer.