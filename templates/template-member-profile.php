<?php
/**
 * Template Name: Member Profile
 * 
 * @package LCD_People
 */

get_header();

// Check if user is logged in
if (!is_user_logged_in()) {
    // Redirect to login page
    wp_redirect(wp_login_url(get_permalink()));
    exit;
}

// Get the frontend class instance
$frontend = LCD_People_Frontend::get_instance();

// Get member profile content
$member_profile = $frontend->render_member_profile(array('redirect_login' => false));
?>

<main id="primary" class="site-main page-template">
    <?php while (have_posts()) : the_post(); ?>
        <article id="post-<?php the_ID(); ?>" <?php post_class('page'); ?>>
            <header class="entry-header<?php echo has_post_thumbnail() ? ' has-featured-image' : ''; ?>">
                <?php if (has_post_thumbnail()) : ?>
                    <div class="featured-image">
                        <?php the_post_thumbnail('full'); ?>
                    </div>
                <?php endif; ?>
                
                <div class="entry-header-content">
                    <?php if (!is_front_page()) : ?>
                        <div class="breadcrumbs">
                            <a href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Home', 'lcd-theme'); ?></a>
                            <span class="separator"> › </span>
                            <?php
                            if ($post->post_parent) {
                                $ancestors = get_post_ancestors($post->ID);
                                $ancestors = array_reverse($ancestors);
                                foreach ($ancestors as $ancestor) {
                                    $ancestor_post = get_post($ancestor);
                                    echo '<a href="' . get_permalink($ancestor) . '">' . esc_html($ancestor_post->post_title) . '</a>';
                                    echo '<span class="separator"> › </span>';
                                }
                            }
                            ?>
                            <span class="current"><?php the_title(); ?></span>
                        </div>
                    <?php endif; ?>
                    <?php the_title('<h1 class="entry-title">', '</h1>'); ?>
                </div>
            </header>

            <div class="content-wrapper<?php echo get_post_meta(get_the_ID(), 'full_width_content', true) ? ' full-width-content' : ''; ?>">
                <div class="entry-content">
                    <?php 
                    if (post_password_required()) {
                        get_template_part('template-parts/password-form');
                    } else {
                        the_content();
                        
                        // Display the member profile
                        echo $member_profile;
                    }
                    ?>
                </div>
            </div>
        </article>
    <?php endwhile; ?>
</main>

<?php
// Get the appropriate sidebar if the theme supports it
if (function_exists('get_sidebar')) {
    get_sidebar();
}

get_footer();
?> 