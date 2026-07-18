<?php
if (!defined('ABSPATH')) {
    exit;
}

get_header();
?>

<main id="primary" class="site-main">
<?php
if (have_posts()) {
    while (have_posts()) {
        the_post();
        the_title('<h2>', '</h2>');
        the_excerpt();
    }
} else {
    esc_html_e('No content found.', 'cad-edu-theme');
}
?>
</main>

<?php get_footer(); ?>
