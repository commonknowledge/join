<?php
use Carbon_Fields\Block;
use Carbon_Fields\Field;

add_action( 'carbon_fields_register_fields', function () {
    Block::make(__( 'Join Header' ) )
    ->add_fields( array(
        Field::make( 'text', 'heading', __( 'Block Heading' ) ),
        Field::make( 'text', 'numbers', __( 'Numbers' ) ),
        Field::make( 'text', 'slogan', __( 'Slogan' ) ),
        Field::make('image', 'background_image',  __( 'Background Image' ) )
    ) )
    ->set_render_callback( function ( $fields, $attributes, $inner_blocks ) {
        ?>
        <div class="jumbotron jumbotron-fluid full-bleed bg-black">
            <img class="jumbotron-background-img" src="<?php echo wp_get_attachment_image_src( $fields['background_image'], 'full' )[0]; ?>" />
            <div class="container">
                <h1 class="text-bebas-neue text-xl text-white text-no-transform"><?php echo esc_html( $fields['heading'] ); ?></h1>
                <div class="w-50 mt-5 text-white">
                    <div class="text-bebas-neue text-md text-no-transform"><?php echo esc_html( $fields['numbers'] ); ?></div>
                    <div class="text-bebas-neue text-md text-no-transform"><?php echo esc_html( $fields['slogan'] ); ?></div>
                </div>
            </div>
        </div>

        <?php
    });
    
    Block::make(__( 'Join Form' ) )
    ->add_fields( array(
        Field::make( 'text', 'ready', __( 'Introduction' ) ),
        Field::make( 'text', 'instructions', __( 'Instruction' ) ),
        Field::make( 'text', 'button_cta', __( 'Button text' ) ),
        Field::make( 'association', 'join_page', __( 'Join page location' ) )
            ->set_types( array(
                array(
                    'type' => 'post',
                    'post_type' => 'page',
                ),
            ) )
            ->set_max(1)
        ) 
    )
    ->set_render_callback( function ( $fields, $attributes, $inner_blocks ) {
        ?>
        <div class="row justify-content-center">
			<div class="col-lg-8">
				<p><?php echo esc_html( $fields['ready'] ); ?></p>
				<p><?php echo esc_html( $fields['instructions'] ); ?></p>
				<form method="GET" action="<?php echo get_permalink($fields['join_page'][0]['id']) ?>">
					<div class="row">
						<div class="col-9">
							<input type="email" id="email" name="email" class="form-control">
						</div>
						<div class="col-3">
							<button type="submit" class="btn btn-primary"><?php echo esc_html( $fields['button_cta'] ); ?></button>
						</div>
					</div>
				</form>
			</div>
		</div>
        <?php
    });
});


