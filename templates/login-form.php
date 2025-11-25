<?php
/**
 * Login form template.
 *
 * @package Workforce_Authentication
 */

if (!defined('ABSPATH')) {
    exit;
}

$redirect_to = isset($_REQUEST['redirect_to']) ? $_REQUEST['redirect_to'] : home_url();
?>

<div class="wfa-login-container">
    <h2>Log In</h2>

    <?php
    if (isset($_GET['wfa_registered']) && $_GET['wfa_registered'] === 'success') {
        echo '<div class="wfa-message wfa-success">Registration successful! You can now log in.</div>';
    }

    if (isset($_GET['wfa_registered']) && $_GET['wfa_registered'] === 'pending') {
        echo '<div class="wfa-message wfa-info">Your registration is pending approval. You will receive an email once approved.</div>';
    }
    ?>

    <form name="wfa-loginform" id="wfa-loginform" action="<?php echo esc_url(site_url('wp-login.php', 'login_post')); ?>" method="post">
        <div class="wfa-form-group">
            <label for="user_login">Username or Email</label>
            <input type="text" name="log" id="user_login" class="input" value="" size="20" autocapitalize="off" required>
        </div>

        <div class="wfa-form-group">
            <label for="user_pass">Password</label>
            <input type="password" name="pwd" id="user_pass" class="input" value="" size="20" required>
        </div>

        <div class="wfa-form-group wfa-remember-me">
            <label>
                <input name="rememberme" type="checkbox" id="rememberme" value="forever">
                Remember Me
            </label>
        </div>

        <div class="wfa-form-actions">
            <button type="submit" name="wp-submit" id="wp-submit" class="wfa-button wfa-button-primary">Log In</button>
            <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect_to); ?>">
        </div>

        <div class="wfa-form-links">
            <a href="<?php echo wp_lostpassword_url(); ?>">Forgot password?</a>
            <?php if (get_option('wfa_registration_enabled', false)): ?>
                <a href="<?php echo home_url('/register/'); ?>">Register with Workforce</a>
            <?php endif; ?>
        </div>
    </form>
</div>
