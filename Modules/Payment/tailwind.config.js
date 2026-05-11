/** @type {import('tailwindcss').Config} */
const preset = require('../../vendor/filament/support/tailwind.config.preset')

module.exports = {
    presets: [preset],
    prefix: 'p-',
    darkMode: 'class',
    content: [
        "./Resources/**/*.blade.php",
        "./Resources/**/*.js",
        "./Resources/**/*.vue",
        "../../vendor/filament/**/*.blade.php",
    ],
    theme: {
        extend: {
            after: ['responsive', 'hover', 'group-hover', 'last'],
        },
    },
    plugins: [],
    darkMode: 'class',
}