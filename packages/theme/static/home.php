<?php /* Template Name: Landing Page */ ?>

<!doctype html>
<html <?php language_attributes(); ?>>

<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
	<meta http-equiv="x-ua-compatible" content="ie=edge">
	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
	<?php wp_body_open(); ?>
	<?php do_action('get_header'); ?>
	<?php get_template_part('template-parts/page-header') ?>
	<div class="jumbotron jumbotron-fluid">
		<div class="container">
			<h1 class="display-4">Better is possible</h1>
			<div>
				<div>Join 52,035 members</div>
				<div>Standing up for what matters</div>
			</div>
		</div>
	</div>
	<div class="container">
		<div class="row justify-content-center">
			<div class="col-lg-8">
				<p>Ready to do politics differently?</p>
				<p>Enter your email to join the Green Party today.</p>
				<form method="GET" action="/join">
					<div class="row">
						<div class="col-9">
							<input type="email" class="form-control">
						</div>
						<div class="col-3">
							<button type="submit" class="btn btn-primary">Get started</button>
						</div>
					</div>
				</form>
			</div>
		</div>
		<div class="row">
			<div class="col-lg-6">
				<div>What do I get for joining?</div>
			</div>
			<div class="col-lg-6">
				<div>
					<div>
						<div>Tick</div>
						<div>Vote in our elections</div>
					</div>
					<div>
						<div>Tick</div>
						<div>Shape the future of the Green Party</div>
					</div>
					<div>
						<div>Tick</div>
						<div>Get updates on campaigns</div>
					</div>
					<div>
						<div>Tick</div>
						<div>Recieve invitations to our local party</div>
					</div>
					<div>
						<div>Tick</div>
						<div>Organise with your local party</div>
					</div>
					<div>
						<div>Tick</div>
						<div>Help us fight for Climate Justice</div>
					</div>
					<div>
						<div>Tick</div>
						<div>Vote in our elections</div>
					</div>
					<div>
						<div>Tick</div>
						<div>Shape the future of the Green Party</div>
					</div>
					<div>
						<div>Tick</div>
						<div>Get updates on campaigns</div>
					</div>
					<div>
						<div>Tick</div>
						<div>Recieve invitations to our local party</div>
					</div>
					<div>
						<div>Tick</div>
						<div>Organise with your local party</div>
					</div>
					<div>
						<div>Tick</div>
						<div>Help us fight for Climate Justice</div>
					</div>
				</div>
				<div>
					<div>Need more information?</div>
					<div>Email us at <a href="mailto:members@greenparty.org.uk">members@greenparty.org.uk</a></div>
				</div>
			</div>
		</div>
	</div>
	<?php get_template_part('template-parts/page-footer') ?>
	<?php do_action('get_footer'); ?>
	<?php wp_footer(); ?>
</body>

</html>