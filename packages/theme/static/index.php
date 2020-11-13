<!doctype html>
<html <?php language_attributes(); ?>>

<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
	<meta http-equiv="x-ua-compatible" content="ie=edge">
	<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
	<?php wp_body_open(); ?>
	<?php do_action('get_header'); ?>
	<?php get_template_part('template-parts/page-header') ?>

	<main class="container mb-5">
		<?php
			if ( have_posts() ) :
				while ( have_posts() ) :
					the_post();
		?>
			<?php the_content(); ?>
		<?php endwhile; endif; ?>
	</main>

	<?php get_template_part('template-parts/page-footer') ?>
	<?php do_action('get_footer'); ?>
	<?php wp_footer(); ?>
</body>

</html>
