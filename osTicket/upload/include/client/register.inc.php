<?php
$info = $_POST;
if (!isset($info['timezone']))
    $info += array(
        'backend' => null,
    );
if (isset($user) && $user instanceof ClientCreateRequest) {
    $bk = $user->getBackend();
    $info = array_merge($info, array(
        'backend' => $bk->getBkId(),
        'username' => $user->getUsername(),
    ));
}
$info = Format::htmlchars(($errors && $_POST)?$_POST:$info);

?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<div id="register-page-wrapper">
    <div class="form-header-main">
        <h1><?php echo __('Account Registration'); ?></h1>
        <p><?php echo __(
        'Use the forms below to create or update the information we have on file for your account'
        ); ?>
        </p>
    </div>

    <div class="register-card">
        <form action="account.php" method="post">
  <?php csrf_token(); ?>
  <input type="hidden" name="do" value="<?php echo Format::htmlchars($_REQUEST['do']
    ?: ($info['backend'] ? 'import' :'create')); ?>" />
  
  <div class="form-section">
    <div style="margin-bottom: 1.5rem;">
    <?php
        $cf = $user_form ?: UserForm::getInstance();
        $cf->render(array('staff' => false, 'mode' => 'create'));
    ?>
    </div>
  </div>

  <div class="form-section">
    <hr>
    <h3><?php echo __('Preferences'); ?></h3>
    <div class="input-group timezone-group">
        <label><?php echo __('Time Zone');?>:</label>
        <?php
        $TZ_NAME = 'timezone';
        $TZ_TIMEZONE = $info['timezone'];
        include INCLUDE_DIR.'staff/templates/timezone.tmpl.php'; ?>
        <div class="error"><?php echo $errors['timezone']; ?></div>
    </div>
  </div>

  <div class="form-section">
    <hr>
    <h3><?php echo __('Access Credentials'); ?></h3>
<?php if ($info['backend']) { ?>
    <div class="input-group">
        <label><?php echo __('Login With'); ?>:</label>
        <input type="hidden" name="backend" value="<?php echo $info['backend']; ?>"/>
        <input type="hidden" name="username" value="<?php echo $info['username']; ?>"/>
        <div style="padding: 12px 15px; background: #f7fafc; border-radius: 6px;">
        <?php foreach (UserAuthenticationBackend::allRegistered() as $bk) {
            if ($bk->getBkId() == $info['backend']) {
                echo $bk->getName();
                break;
            }
        } ?>
        </div>
    </div>
<?php } else { ?>
    <div class="input-group">
        <label><?php echo __('Create a Password'); ?>:</label>
        <input type="password" name="passwd1" maxlength="128" value="<?php echo $info['passwd1']; ?>">
        <span class="error"><?php echo $errors['passwd1']; ?></span>
    </div>
    <div class="input-group">
        <label><?php echo __('Confirm New Password'); ?>:</label>
        <input type="password" name="passwd2" maxlength="128" value="<?php echo $info['passwd2']; ?>">
        <span class="error"><?php echo $errors['passwd2']; ?></span>
    </div>
<?php } ?>
  </div>

  <div class="button-group">
    <button type="submit" class="btn-primary"><?php echo __('Register'); ?></button>
    <button type="button" class="btn-secondary" onclick="window.location.href='index.php';"><?php echo __('Cancel'); ?></button>
  </div>
        </form>
    </div>
</div>
<?php if (!isset($info['timezone'])) { ?>
<!-- Auto detect client's timezone where possible -->
<script type="text/javascript" src="<?php echo ROOT_PATH; ?>js/jstz.min.js?8d38b06"></script>
<script type="text/javascript">
$(function() {
    var zone = jstz.determine();
    $('#timezone-dropdown').val(zone.name()).trigger('change');
});
</script>
<?php }
