/** @type {import('tailwindcss').Config} */
module.exports = {
    content: [
        "./Resources/**/*.blade.php",
        "./Resources/**/*.js",
        "./Resources/**/*.vue",
        "./Resources/**/*.scss",
        "./node_modules/flowbite/**/*.js"
    ],
    theme: {
        extend: {
            screens: {
                'xs': '476px',
            },
            maxWidth: {
                'screen-xl': '1280px',
                '8xl': '90rem',
                '10xl': '110rem',
                '11xl': '120rem',
                '12xl': '130rem'
            },
            colors: {
                primary: 'var(--color-primary)',
                textSecondary: 'var(--color-text-secondary)',
                bgSecondary: 'var(--color-text-secondary)',
                background: 'var(--color-background)',
                bgDark: 'var(--color-bgDark)',
                textDark: 'var(--color-textDark)',
                red9C: 'var(--color-red9C)',
                borderGray: 'var(--color-borderGray)',
                tickGreen: 'var(--color-tickGreen)',
                tickYellow: 'var(--color-tickYellow)',
                tickGray: 'var(--color-tickGray)',
            },
            boxShadow: theme => ({
                secondaryVar: '0 4px 16px var(--color-text-secondary)',
            }),
        },
    },
    plugins: [
        require('flowbite/plugin')
    ]
}