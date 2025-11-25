jQuery(document).ready(function($) {
    'use strict';

    // Token form submission
    $('#wfa-token-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $button = $form.find('button[type="submit"]');
        var $spinner = $form.find('.spinner');
        var $result = $('#wfa-token-result');

        var scopes = [];
        $form.find('input[name="scopes[]"]:checked').each(function() {
            scopes.push($(this).val());
        });

        $button.prop('disabled', true);
        $spinner.addClass('is-active');
        $result.html('');

        $.ajax({
            url: wfaAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'wfa_get_token',
                nonce: wfaAdmin.nonce,
                email: $('#wfa_email').val(),
                password: $('#wfa_password').val(),
                scopes: scopes
            },
            success: function(response) {
                if (response.success) {
                    $result.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $result.html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                    $button.prop('disabled', false);
                }
            },
            error: function() {
                $result.html('<div class="notice notice-error"><p>Request failed. Please try again.</p></div>');
                $button.prop('disabled', false);
            },
            complete: function() {
                $spinner.removeClass('is-active');
            }
        });
    });

    // Locations form submission
    $('#wfa-locations-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $button = $form.find('button[type="submit"]');
        var $spinner = $form.find('.spinner');
        var $result = $('#wfa-locations-result');

        var locations = [];
        $form.find('input[name="locations[]"]:checked').each(function() {
            locations.push($(this).val());
        });

        if (locations.length === 0) {
            $result.html('<div class="notice notice-error"><p>Please select at least one location.</p></div>');
            return;
        }

        $button.prop('disabled', true);
        $spinner.addClass('is-active');
        $result.html('');

        $.ajax({
            url: wfaAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'wfa_save_locations',
                nonce: wfaAdmin.nonce,
                locations: locations
            },
            success: function(response) {
                if (response.success) {
                    $result.html('<div class="notice notice-success"><p>' + response.data + '</p></div>');
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    $result.html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                    $button.prop('disabled', false);
                }
            },
            error: function() {
                $result.html('<div class="notice notice-error"><p>Request failed. Please try again.</p></div>');
                $button.prop('disabled', false);
            },
            complete: function() {
                $spinner.removeClass('is-active');
            }
        });
    });

    // Sync departments button
    $('#wfa-sync-departments, #wfa-resync-departments').on('click', function() {
        var $button = $(this);
        var $spinner = $button.next('.spinner');
        var $result = $('#wfa-sync-result');

        $button.prop('disabled', true);
        $spinner.addClass('is-active');
        $result.html('');

        $.ajax({
            url: wfaAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'wfa_sync_departments',
                nonce: wfaAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    var msg = 'Synced ' + response.data.departments_synced + ' departments and ' +
                              response.data.users_synced + ' user assignments.';
                    $result.html('<div class="notice notice-success"><p>' + msg + '</p></div>');

                    if ($button.attr('id') === 'wfa-sync-departments') {
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        $button.prop('disabled', false);
                    }
                } else {
                    $result.html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                    $button.prop('disabled', false);
                }
            },
            error: function() {
                $result.html('<div class="notice notice-error"><p>Request failed. Please try again.</p></div>');
                $button.prop('disabled', false);
            },
            complete: function() {
                $spinner.removeClass('is-active');
            }
        });
    });

    // Test connection button
    $('#wfa-test-token').on('click', function() {
        var $button = $(this);
        var $spinner = $button.next('.spinner');
        var $result = $('#wfa-test-result');

        $button.prop('disabled', true);
        $spinner.addClass('is-active');
        $result.html('');

        $.ajax({
            url: wfaAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'wfa_test_connection',
                nonce: wfaAdmin.nonce
            },
            success: function(response) {
                if (response.success) {
                    $result.html('<span style="color: green;">✓ ' + response.data + '</span>');
                } else {
                    $result.html('<span style="color: red;">✗ ' + response.data + '</span>');
                }
                $button.prop('disabled', false);
            },
            error: function() {
                $result.html('<span style="color: red;">✗ Request failed</span>');
                $button.prop('disabled', false);
            },
            complete: function() {
                $spinner.removeClass('is-active');
            }
        });
    });

    // Reset setup button
    $('#wfa-reset-setup').on('click', function() {
        if (!confirm('Are you sure you want to reset the setup? This will clear all configuration.')) {
            return;
        }

        // For now, just reload - you could add an AJAX handler to clear options
        alert('Please deactivate and reactivate the plugin to reset setup.');
    });

    // Auto-sync settings form
    $('#wfa-auto-sync-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var $button = $form.find('button[type="submit"]');
        var $spinner = $form.find('.spinner');
        var $result = $('#wfa-auto-sync-result');

        var enabled = $('#wfa-auto-sync-enabled').is(':checked') ? '1' : '0';
        var frequency = $('#wfa-auto-sync-frequency').val();

        $button.prop('disabled', true);
        $spinner.addClass('is-active');
        $result.html('');

        $.ajax({
            url: wfaAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'wfa_save_auto_sync',
                nonce: wfaAdmin.nonce,
                enabled: enabled,
                frequency: frequency
            },
            success: function(response) {
                if (response.success) {
                    $result.html('<div class="notice notice-success"><p>' + response.data + '</p></div>');
                } else {
                    $result.html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                }
                $button.prop('disabled', false);
            },
            error: function() {
                $result.html('<div class="notice notice-error"><p>Request failed. Please try again.</p></div>');
                $button.prop('disabled', false);
            },
            complete: function() {
                $spinner.removeClass('is-active');
            }
        });
    });
});
