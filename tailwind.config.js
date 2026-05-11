import preset from './vendor/filament/support/tailwind.config.preset'

export default {
    presets: [preset],
    content: [
        './app/Filament/**/*.php',
        './resources/views/filament/**/*.blade.php',
        './vendor/filament/**/*.blade.php',
        './Modules/**/*.blade.php',
        './Modules/**/*.php',
        './plugins/**/*.blade.php',
        './plugins/**/*.php',
    ],
    theme: {
        extend: {
            screens: {
                'xs': '476px',
            },
            maxWidth: {
                'screen-xl': '1280px'
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
}
