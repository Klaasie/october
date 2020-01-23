let mix = require('laravel-mix');
const tailwindcss = require('tailwindcss');


mix.js('src/js/app.js', 'dist/js')
    .sass('src/sass/app.scss', 'dist/css')
    .options({
        processCssUrls: false,
        postCss: [tailwindcss('./tailwind.config.js')],
    });
