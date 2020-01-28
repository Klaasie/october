module.exports = {
    prefix: 'tw-',
    theme: {
        inset: {
            '0': 0,
             auto: 'auto',
            '2': '2rem',
        },
        extend: {
            colors: {
                'main': {
                    100: '#EFF8FE',
                    200: '#D6EEFD',
                    300: '#BEE4FB',
                    400: '#8DD0F9',
                    500: '#5CBCF6',
                    600: '#53A9DD',
                    700: '#377194',
                    800: '#29556F',
                    900: '#1C384A',
                },
                'secondary': {
                    100: '#FDF2E9',
                    200: '#F9DFC8',
                    300: '#F5CBA7',
                    400: '#EEA564',
                    500: '#E67E22',
                    600: '#CF711F',
                    700: '#8A4C14',
                    800: '#68390F',
                    900: '#45260A',
                },
            },
        },

        // Transitions
        transitionProperty: { // defaults to these values
            'none': 'none',
            'all': 'all',
            'color': 'color',
            'bg': 'background-color',
            'border': 'border-color',
            'colors': ['color', 'background-color', 'border-color'],
            'opacity': 'opacity',
            'transform': 'transform',
        },
        transitionDuration: { // defaults to these values
            'default': '250ms',
            '0': '0ms',
            '100': '100ms',
            '250': '250ms',
            '500': '500ms',
            '750': '750ms',
            '1000': '1000ms',
        },
        transitionTimingFunction: { // defaults to these values
            'default': 'ease',
            'linear': 'linear',
            'ease': 'ease',
            'ease-in': 'ease-in',
            'ease-out': 'ease-out',
            'ease-in-out': 'ease-in-out',
        },
        transitionDelay: { // defaults to these values
            'default': '0ms',
            '0': '0ms',
            '100': '100ms',
            '250': '250ms',
            '500': '500ms',
            '750': '750ms',
            '1000': '1000ms',
        },
        willChange: { // defaults to these values
            'auto': 'auto',
            'scroll': 'scroll-position',
            'contents': 'contents',
            'opacity': 'opacity',
            'transform': 'transform',
        },
        // End transitions
    },
    variants: {
        // Transitions
        transitionProperty: ['responsive'],
        transitionDuration: ['responsive'],
        transitionTimingFunction: ['responsive'],
        transitionDelay: ['responsive'],
        willChange: ['responsive'],
        // End transitions
    },
    plugins: [
        require('tailwindcss-transitions')(),
    ]
}
