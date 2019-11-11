<?php
/**
 * @var \App\View\AppView $this
 */
?>
<?php if (isset($url)) {
	echo '<h3>URL array</h3>';
	echo '<pre>';
	echo $this->TestHelper->url($url, $this->request->getData('verbose'));
	echo '</pre>';

	echo '<h3>URL path</h3>';
	echo '<p>Note: Path elements only support <code>[Plugin].[Prefix]/[Controller]::[action]</code>. The rest is dropped.';

	echo '<pre>';
	echo $this->TestHelper->urlPath($url);
	echo '</pre>';

} ?>
