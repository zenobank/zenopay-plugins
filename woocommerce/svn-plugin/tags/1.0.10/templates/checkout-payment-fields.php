<?php
if (!defined('ABSPATH')) exit;
?>
<fieldset id="zcpg-form" class="wc-payment-form">
  <?php if (!empty($description)) : ?>
    <p class="form-row"><?php echo wp_kses_post(wpautop($description)); ?></p>
  <?php endif; ?>
</fieldset>