<?php

/**
Plugin Name: Country Form
Description: Custom phone number field with country dropdown.
Version: 1.0
Author: Bishal
Text Domain: country-form
*/


function enqueue_country_code_styles() {
    wp_enqueue_style('country-code-style', plugin_dir_url(__FILE__) . 'country-form-css.css');
}
add_action('wp_enqueue_scripts', 'enqueue_country_code_styles');


function phone_number_form_shortcode() {
    ob_start();
    ?>
    <form id="phone-number-form" method="post">
        <?php wp_nonce_field('phone_number_submit_action', 'phone_number_nonce'); ?>
        
		<div class="country-listform-container">
				<select id="country-code" name="country_code">
					<option value="">Loading...</option> <!-- Placeholder until JS loads the options -->
				</select>
				<input type="text" id="phone-number" name="phone_number" required pattern="\+?[0-9]+" title="Only numbers and '+' are allowed.">
			</div>
        
        <button type="submit" id="country-form-submit-button">Anmäla</button>
        <div id="loading-message" style="display: none;">Submitting...</div>
        <div id="response-message"></div>
    </form>

    <script>
    jQuery(document).ready(function($) {
        // Fetch country codes from JSON file
        fetch('<?php echo plugin_dir_url(__FILE__); ?>CountryCodes.json')
            .then(response => response.json())
            .then(data => {
                let select = document.getElementById("country-code");
                select.innerHTML = ""; // Clear the placeholder

                data.forEach(country => {
                    let option = document.createElement("option");
                    option.value = country.dial_code;
                    option.textContent = `${country.code} (${country.dial_code})`;
					if(country.name=="Sweden") {
						option.selected = true
					}
                    select.appendChild(option);
                });
            })
            .catch(error => console.error("Error loading country codes:", error));

        // AJAX form submission
        $('#phone-number-form').on('submit', function(e) {
            e.preventDefault();

            var formData = new FormData(this);
            formData.append('action', 'phone_number_submit'); // Ensure correct AJAX action

            $('#submit-button').prop('disabled', true);
            $('#loading-message').show();
            $('#response-message').html('');

            $.ajax({
                url: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json',
                success: function(response) {
                    $('#submit-button').prop('disabled', false);
                    $('#loading-message').hide();
                    $('#response-message').html(response.message).css('color', response.success ? 'green' : 'red');
                },
                error: function(xhr, status, error) {
                    $('#submit-button').prop('disabled', false);
                    $('#loading-message').hide();
                    $('#response-message').html('An error occurred: ' + xhr.responseText).css('color', 'red');
                }
            });
        });
    });
    </script>

    <?php
    return ob_get_clean();
}

add_shortcode('phone_number_form', 'phone_number_form_shortcode');



function handle_phone_number_submission() {
    // Check if the request is from AJAX
    if (!defined('DOING_AJAX') || !DOING_AJAX) {
        wp_send_json(['success' => false, 'message' => 'Invalid request.']);
    }

    // Verify nonce
    if (!isset($_POST['phone_number_nonce']) || !wp_verify_nonce($_POST['phone_number_nonce'], 'phone_number_submit_action')) {
        wp_send_json(['success' => false, 'message' => 'Security check failed.']);
    }

    // Validate input
    if (!isset($_POST['phone_number']) || !isset($_POST['country_code'])) {
        wp_send_json(['success' => false, 'message' => 'Invalid input.']);
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'phone_numbers';

    $country_code = sanitize_text_field($_POST['country_code']);
    $phone_number = preg_replace('/[^0-9+]/', '', $_POST['phone_number']);

    if (empty($phone_number)) {
        wp_send_json(['success' => false, 'message' => 'Invalid phone number format.']);
    }

    $result = $wpdb->insert(
        $table_name,
        [
            'country_code' => $country_code,
            'phone_number' => $phone_number,
            'date' => current_time('mysql')
        ]
    );

    if ($result) {
        wp_send_json(['success' => true, 'message' => 'Thank you for submitting your phone number!']);
    } else {
        wp_send_json(['success' => false, 'message' => 'Database error.']);
    }
}
add_action('wp_ajax_phone_number_submit', 'handle_phone_number_submission');
add_action('wp_ajax_nopriv_phone_number_submit', 'handle_phone_number_submission');



function create_phone_numbers_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'phone_numbers';
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        country_code varchar(10) NOT NULL,
        phone_number varchar(20) NOT NULL,
        date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'create_phone_numbers_table');

function phone_numbers_admin_menu() {
    add_menu_page(
        'Phone Numbers',
        'Phone Numbers',
        'manage_options',
        'phone-numbers',
        'display_phone_numbers_table',
        'dashicons-phone',
        6
    );
}
add_action('admin_menu', 'phone_numbers_admin_menu');

function display_phone_numbers_table() {
    ?>
    <div class="wrap">
        <h1>Phone Numbers</h1>
        <a href="<?php echo esc_url(admin_url('admin-post.php?action=export_phone_numbers')); ?>" class="button button-primary">
            Export to CSV
        </a>
        <br><br>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Country Code</th>
                    <th>Phone Number</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php
                global $wpdb;
                $table_name = $wpdb->prefix . 'phone_numbers';
                $results = $wpdb->get_results("SELECT * FROM $table_name ORDER BY date DESC");

                foreach ($results as $row) {
                    echo '<tr>';
                    echo '<td>' . esc_html($row->id) . '</td>';
                    echo '<td>' . esc_html($row->country_code) . '</td>';
                    echo '<td>' . esc_html($row->phone_number) . '</td>';
                    echo '<td>' . esc_html(date('Y-m-d H:i:s', strtotime($row->date))) . '</td>';
                    echo '</tr>';
                }
                ?>
            </tbody>
        </table>
    </div>
    <?php
}


function export_phone_numbers_csv() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'phone_numbers';
    $results = $wpdb->get_results("SELECT * FROM $table_name ORDER BY date DESC", ARRAY_A);

    if (!$results) {
        wp_die(__('No phone numbers found.'));
    }

    // Set headers for CSV file
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename=phone_numbers.csv');

    $output = fopen('php://output', 'w');

    // Add CSV Column Headers
    fputcsv($output, ['ID', 'Country Code', 'Phone Number', 'Date']);

    foreach ($results as $row) {
        $formatted_date = date('Y-m-d', strtotime($row['date']));
        fputcsv($output, [$row['id'], $row['country_code'], $row['phone_number'], $formatted_date]);
    }

    fclose($output);
    exit;
}
add_action('admin_post_export_phone_numbers', 'export_phone_numbers_csv');

