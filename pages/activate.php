<?php
if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
?>
<div class="wrap">

	<h1>Activate <?php echo $this->getConfig('plugin_name'); ?></h1>
	<p>To get started with <?php echo $this->getConfig('plugin_name'); ?>, please activate using your license information.</p>

    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
    	<table class="form-table" role="presentation">
			<tbody>
				<?php if ($this->getConfig('license.require_email')) { ?>
				<tr>
					<th>
						<label for="license-email">
							Your email
						</label>
					</th>
					<td>
						<input type="email" name="email" id="license-email" class="regular-text code" placeholder="your@email.com">
					</td>
				</tr>
				<?php } ?>
				<tr>
					<th>
						<label for="license-key">License key</label>
					</th>
					<td>
						<input type="text" name="license-key" id="license-key" class="regular-text code" placeholder="06C1915F-905B-42A7-8BA4-350F6B2C70DA">
					</td>
				</tr>
			</tbody>
		</table>

      	<input type="hidden" name="action" value="<?php echo sprintf('activate_license_%s', $this->config['basename']); ?>">
      	<?php wp_nonce_field(sprintf('activate_%s_nonce', $this->config['basename']), sprintf('activate_%s_nonce', $this->config['basename'])); ?>

	      <p>
	      	<input type="submit" class="button button-primary" value="Activate License">
	      </p>
    </form>
</div>